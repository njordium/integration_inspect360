<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\Service;

use Exception;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin HTTP wrapper for the Inspect360 REST v1 API — Bearer-authenticated
 * calls under `{instance_url}/api/v1/`. Token acquisition and refresh are
 * owned by {@see Inspect360AuthService}; this class only knows how to make
 * an HTTP request and retry it once after a forced token refresh on 401.
 *
 * Business-logic methods below (getRepoIssues, getUserRepos, …) are
 * carryover from the integration_forgejo_gitea fork base and still target
 * the Forgejo/Gitea endpoint shape. They will be replaced with
 * Inspect360-specific methods (vendors, services, assessments, …) in the
 * Phase B widget release; kept in the interim as reference material and so
 * the carryover widgets have something to call while auth is being wired.
 */
class Inspect360APIService {

	private const HTTP_TIMEOUT = 30;
	private const USER_AGENT = 'Nextcloud Inspect360 integration';

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private Inspect360AuthService $auth,
	) {
	}

	/**
	 * Bearer-authenticated call to the instance's /api/v1/ tree.
	 * On 401 forces one token refresh via {@see Inspect360AuthService} and
	 * retries the request once. A second 401 is treated as a genuine
	 * "credentials revoked" outcome and returned as an error.
	 *
	 * @return array Decoded JSON response, or ['error' => string] on failure.
	 */
	public function request(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $endpoint,
		array $params = [],
		string $method = 'GET',
	): array {
		return $this->doRequest($instanceUrl, $accessToken, $userId, $endpoint, $params, $method, false);
	}

	/**
	 * @return array Decoded JSON body, or ['error' => string].
	 */
	private function doRequest(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $endpoint,
		array $params,
		string $method,
		bool $isRetry,
	): array {
		try {
			$url = rtrim($instanceUrl, '/') . '/api/v1/' . ltrim($endpoint, '/');
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'Accept' => 'application/json',
					'User-Agent' => self::USER_AGENT,
				],
				'timeout' => self::HTTP_TIMEOUT,
				// Handle 4xx/5xx ourselves rather than let Guzzle throw —
				// otherwise the 401 → refresh path below is dead.
				'http_errors' => false,
			];

			if ($method === 'GET') {
				if (!empty($params)) {
					$url .= '?' . http_build_query($params);
				}
			} else {
				$options['json'] = $params;
				$options['headers']['Content-Type'] = 'application/json';
			}

			$client = $this->clientService->newClient();
			$response = match ($method) {
				'GET' => $client->get($url, $options),
				'POST' => $client->post($url, $options),
				'PUT' => $client->put($url, $options),
				'PATCH' => $client->patch($url, $options),
				'DELETE' => $client->delete($url, $options),
				default => throw new Exception('Unsupported method: ' . $method),
			};

			$status = $response->getStatusCode();
			$body = (string) $response->getBody();

			if ($status === 401 && $userId !== '' && !$isRetry) {
				$fresh = $this->auth->forceRefresh($userId);
				if ($fresh === '') {
					return ['error' => 'unauthorized'];
				}
				return $this->doRequest($instanceUrl, $fresh, $userId, $endpoint, $params, $method, true);
			}

			if ($status === 429) {
				return ['error' => 'rate_limited'];
			}

			if ($status >= 400) {
				$this->logger->info('Inspect360 request returned non-2xx', [
					'endpoint' => $endpoint,
					'method' => $method,
					'status' => $status,
				]);
				return ['error' => 'upstream_' . $status];
			}

			$decoded = json_decode($body, true);
			return is_array($decoded) ? $decoded : [];
		} catch (Throwable $e) {
			// Log a redacted summary — never the exception object, which
			// Nextcloud's logger otherwise records with full request context
			// (including the Authorization: Bearer header).
			$this->logger->warning('Inspect360 request failed', [
				'endpoint' => $endpoint,
				'method' => $method,
				'reason' => $this->redactSecrets($e->getMessage(), $accessToken),
			]);
			return ['error' => 'request_failed'];
		}
	}

	/**
	 * Total count for a paginated /api/v1/ endpoint, read from the
	 * X-Total-Count response header rather than counting rows in the
	 * body — so we return the real total even when it exceeds the
	 * page size. Uses limit=1 to keep the transfer tiny; only the
	 * header matters for the count.
	 *
	 * Returns -1 on error so the caller can distinguish "0 real
	 * matches" from "the request failed."
	 */
	public function countByHeader(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $endpoint,
		array $params = [],
	): int {
		return $this->doCountByHeader($instanceUrl, $accessToken, $userId, $endpoint, $params, false);
	}

	private function doCountByHeader(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $endpoint,
		array $params,
		bool $isRetry,
	): int {
		try {
			$params['limit'] = 1;
			$params['page'] = 1;
			$url = rtrim($instanceUrl, '/') . '/api/v1/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
			$response = $this->clientService->newClient()->get($url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'Accept' => 'application/json',
					'User-Agent' => self::USER_AGENT,
				],
				'timeout' => self::HTTP_TIMEOUT,
				'http_errors' => false,
			]);
			$status = $response->getStatusCode();
			if ($status === 401 && $userId !== '' && !$isRetry) {
				$fresh = $this->auth->forceRefresh($userId);
				if ($fresh === '') {
					return -1;
				}
				return $this->doCountByHeader($instanceUrl, $fresh, $userId, $endpoint, $params, true);
			}
			if ($status >= 400) {
				return -1;
			}
			return (int) $response->getHeader('X-Total-Count');
		} catch (Throwable $e) {
			$this->logger->warning('Inspect360 countByHeader failed', [
				'endpoint' => $endpoint,
				'reason' => $this->redactSecrets($e->getMessage(), $accessToken),
			]);
			return -1;
		}
	}

	/**
	 * Replace known-secret substrings with a fixed marker so the redacted
	 * string is safe to write to the log. Empty secrets are ignored.
	 */
	private function redactSecrets(string $subject, string ...$secrets): string {
		foreach ($secrets as $s) {
			if ($s !== '') {
				$subject = str_replace($s, '[REDACTED]', $subject);
			}
		}
		return $subject;
	}

	// ---------------------------------------------------------------------
	// Carryover business-logic methods (Forgejo/Gitea endpoint shape).
	// These will be replaced with Inspect360 vendor/service/assessment
	// methods in the Phase B widget release. Kept here so the carryover
	// widget PHP + Vue continue to compile and run against a rejecting
	// endpoint (404) while Phase A auth is being validated.
	// ---------------------------------------------------------------------

	public function getUser(string $instanceUrl, string $accessToken): array {
		return $this->request($instanceUrl, $accessToken, '', 'user');
	}

	public function getUserRepos(string $instanceUrl, string $accessToken, string $userId, int $maxPages = 5): array {
		$out = [];
		for ($page = 1; $page <= $maxPages; $page++) {
			$batch = $this->request($instanceUrl, $accessToken, $userId, 'user/repos', [
				'page' => $page,
				'limit' => 50,
			]);
			if (isset($batch['error']) || !is_array($batch) || empty($batch)) {
				break;
			}
			foreach ($batch as $repo) {
				if (isset($repo['full_name'])) {
					$out[] = $repo;
				}
			}
			if (count($batch) < 50) {
				break;
			}
		}
		return $out;
	}

	public function getRepoIssues(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
		array $params = [],
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/issues';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, $params);
		return isset($result['error']) ? [] : (is_array($result) ? $result : []);
	}

	public function searchAllIssues(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		array $params = [],
	): array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'repos/issues/search', $params);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}

	public function countIssueSearch(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		array $params = [],
	): int {
		return $this->countByHeader($instanceUrl, $accessToken, $userId, 'repos/issues/search', $params);
	}

	public function getHeatmap(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $username,
	): array {
		if ($username === '') {
			return [];
		}
		$result = $this->request(
			$instanceUrl,
			$accessToken,
			$userId,
			'users/' . rawurlencode($username) . '/heatmap',
		);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}

	public function getNotifications(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		array $params = [],
	): array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'notifications', $params);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}

	public function markNotificationRead(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $threadId,
	): bool {
		$endpoint = 'notifications/threads/' . rawurlencode($threadId);
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, [], 'PATCH');
		return !isset($result['error']);
	}

	public function getRepoCommits(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
		array $params = [],
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, $params);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}

	public function getRepoMilestones(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
		array $params = [],
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/milestones';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, $params);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}

	public function getRepoDetails(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}

	public function getLatestRelease(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/latest';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint);
		return isset($result['error']) || !is_array($result) ? [] : $result;
	}
}
