<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

use OCA\Inspect360\AppInfo\Application;

/**
 * Owns the Inspect360 authentication lifecycle:
 *
 *   1. login()           — obtain the initial token pair from Inspect360,
 *                          persist the refresh token, cache the access
 *                          token, extract identity from JWT claims.
 *   2. getAccessToken()  — returns the cached access token; on cache miss,
 *                          refreshes from the stored refresh token.
 *   3. refresh()         — mints a new access token from the stored
 *                          refresh token; updates cache and storage.
 *   4. disconnect()      — clears refresh token + identity config;
 *                          best-effort upstream token revoke.
 *   5. getConnectionStatus() — for the personal settings UI.
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

	// Access-token cache TTL buffer — subtracted from the upstream `expires_in`
	// so a cached token that's about to expire mid-request is minted fresh
	// rather than causing a 401→refresh round-trip inside the widget call.
	private const CACHE_TTL_BUFFER_SECONDS = 30;
	private const CACHE_TTL_MIN_SECONDS = 60;
	private const CACHE_PREFIX = 'inspect360:at:';

	// After a refresh attempt fails, wait briefly then re-read the distributed
	// cache — a parallel worker may have won the refresh race and populated
	// the cache with a valid token. Prevents finding H-M2's thundering-herd
	// disconnect when four widgets fire simultaneously against a rotated
	// refresh token.
	private const REFRESH_RACE_RECHECK_DELAY_MICROSECONDS = 200_000;

	/**
	 * Per-request in-memory cache of decrypted access tokens keyed by userId.
	 * Nextcloud DI hands out the same service instance for the lifetime of a
	 * single HTTP request, so this cache is safe and avoids re-hitting
	 * /auth/refresh for every one of a dashboard's ten-plus API calls.
	 *
	 * @var array<string, string>
	 */
	private array $accessTokenCache = [];

	/**
	 * Distributed access-token cache — shared across all PHP-FPM workers
	 * (Redis / APCu / memcached, whatever the Nextcloud instance has
	 * configured) so the four dashboard widgets firing in parallel don't
	 * each trigger their own /auth/refresh call. See finding H-M2.
	 */
	private ICache $cache;

	public function __construct(
		private IConfig $config,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private TokenStorage $tokens,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/**
	 * The admin-configured Inspect360 instance base URL (no trailing slash).
	 * Returns an empty string when unset — the admin MUST configure this
	 * explicitly under Administration → Connected accounts before any user
	 * can sign in. Historical fallback to `https://ymir.njordium.io` was
	 * removed in v0.3.0 (finding H-L2) to prevent an unaware admin from
	 * silently routing user credentials to the Njordium demo tenant.
	 */
	public function getInstanceUrl(): string {
		$stored = trim($this->config->getAppValue(Application::APP_ID, self::CONFIG_INSTANCE_URL, ''));
		return $stored === '' ? '' : rtrim($stored, '/');
	}

	/**
	 * Sign in against the Inspect360 authentication endpoint. On success,
	 * persists the refresh token and identity fields; on any policy-block
	 * returns the specific status code so the UI can surface a targeted
	 * message rather than a generic "sign-in failed".
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
		$this->cacheAccessToken($userId, $accessToken, (int) ($body['expires_in'] ?? 900));

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
	 * Returns a usable access token for the given user. Tries the caches
	 * in order — per-request in-memory, then the distributed cache — and
	 * falls back to a /auth/refresh call only if both are empty.
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
		$distributed = $this->readDistributedCache($userId);
		if ($distributed !== '') {
			$this->accessTokenCache[$userId] = $distributed;
			return $distributed;
		}
		return $this->refreshAndCache($userId);
	}

	/**
	 * Invalidate BOTH caches and mint a new access token from the refresh
	 * token. Called by {@see Inspect360APIService} on a 401 response to
	 * distinguish "cached token expired mid-request" from "credentials
	 * genuinely revoked" — a fresh mint that also 401s is the latter.
	 */
	public function forceRefresh(string $userId): string {
		if ($userId === '') {
			return '';
		}
		unset($this->accessTokenCache[$userId]);
		$this->cache->remove(self::CACHE_PREFIX . $userId);
		return $this->refreshAndCache($userId);
	}

	/**
	 * Clear all per-user auth state. The user will need to complete an
	 * sign in again to reconnect.
	 *
	 * Best-effort attempts an upstream refresh-token revocation before
	 * clearing local state (finding H-L1) so a leaked Nextcloud config
	 * dump can't be used to mint access tokens after the user disconnects.
	 * Failure of the revocation call does not abort the local disconnect —
	 * the user's UI must always return to "not connected" and any stale
	 * upstream token will hit natural expiry on its own.
	 */
	public function disconnect(string $userId): void {
		if ($userId === '') {
			return;
		}
		$this->revokeUpstream($userId);
		$this->tokens->clear($userId);
		unset($this->accessTokenCache[$userId]);
		$this->cache->remove(self::CACHE_PREFIX . $userId);
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_EMAIL, '');
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_SUBJECT, '');
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY_ROLE, '');
	}

	/**
	 * Best-effort call to `/api/v1/auth/logout` with the stored refresh
	 * token. Endpoint URL and body shape are educated guesses — will be
	 * verified alongside `/auth/refresh` on the first real 15-min token
	 * expiry against ymir. A missing endpoint (404) is silently accepted
	 * because we always clear local state regardless.
	 */
	private function revokeUpstream(string $userId): void {
		$instanceUrl = $this->getInstanceUrl();
		if ($instanceUrl === '') {
			return;
		}
		$refreshToken = $this->tokens->getRefreshToken($userId);
		if ($refreshToken === '') {
			return;
		}
		$this->post($instanceUrl . '/api/v1/auth/logout', [
			'refresh_token' => $refreshToken,
		]);
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
	 * Mint a new access token from the stored refresh token, populate both
	 * caches. Returns the new token or '' on failure.
	 *
	 * Thundering-herd handling (finding H-M2): on failure this re-checks
	 * the distributed cache after a short delay. If a parallel worker
	 * won the refresh race and rotated the refresh token, our copy of the
	 * refresh token was silently invalidated and our /auth/refresh call
	 * returned 401 — but the parallel worker's success populated the
	 * distributed cache with a valid access token we can still use. The
	 * re-check turns what would have been a spurious disconnect ("3 out
	 * of 4 widgets show Not connected") into a normal cache hit.
	 *
	 * NOTE: refresh endpoint URL + request body shape are educated guesses
	 * based on standard token-refresh conventions. First real integration run against
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
			return $this->reReadCacheAfterRefreshRace($userId);
		}
		[$status, $body] = $response;

		if ($status >= 400) {
			// Refresh token no longer valid — a 401 here means either (a)
			// the user genuinely needs to re-enter their credentials, OR
			// (b) a parallel worker beat us to /auth/refresh and rotated
			// the token out from under us. Distinguish by re-checking the
			// distributed cache. We deliberately do NOT clear the stored
			// refresh token — the UI's reconnect flow will overwrite it
			// on real credential rotation, and a transient upstream 500
			// shouldn't nuke otherwise-working state.
			$this->logger->info('Inspect360 refresh failed', ['status' => $status]);
			return $this->reReadCacheAfterRefreshRace($userId);
		}

		$access = (string) ($body['access_token'] ?? '');
		if ($access === '') {
			return '';
		}
		$this->cacheAccessToken($userId, $access, (int) ($body['expires_in'] ?? 900));

		// Servers may rotate the refresh token on every use; persist the
		// new one if present.
		$rotated = (string) ($body['refresh_token'] ?? '');
		if ($rotated !== '' && $rotated !== $refreshToken) {
			$this->tokens->setRefreshToken($userId, $rotated);
		}

		return $access;
	}

	/**
	 * Write the access token to both caches with a TTL derived from the
	 * upstream `expires_in` (default 900s if missing) minus a small buffer
	 * so we mint a new one rather than serving a stale token to an
	 * in-flight widget call.
	 */
	private function cacheAccessToken(string $userId, string $token, int $expiresIn): void {
		$this->accessTokenCache[$userId] = $token;
		$ttl = max(self::CACHE_TTL_MIN_SECONDS, $expiresIn - self::CACHE_TTL_BUFFER_SECONDS);
		$this->cache->set(self::CACHE_PREFIX . $userId, $token, $ttl);
	}

	private function readDistributedCache(string $userId): string {
		$cached = $this->cache->get(self::CACHE_PREFIX . $userId);
		return is_string($cached) ? $cached : '';
	}

	private function reReadCacheAfterRefreshRace(string $userId): string {
		usleep(self::REFRESH_RACE_RECHECK_DELAY_MICROSECONDS);
		$distributed = $this->readDistributedCache($userId);
		if ($distributed !== '') {
			$this->accessTokenCache[$userId] = $distributed;
		}
		return $distributed;
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
