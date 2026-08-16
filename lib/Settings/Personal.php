<?php
declare(strict_types=1);

namespace OCA\Inspect360\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

use OCA\Inspect360\AppInfo\Application;
use OCA\Inspect360\Service\Inspect360AuthService;

class Personal implements ISettings {

	public function __construct(
		private IInitialState $initialStateService,
		private Inspect360AuthService $auth,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialStateService->provideInitialState('user-config', $this->auth->getConnectionStatus($this->userId ?? ''));
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
