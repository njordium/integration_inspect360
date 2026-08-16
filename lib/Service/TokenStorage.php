<?php
declare(strict_types=1);

namespace OCA\Inspect360\Service;

use Exception;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCA\Inspect360\AppInfo\Application;

/**
 * Per-user encrypted storage for the Inspect360 refresh token.
 *
 * Access tokens are short-lived (15 min) and never persisted — they are
 * minted on demand from the refresh token and cached in-memory for the
 * lifetime of a single HTTP request by {@see Inspect360AuthService}.
 */
class TokenStorage {

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
	) {
	}

	public function getRefreshToken(string $userId): string {
		if ($userId === '') {
			return '';
		}
		$stored = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (Exception) {
			return '';
		}
	}

	public function setRefreshToken(string $userId, string $token): void {
		if ($userId === '') {
			return;
		}
		if ($token === '') {
			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', '');
			return;
		}
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			'refresh_token',
			$this->crypto->encrypt($token),
		);
	}

	public function clear(string $userId): void {
		if ($userId === '') {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', '');
	}
}
