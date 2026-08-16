<?php
/**
 * Nextcloud - Inspect360 integration
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

use OCA\Inspect360\Dashboard\AddedVendorsWidget;
use OCA\Inspect360\Dashboard\ApprovedVendorsWidget;
use OCA\Inspect360\Dashboard\AssessedWidget;
use OCA\Inspect360\Dashboard\InProgressWidget;
use OCA\Inspect360\Dashboard\OverviewWidget;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_inspect360';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(OverviewWidget::class);
		$context->registerDashboardWidget(ApprovedVendorsWidget::class);
		$context->registerDashboardWidget(InProgressWidget::class);
		$context->registerDashboardWidget(AddedVendorsWidget::class);
		$context->registerDashboardWidget(AssessedWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
