<?php
declare(strict_types=1);

/**
 * Nextcloud - Inspect360 integration
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

use OCA\Inspect360\AppInfo\Application;
use OCA\Inspect360\Service\Inspect360AuthService;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private Inspect360AuthService $auth,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Store per-user config values. Reserved for widget-specific preferences
	 * (refresh cadence, hidden columns, …). The connect/disconnect flow does
	 * NOT come through this endpoint — see credentialLogin / disconnect.
	 *
	 * @NoAdminRequired
	 */
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'no_session'], Http::STATUS_UNAUTHORIZED);
		}
		foreach ($values as $key => $value) {
			$stored = is_array($value) ? json_encode($value) : (string) $value;
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $stored);
		}
		return new DataResponse([]);
	}

	/**
	 * Store admin config values. Validates the instance URL to prevent
	 * common misconfigurations (missing scheme, plain-HTTP against a
	 * non-loopback host).
	 */
	public function setAdminConfig(array $values): DataResponse {
		$warnings = [];
		if (isset($values['instance_url'])) {
			$rawUrl = trim((string) $values['instance_url']);
			if ($rawUrl !== '') {
				$parsed = parse_url($rawUrl);
				if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
					return new DataResponse(['error' => 'invalid_instance_url'], Http::STATUS_BAD_REQUEST);
				}
				if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
					return new DataResponse(['error' => 'invalid_instance_url_scheme'], Http::STATUS_BAD_REQUEST);
				}
				if (strtolower($parsed['scheme']) === 'http' && !$this->isLoopbackHost($parsed['host'])) {
					$warnings[] = 'http_url_not_recommended';
				}
				$values['instance_url'] = rtrim($rawUrl, '/');
			}
		}
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, (string) $value);
		}
		return new DataResponse(['ok' => 1, 'warnings' => $warnings]);
	}

	/**
	 * Sign in to Inspect360 with the user's email + password. The password
	 * is never persisted — only the returned refresh token is stored (via
	 * ICrypto). On any policy-block outcome (MFA required, password-change
	 * required, MFA enrolment required) returns the specific status code so
	 * the UI can render a targeted message.
	 *
	 * @NoAdminRequired
	 */
	public function credentialLogin(string $email, string $password): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'no_session'], Http::STATUS_UNAUTHORIZED);
		}
		$email = trim($email);
		if ($email === '' || $password === '') {
			return new DataResponse(['error' => 'missing_credentials'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->auth->login($this->userId, $email, $password);
		return new DataResponse($result, $this->httpStatusFor($result['status']));
	}

	/**
	 * Clear the user's stored refresh token and identity fields. The user
	 * will need to sign in again to reconnect.
	 *
	 * @NoAdminRequired
	 */
	public function disconnect(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'no_session'], Http::STATUS_UNAUTHORIZED);
		}
		$this->auth->disconnect($this->userId);
		return new DataResponse(['ok' => 1]);
	}

	/**
	 * Reports whether the user is connected to Inspect360 and, if so, the
	 * email + role we're connected as. Used by the Personal settings page
	 * to render the current state without another round-trip.
	 *
	 * @NoAdminRequired
	 */
	public function connectionStatus(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['connected' => false]);
		}
		return new DataResponse($this->auth->getConnectionStatus($this->userId));
	}

	private function httpStatusFor(string $status): int {
		return match ($status) {
			Inspect360AuthService::STATUS_OK => Http::STATUS_OK,
			Inspect360AuthService::STATUS_INVALID_CREDENTIALS => Http::STATUS_UNAUTHORIZED,
			Inspect360AuthService::STATUS_MFA_REQUIRED,
			Inspect360AuthService::STATUS_MFA_ENROLLMENT_REQUIRED,
			Inspect360AuthService::STATUS_MUST_CHANGE_PASSWORD => Http::STATUS_FORBIDDEN,
			Inspect360AuthService::STATUS_ADMIN_NOT_CONFIGURED => Http::STATUS_BAD_REQUEST,
			Inspect360AuthService::STATUS_RATE_LIMITED => Http::STATUS_TOO_MANY_REQUESTS,
			default => Http::STATUS_BAD_GATEWAY,
		};
	}

	/**
	 * Loopback hosts allowed to use plain HTTP without a warning — for
	 * dev setups running against a local Inspect360 instance.
	 */
	private function isLoopbackHost(string $host): bool {
		$host = strtolower($host);
		return $host === 'localhost'
			|| $host === '127.0.0.1'
			|| $host === '::1'
			|| str_ends_with($host, '.localhost');
	}
}
