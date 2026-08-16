<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

use OCA\Inspect360\AppInfo\Application;

/**
 * Owns the Inspect360 authentication lifecycle:
 *
 *   1. login()           — POST /api/v1/auth/login with {email, password},
 *                          persist the returned refresh token, cache the
 *                          access token in memory, extract identity from JWT.
 *   2. getAccessToken()  — returns the cached access token; on cache miss,
 *                          refreshes from the stored refresh token.
 *   3. refresh()         — POST /api/v1/auth/refresh, update cache + storage.
 *   4. disconnect()      — clear refresh token + identity config.
 *   5. getConnectionStatus() — for the personal settings UI.
 *
 * When Inspect360 exposes real OAuth 2.0 authorization-code flow the only
 * method that changes is login() — the storage / refresh / accessor paths
 * stay identical.
 */
class Inspect360AuthService {

	public const STATUS_OK = 'ok';
	public const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
	public const STATUS_MFA_REQUIRED = 'mfa_required';
	public const STATUS_MFA_ENROLLMENT_REQUIRED = 'mfa_enrollment_required';
	public const STATUS_MUST_CHANGE_PASSWORD = 'must_change_password';
	public const STATUS_ADMIN_NOT_CONFIGURED = 'admin_not_configured';
	public const STATUS_UPSTREAM_ERROR = 'upstream_error';
	public const STATUS_RATE_LIMITED = 'rate_limited';

	private const CONFIG_INSTANCE_URL = 'instance_url';
	private const USER_KEY_EMAIL = 'user_name';
	private const USER_KEY_SUBJECT = 'user_id';
	private const USER_KEY_ROLE = 'user_role';

	private const HTTP_TIMEOUT = 15;
	private const USER_AGENT = 'Nextcloud Inspect360 integration';

	/**
	 * Per-request in-memory cache of decrypted access tokens keyed by userId.
	 * Nextcloud DI hands out the same service instance for the lifetime of a
	 * single HTTP request, so this cache is safe and avoids re-hitting
	 * /auth/refresh for every one of a dashboard's ten-plus API calls.
	 *
	 * @var array<string, string>
	 */
	private array $accessTokenCache = [];

	public function __construct(
		private IConfig $config,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private TokenStorage $tokens,
	) {
	}

	/**
	 * The admin-configured Inspect360 instance base URL (no trailing slash),
	 * defaulting to the demo instance so a fresh install works out of the box
	 * before the admin touches anything.
	 */
	public function getInstanceUrl(): string {
		$stored = trim($this->config->getAppValue(Application::APP_ID, self::CONFIG_INSTANCE_URL, ''));
		if ($stored === '') {
			return 'https://ymir.njordium.io';
		}
		return rtrim($stored, '/');
	}

