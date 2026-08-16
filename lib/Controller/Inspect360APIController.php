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

	private const DEFAULT_REFRESH_SECONDS = 300;
	private const ALLOWED_REFRESH_SECONDS = [0, 30, 60, 300, 900, 1800, 3600];
	// Per-widget "Records to show" setting (v0.3.3). Default 10 gives a
	// short, scannable list that still leaves room to grow — the list
	// container is scrollable so users can pick up to 100 without the
	// widget breaking out of the dashboard column.
	private const DEFAULT_MAX_ITEMS = 10;
	private const ALLOWED_MAX_ITEMS = [5, 10, 20, 50, 100];
	// Widget-key whitelist for the preferences endpoint (finding H-L5) —
	// prevents authenticated users from populating oc_preferences with
	// arbitrary `<foo>_refresh_seconds` or `<foo>_max_items` rows.
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
	 * capped at the per-user "records to show" setting.
	 *
	 * @NoAdminRequired
	 */
	public function getApprovedVendors(): DataResponse {
		$widgetKey = 'inspect360_approved_vendors';
		$maxItems = $this->readMaxItems($widgetKey);
		return $this->respondVendorList($widgetKey, [
			'status' => 'approved',
			'limit' => $maxItems,
			'page' => 1,
		]);
	}

	/**
	 * Recently added vendors — plain list ordered by the API's default
	 * (which appears to be recency), capped at the per-user "records to
	 * show" setting. If the upstream default sort turns out to be
	 * alphabetical, we'll add an explicit sort=created_at&order=desc pair.
	 *
	 * @NoAdminRequired
	 */
	public function getAddedVendors(): DataResponse {
		$widgetKey = 'inspect360_added_vendors';
		$maxItems = $this->readMaxItems($widgetKey);
		return $this->respondVendorList($widgetKey, [
			'limit' => $maxItems,
			'page' => 1,
			'sort' => 'created_at',
			'order' => 'desc',
		]);
	}

	/**
	 * Recent assessments across the user's accessible suppliers, capped
	 * at the per-user "records to show" setting. Sorted client-side by
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
		$widgetKey = 'inspect360_assessed';
		$maxItems = $this->readMaxItems($widgetKey);
		$raw = $this->api->getAssessments($instanceUrl, $accessToken, $this->userId, [
			'limit' => $maxItems,
		]);
		usort($raw, static function ($a, $b) {
			return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
		});
		$items = array_map([$this, 'projectAssessment'], $raw);
		return new DataResponse([
			'items' => $items,
			'config' => [
				'refresh_interval_seconds' => $this->readRefreshInterval($widgetKey),
				'max_items' => $maxItems,
			],
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
	 * Store per-widget preferences (refresh cadence + records-to-show).
	 * Both fields are optional in the request; when omitted, the stored
	 * value stays as-is. Whitelist-validated against ALLOWED_REFRESH_SECONDS
	 * and ALLOWED_MAX_ITEMS respectively.
	 *
	 * Replaces the v0.3.2 refresh-interval-only endpoint — one save call
	 * from the widget-settings modal persists both fields in one hit.
	 *
	 * @NoAdminRequired
	 */
	public function setWidgetPreferences(
		string $widgetKey,
		?int $refresh_seconds = null,
		?int $max_items = null,
	): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'no_session'], Http::STATUS_UNAUTHORIZED);
		}
		if (!in_array($widgetKey, self::KNOWN_WIDGET_KEYS, true)) {
			return new DataResponse(['error' => 'invalid_widget_key'], Http::STATUS_BAD_REQUEST);
		}
		if ($refresh_seconds !== null) {
			if (!in_array($refresh_seconds, self::ALLOWED_REFRESH_SECONDS, true)) {
				return new DataResponse(['error' => 'invalid_interval'], Http::STATUS_BAD_REQUEST);
			}
			$this->config->setUserValue(
				$this->userId,
				Application::APP_ID,
				$widgetKey . '_refresh_seconds',
				(string) $refresh_seconds,
			);
		}
		if ($max_items !== null) {
			if (!in_array($max_items, self::ALLOWED_MAX_ITEMS, true)) {
				return new DataResponse(['error' => 'invalid_max_items'], Http::STATUS_BAD_REQUEST);
			}
			$this->config->setUserValue(
				$this->userId,
				Application::APP_ID,
				$widgetKey . '_max_items',
				(string) $max_items,
			);
		}
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
			'config' => [
				'refresh_interval_seconds' => $this->readRefreshInterval($widgetKey),
				'max_items' => $this->readMaxItems($widgetKey),
			],
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

	/**
	 * Per-widget "records to show" setting. Read from user config, coerced
	 * against ALLOWED_MAX_ITEMS whitelist. Falls back to DEFAULT_MAX_ITEMS
	 * if unset or out of range.
	 */
	private function readMaxItems(string $widgetKey): int {
		$raw = $this->config->getUserValue(
			$this->userId ?? '',
			Application::APP_ID,
			$widgetKey . '_max_items',
			(string) self::DEFAULT_MAX_ITEMS,
		);
		$items = (int) $raw;
		return in_array($items, self::ALLOWED_MAX_ITEMS, true)
			? $items
			: self::DEFAULT_MAX_ITEMS;
	}
}
