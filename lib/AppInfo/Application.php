<?php
/**
 * Nextcloud - Inspect360 integration
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCA\Inspect360\Dashboard\ClosedIssuesWidget;
use OCA\Inspect360\Dashboard\ClosedPRsWidget;
use OCA\Inspect360\Dashboard\HeatmapWidget;
use OCA\Inspect360\Dashboard\MilestonesWidget;
use OCA\Inspect360\Dashboard\NotificationsWidget;
use OCA\Inspect360\Dashboard\OpenIssuesWidget;
use OCA\Inspect360\Dashboard\OpenPRsWidget;
use OCA\Inspect360\Dashboard\PendingReviewsWidget;
use OCA\Inspect360\Dashboard\RecentCommitsWidget;
use OCA\Inspect360\Dashboard\RepoStatsWidget;
use OCA\Inspect360\Dashboard\StatsWidget;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_inspect360';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(StatsWidget::class);
		$context->registerDashboardWidget(OpenIssuesWidget::class);
		$context->registerDashboardWidget(NotificationsWidget::class);
		$context->registerDashboardWidget(ClosedIssuesWidget::class);
		$context->registerDashboardWidget(PendingReviewsWidget::class);
		$context->registerDashboardWidget(OpenPRsWidget::class);
		$context->registerDashboardWidget(ClosedPRsWidget::class);
		$context->registerDashboardWidget(HeatmapWidget::class);
		$context->registerDashboardWidget(RecentCommitsWidget::class);
		$context->registerDashboardWidget(MilestonesWidget::class);
		$context->registerDashboardWidget(RepoStatsWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
