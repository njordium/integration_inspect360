<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

use OCA\Inspect360\AppInfo\Application;
use OCA\Inspect360\Service\Inspect360APIService;
use OCA\Inspect360\Service\Inspect360AuthService;

/**
 * HTTP surface consumed by the dashboard widgets. Each widget calls one
 * endpoint here; this controller talks to {@see Inspect360APIService} and
 * normalises the response shape (envelope-normalised, refresh cadence
 * inlined) so the Vue side speaks one shape per widget.
 */
class Inspect360APIController extends Controller {

	private const MAX_ITEMS_PER_WIDGET = 30;
	private const DEFAULT_REFRESH_SECONDS = 300;
	private const ALLOWED_REFRESH_SECONDS = [0, 30, 60, 300, 900, 1800, 3600];
	// Widget-key whitelist for setRefreshInterval (finding H-L5) — prevents
	// authenticated users from populating oc_preferences with arbitrary
	// `<foo>_refresh_seconds` rows.
	private const KNOWN_WIDGET_KEYS = [
		'inspect360_overview',
		'inspect360_approved_vendors',
		'inspect360_added_vendors',
		'inspect360_assessed',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private Inspect360APIService $api,
		private Inspect360AuthService $auth,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The four Overview widget tiles in one round-trip. Uses
	 * `/api/v1/suppliers/stats` for the three vendor tiles and
	 * `/api/v1/products?limit=1` for the service count.
	 *
	 * @NoAdminRequired
	 */
	public function getOverview(): DataResponse {
		[$instanceUrl, $accessToken, $err] = $this->credentials();
		if ($err !== null) {
			return $err;
		}
		$stats = $this->api->getSuppliersStats($instanceUrl, $accessToken, $this->userId);
		$productsTotal = $this->api->getProductsCount($instanceUrl, $accessToken, $this->userId);
		if ($stats === null && $productsTotal < 0) {
			return new DataResponse(['error' => 'upstream_unavailable'], Http::STATUS_BAD_GATEWAY);
		}
		$byStatus = $stats['by_status'] ?? [];
		return new DataResponse([
			'tiles' => [
				'approved_vendors' => (int) ($byStatus['approved'] ?? 0),
				'total_vendors' => (int) ($stats['total'] ?? 0),
				'pending_review' => (int) ($byStatus['under_review'] ?? 0),
				'total_services' => $productsTotal >= 0 ? $productsTotal : 0,
			],
			'config' => [
				'refresh_interval_seconds' => $this->readRefreshInterval('inspect360_overview'),
			],
			'instance_url' => $instanceUrl,
			'errors' => [
				'stats' => $stats === null ? 'unavailable' : null,
				'products' => $productsTotal < 0 ? 'unavailable' : null,
			],
		]);
	}

	/**
	 * Approved vendors — list of suppliers with `status=approved`,
	 * capped at MAX_ITEMS_PER_WIDGET.
	 *
	 * @NoAdminRequired
	 */
	public function getApprovedVendors(): DataResponse {
		return $this->respondVendorList('inspect360_approved_vendors', [
			'status' => 'approved',
			'limit' => self::MAX_ITEMS_PER_WIDGET,
			'page' => 1,
		]);
	}

	/**
	 * Recently added vendors — plain list ordered by the API's default
	 * (which appears to be recency), capped at MAX_ITEMS_PER_WIDGET.
	 * If the upstream default sort turns out to be alphabetical, we'll
	 * add an explicit sort=created_at&order=desc pair here.
	 *
	 * @NoAdminRequired
	 */
	public function getAddedVendors(): DataResponse {
		return $this->respondVendorList('inspect360_added_vendors', [
			'limit' => self::MAX_ITEMS_PER_WIDGET,
			'page' => 1,
			'sort' => 'created_at',
			'order' => 'desc',
		]);
	}

	/**
	 * Recent assessments across the user's accessible suppliers,
	 * capped at MAX_ITEMS_PER_WIDGET. Sorted client-side by
	 * updated_at desc so the freshest changes bubble to the top even
	 * when the upstream returns in a different order.
	 *
	 * @NoAdminRequired
	 */
	public function getAssessed(): DataResponse {
		[$instanceUrl, $accessToken, $err] = $this->credentials();
		if ($err !== null) {
			return $err;
		}
		$raw = $this->api->getAssessments($instanceUrl, $accessToken, $this->userId, [
			'limit' => self::MAX_ITEMS_PER_WIDGET,
		]);
		usort($raw, static function ($a, $b) {
			return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
		});
		$items = array_map([$this, 'projectAssessment'], $raw);
		return new DataResponse([
			'items' => $items,
			'config' => ['refresh_interval_seconds' => $this->readRefreshInterval('inspect360_assessed')],
			'instance_url' => $this->auth->getInstanceUrl(),
		]);
	}

	/**
	 * Instance URL + connected user info for widget deep-link building.
	 *
	 * @NoAdminRequired
	 */
	public function getInstanceInfo(): DataResponse {
		return new DataResponse($this->auth->getConnectionStatus($this->userId ?? ''));
	}

	/**
	 * Store a per-widget refresh cadence. Whitelist-validated against
	 * ALLOWED_REFRESH_SECONDS; anything else is silently dropped.
	 *
	 * @NoAdminRequired
	 */
	public function setRefreshInterval(string $widgetKey, int $seconds): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'no_session'], Http::STATUS_UNAUTHORIZED);
		}
		if (!in_array($widgetKey, self::KNOWN_WIDGET_KEYS, true)) {
			return new DataResponse(['error' => 'invalid_widget_key'], Http::STATUS_BAD_REQUEST);
		}
		if (!in_array($seconds, self::ALLOWED_REFRESH_SECONDS, true)) {
			return new DataResponse(['error' => 'invalid_interval'], Http::STATUS_BAD_REQUEST);
		}
		$this->config->setUserValue(
			$this->userId,
			Application::APP_ID,
			$widgetKey . '_refresh_seconds',
			(string) $seconds,
		);
		return new DataResponse(['ok' => 1]);
	}

	private function respondVendorList(string $widgetKey, array $apiParams): DataResponse {
		[$instanceUrl, $accessToken, $err] = $this->credentials();
		if ($err !== null) {
			return $err;
		}
		$page = $this->api->getSuppliers($instanceUrl, $accessToken, $this->userId, $apiParams);
		$items = array_map([$this, 'projectVendor'], $page['items']);
		return new DataResponse([
			'items' => $items,
			'total' => $page['total'],
			'config' => ['refresh_interval_seconds' => $this->readRefreshInterval($widgetKey)],
			'instance_url' => $instanceUrl,
		]);
	}

	/**
	 * Project a raw supplier row down to the fields the vendor list
	 * widgets actually render — keeps the widget payload small and the
	 * frontend schema stable when Inspect360 adds fields to the underlying
	 * model.
	 *
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	private function projectVendor(array $s): array {
		return [
			'id' => (string) ($s['id'] ?? ''),
			'org_name' => (string) ($s['org_name'] ?? ''),
			'org_number' => (string) ($s['org_number'] ?? ''),
			'country' => (string) ($s['country'] ?? ''),
			'city' => (string) ($s['city'] ?? ''),
			'status' => (string) ($s['status'] ?? ''),
			'created_at' => (string) ($s['created_at'] ?? ''),
			'approved_at' => (string) ($s['approved_at'] ?? ''),
			'critical_supplier_flag' => (bool) ($s['critical_supplier_flag'] ?? false),
			'ict_provider_flag' => (bool) ($s['ict_provider_flag'] ?? false),
			'data_processor_flag' => (bool) ($s['data_processor_flag'] ?? false),
			'aml_regulated' => (bool) ($s['aml_regulated'] ?? false),
			'categories' => $s['categories'] ?? [],
		];
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>
	 */
	private function projectAssessment(array $a): array {
		return [
			'id' => (string) ($a['id'] ?? ''),
			'supplier_id' => (string) ($a['supplier_id'] ?? ''),
			'supplier_name' => (string) ($a['supplier_name'] ?? ''),
			'status' => (string) ($a['status'] ?? ''),
			'current_screen' => (string) ($a['current_screen'] ?? ''),
			'basic_tprm_risk_level' => $a['basic_tprm_risk_level'] ?? null,
			'combined_risk_level' => $a['combined_risk_level'] ?? null,
			'final_risk_level' => $a['final_risk_level'] ?? null,
			'decision' => $a['decision'] ?? null,
			'completed_at' => $a['completed_at'] ?? null,
			'created_at' => (string) ($a['created_at'] ?? ''),
			'updated_at' => (string) ($a['updated_at'] ?? ''),
		];
	}

	/**
	 * Resolve instance URL + a usable access token; returns either
	 * [instanceUrl, accessToken, null] on success, or [null, null, errorResponse]
	 * if the user isn't connected or the admin hasn't configured the instance.
	 *
	 * @return array{0: string, 1: string, 2: ?DataResponse}|array{0: null, 1: null, 2: DataResponse}
	 */
	private function credentials(): array {
		if ($this->userId === null) {
			return [null, null, new DataResponse(['error' => 'no_session'], Http::STATUS_UNAUTHORIZED)];
		}
		$instanceUrl = $this->auth->getInstanceUrl();
		if ($instanceUrl === '') {
			return [null, null, new DataResponse(['error' => 'admin_not_configured'], Http::STATUS_BAD_REQUEST)];
		}
		$accessToken = $this->auth->getAccessToken($this->userId);
		if ($accessToken === '') {
			return [null, null, new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED)];
		}
		return [$instanceUrl, $accessToken, null];
	}

	/**
	 * Per-widget refresh cadence in seconds. Read from user config, coerced
	 * against ALLOWED_REFRESH_SECONDS whitelist. Falls back to
	 * DEFAULT_REFRESH_SECONDS if unset or out of range.
	 */
	private function readRefreshInterval(string $widgetKey): int {
		$raw = $this->config->getUserValue(
			$this->userId ?? '',
			Application::APP_ID,
			$widgetKey . '_refresh_seconds',
			(string) self::DEFAULT_REFRESH_SECONDS,
		);
		$seconds = (int) $raw;
		return in_array($seconds, self::ALLOWED_REFRESH_SECONDS, true)
			? $seconds
			: self::DEFAULT_REFRESH_SECONDS;
	}
}
