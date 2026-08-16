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
 * The Inspect360 API returns three list envelope shapes across the entities
 * we consume — the higher-level getSuppliers/getProducts/getAssessments
 * methods normalise them all to `['items' => [...], 'total' => N]` so the
 * controller and Vue widgets speak one shape.
 *
 *   - Suppliers: `{items, total, page, page_size, pages}`
 *   - Products:  `{products, total, page, page_size}` (different key name)
 *   - Assessments: raw array (no envelope)
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
	 * Aggregate supplier counts — powers the Overview widget's tiles.
	 *
	 * @return array{total: int, active: int, archived: int, by_status: array<string, int>}|null
	 *         Null when the request fails; callers surface that as a widget-level error.
	 */
	public function getSuppliersStats(string $instanceUrl, string $accessToken, string $userId): ?array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'suppliers/stats');
		if (isset($result['error'])) {
			return null;
		}
		return [
			'total' => (int) ($result['total'] ?? 0),
			'active' => (int) ($result['active'] ?? 0),
			'archived' => (int) ($result['archived'] ?? 0),
			'by_status' => is_array($result['by_status'] ?? null) ? $result['by_status'] : [],
		];
	}

	/**
	 * Paginated supplier list. Query params (all optional):
	 *   - status: 'approved'|'draft'|'under_review'|'archived'
	 *   - limit, page
	 *
	 * The API default list scope is `active` only (excludes archived);
	 * pass an explicit status if you need archived rows.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function getSuppliers(string $instanceUrl, string $accessToken, string $userId, array $params = []): array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'suppliers', $params);
		if (isset($result['error']) || !is_array($result)) {
			return ['items' => [], 'total' => 0];
		}
		$items = is_array($result['items'] ?? null) ? $result['items'] : [];
		return ['items' => $items, 'total' => (int) ($result['total'] ?? count($items))];
	}

	/**
	 * Total product count — used for the Overview widget's "Total Services"
	 * tile. Reads the `total` field of the paginated list response with the
	 * smallest possible payload (limit=1, page=1).
	 *
	 * Returns -1 on failure so the caller can distinguish "0 real
	 * products" from "the request failed".
	 */
	public function getProductsCount(string $instanceUrl, string $accessToken, string $userId): int {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'products', ['limit' => 1, 'page' => 1]);
		if (isset($result['error']) || !is_array($result)) {
			return -1;
		}
		return (int) ($result['total'] ?? 0);
	}

	/**
	 * Recent assessments as a plain array. The Inspect360 assessments
	 * endpoint does NOT use the paginated envelope — it returns a bare
	 * array capped at the requested limit (default 20 upstream).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getAssessments(string $instanceUrl, string $accessToken, string $userId, array $params = []): array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'assessments', $params);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
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

	private function redactSecrets(string $subject, string ...$secrets): string {
		foreach ($secrets as $s) {
			if ($s !== '') {
				$subject = str_replace($s, '[REDACTED]', $subject);
			}
		}
		return $subject;
	}
}