	/**
	 * Attempt an email + password sign-in. On success, persists the refresh
	 * token and identity fields; on any policy-block (MFA / password-change /
	 * enrolment) returns the specific status code so the UI can surface a
	 * targeted message rather than a generic "login failed".
	 *
	 * @return array{status: string, message?: string, email?: string, role?: string}
	 */
	public function login(string $userId, string $email, string $password): array {
		if ($userId === '') {
			return ['status' => self::STATUS_UPSTREAM_ERROR, 'message' => 'no session user'];
		}
		$instanceUrl = $this->getInstanceUrl();
		if ($instanceUrl === '') {
			return ['status' => self::STATUS_ADMIN_NOT_CONFIGURED];
		}

		$response = $this->post($instanceUrl . '/api/v1/auth/login', [
			'email' => $email,
			'password' => $password,
		]);

		if ($response === null) {
			return ['status' => self::STATUS_UPSTREAM_ERROR];
		}

		[$status, $body] = $response;

		if ($status === 429) {
			return ['status' => self::STATUS_RATE_LIMITED];
		}
		if ($status === 401 || $status === 403) {
			return ['status' => self::STATUS_INVALID_CREDENTIALS];
		}
		if ($status >= 400) {
			$this->logger->info('Inspect360 login returned non-2xx', ['status' => $status]);
			return ['status' => self::STATUS_UPSTREAM_ERROR];
		}

		// Policy gates take precedence over token extraction — the server
		// may still ship a temp_token / partial payload; we treat these
		// states as "not connected yet" so the widget doesn't silently
		// proceed with an unusable token.
		if (($body['mfa_required'] ?? false) === true) {
			return ['status' => self::STATUS_MFA_REQUIRED];
		}
		if (($body['must_change_password'] ?? false) === true) {
			return ['status' => self::STATUS_MUST_CHANGE_PASSWORD];
		}
		if (($body['mfa_enrollment_required'] ?? false) === true) {
			return ['status' => self::STATUS_MFA_ENROLLMENT_REQUIRED];
		}

		$accessToken = (string) ($body['access_token'] ?? '');
		$refreshToken = (string) ($body['refresh_token'] ?? '');
		if ($accessToken === '' || $refreshToken === '') {
			$this->logger->warning('Inspect360 login OK but missing tokens', [
				'status' => $status,
				'has_access' => $accessToken !== '',
				'has_refresh' => $refreshToken !== '',
			]);
			return ['status' => self::STATUS_UPSTREAM_ERROR];
		}

		$this->tokens->setRefreshToken($userId, $refreshToken);
		$this->accessTokenCache[$userId] = $accessToken;

		$claims = $this->decodeJwtClaims($accessToken);
		$subject = (string) ($claims['sub'] ?? '');
		$role = (string) ($claims['role'] ?? '');

		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_EMAIL, $email);
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_SUBJECT, $subject);
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_ROLE, $role);

		return [
			'status' => self::STATUS_OK,
			'email' => $email,
			'role' => $role,
		];
	}

	/**
	 * Returns a usable access token for the given user, minting a fresh one
	 * from the stored refresh token if the in-request cache is empty or the
	 * caller has just forced an invalidation via {@see forceRefresh()}.
	 *
	 * Returns an empty string when the user is not connected (no refresh
	 * token stored) or when a refresh attempt fails — callers must treat
	 * that as "not authenticated" and surface a re-login prompt.
	 */
	public function getAccessToken(string $userId): string {
		if ($userId === '') {
			return '';
		}
		if (isset($this->accessTokenCache[$userId]) && $this->accessTokenCache[$userId] !== '') {
			return $this->accessTokenCache[$userId];
		}
		return $this->refreshAndCache($userId);
	}

	/**
	 * Invalidate the in-request cache and mint a new access token from the
	 * refresh token. Called by {@see Inspect360APIService} on a 401 response
	 * to distinguish "cached token expired mid-request" from "credentials
	 * genuinely revoked" — a fresh mint that also 401s is the latter.
	 */
	public function forceRefresh(string $userId): string {
		if ($userId === '') {
			return '';
		}
		unset($this->accessTokenCache[$userId]);
		return $this->refreshAndCache($userId);
	}

	/**
	 * Clear all per-user auth state. The user will need to sign in again
	 * with their Inspect360 email + password to reconnect.
	 */
	public function disconnect(string $userId): void {
		if ($userId === '') {
			return;
		}
		$this->tokens->clear($userId);
		unset($this->accessTokenCache[$userId]);
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_EMAIL, '');
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_SUBJECT, '');
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_ROLE, '');
	}

	/**
	 * @return array{connected: bool, email: string, role: string, instance_url: string}
	 */
	public function getConnectionStatus(string $userId): array {
		return [
			'connected' => $userId !== '' && $this->tokens->getRefreshToken($userId) !== '',
			'email' => $userId !== '' ? $this->config->getUserValue($userId, Application::APP_ID, self::USER_KEY_EMAIL) : '',
			'role' => $userId !== '' ? $this->config->getUserValue($userId, Application::APP_ID, self::USER_KEY_ROLE) : '',
			'instance_url' => $this->getInstanceUrl(),
		];
	}

	/**
	 * Mint a new access token from the stored refresh token, update the
	 * cache. Returns the new token or '' on failure.
	 *
	 * NOTE: refresh endpoint URL + request body shape are educated guesses
	 * based on OAuth 2 conventions. First real integration run against
	 * ymir will confirm — adjust here if the endpoint is `/auth/token/refresh`
	 * or the body key is `refresh` instead of `refresh_token`.
	 */
	private function refreshAndCache(string $userId): string {
		$refreshToken = $this->tokens->getRefreshToken($userId);
		if ($refreshToken === '') {
			return '';
		}
		$instanceUrl = $this->getInstanceUrl();
		if ($instanceUrl === '') {
			return '';
		}

		$response = $this->post($instanceUrl . '/api/v1/auth/refresh', [
			'refresh_token' => $refreshToken,
		]);
		if ($response === null) {
			return '';
		}
		[$status, $body] = $response;

		if ($status >= 400) {
			// Refresh token no longer valid — a 401 here means the user must
			// re-enter their credentials. We deliberately do NOT clear the
			// stored refresh token: the UI's "reconnect" flow will overwrite
			// it, and a transient upstream 500 shouldn't nuke the state.
			$this->logger->info('Inspect360 refresh failed', ['status' => $status]);
			return '';
		}

		$access = (string) ($body['access_token'] ?? '');
		if ($access === '') {
			return '';
		}
		$this->accessTokenCache[$userId] = $access;

		// Servers may rotate the refresh token on every use; persist the
		// new one if present.
		$rotated = (string) ($body['refresh_token'] ?? '');
		if ($rotated !== '' && $rotated !== $refreshToken) {
			$this->tokens->setRefreshToken($userId, $rotated);
		}

		return $access;
	}

	/**
	 * POST JSON body, return [status, decoded_body_array] or null on
	 * transport failure. Never logs the request body (contains credentials
	 * on the login path and the refresh token on the refresh path).
	 *
	 * @return array{0: int, 1: array<string, mixed>}|null
	 */
	private function post(string $url, array $body): ?array {
		try {
			$response = $this->clientService->newClient()->post($url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
					'User-Agent' => self::USER_AGENT,
				],
				'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
				'timeout' => self::HTTP_TIMEOUT,
				'http_errors' => false,
			]);
			$decoded = json_decode((string) $response->getBody(), true);
			return [
				$response->getStatusCode(),
				is_array($decoded) ? $decoded : [],
			];
		} catch (Throwable $e) {
			$this->logger->warning('Inspect360 auth POST failed', [
				'url' => $this->redactUrl($url),
				'reason' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Decode a JWT's middle segment (claims) without verifying the
	 * signature. The token was just received over TLS from the trusted
	 * upstream — we don't have the HS256 shared secret, and re-verifying
	 * would gain nothing that transport security hasn't already given us.
	 * We only read `sub` and `role` for display; anything security-relevant
	 * must be re-checked by the upstream server on each API call.
	 *
	 * @return array<string, mixed>
	 */
	private function decodeJwtClaims(string $jwt): array {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return [];
		}
		$payload = strtr($parts[1], '-_', '+/');
		$payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
		$decoded = base64_decode($payload, true);
		if ($decoded === false) {
			return [];
		}
		$claims = json_decode($decoded, true);
		return is_array($claims) ? $claims : [];
	}

	private function redactUrl(string $url): string {
		return preg_replace('/(\?|&)([^=]+)=([^&]*)/', '$1$2=[REDACTED]', $url) ?? $url;
	}
}
