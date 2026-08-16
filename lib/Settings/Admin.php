<?php
declare(strict_types=1);

namespace OCA\Inspect360\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

use OCA\Inspect360\AppInfo\Application;

class Admin implements ISettings {

	private const DEFAULT_INSTANCE_URL = 'https://ymir.njordium.io';

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialStateService->provideInitialState('admin-config', [
			'instance_url' => $this->config->getAppValue(Application::APP_ID, 'instance_url', self::DEFAULT_INSTANCE_URL),
			'default_instance_url' => self::DEFAULT_INSTANCE_URL,
		]);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
