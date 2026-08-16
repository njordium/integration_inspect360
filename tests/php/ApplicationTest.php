<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Inspect360\Tests;

use OCA\Inspect360\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test — verifies the composer PSR-4 autoloader can resolve the
 * app's namespace and that the constant used everywhere as the app id
 * holds its expected value. Deliberately minimal; the app is unit-test
 * light by design (its logic is thin HTTP + Nextcloud framework
 * plumbing that's better exercised end-to-end than mocked). Its
 * primary purpose is to give the CI PHPUnit job something to run
 * so a green tick means "nothing catastrophic broke at autoload".
 */
class ApplicationTest extends TestCase {

	public function testAppIdMatchesInfoXml(): void {
		$this->assertSame('integration_inspect360', Application::APP_ID);
	}

	public function testAppClassInstantiates(): void {
		$app = new Application();
		$this->assertInstanceOf(Application::class, $app);
	}
}
