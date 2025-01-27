<?php
/**
 * CMS Pico - Create websites using Pico CMS for Nextcloud.
 *
 * @copyright Copyright (c) 2017, Maxence Lange (<maxence@artificial-owl.com>)
 * @copyright Copyright (c) 2019, Daniel Rudolf (<picocms.org@daniel-rudolf.de>)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace OCA\CMSPico\Tests\Service;

use OCA\CMSPico\AppInfo\Application;
use OCA\CMSPico\Controller\ThemesController;
use OCA\CMSPico\Exceptions\ThemeNotFoundException;
use OCA\CMSPico\Service\FileService;
use OCA\CMSPico\Service\PicoService;
use OCA\CMSPico\Service\ThemesService;
use OCA\CMSPico\Tests\Env;
use PHPUnit\Exception as PHPUnitException;
use PHPUnit\Framework\TestCase;

class ThemesServiceTest extends TestCase
{
	/** @var FileService */
	private $fileService;

	/** @var ThemesController */
	private $themesController;

	/** @var ThemesService */
	private $themesService;

	protected function setUp()
	{
		Env::setUser(Env::ENV_TEST_USER1);
		Env::logout();

		$app = new Application();
		$container = $app->getContainer();

		$this->fileService = $container->query(FileService::class);
		$this->themesService = $container->query(ThemesService::class);
		$this->themesController = $container->query(ThemesController::class);
	}

	protected function tearDown()
	{
		Env::setUser(Env::ENV_TEST_USER1);
		Env::logout();
	}

	public function testThemes()
	{
		$this->assertCount(1, $this->themesService->getThemes());
		$this->assertCount(0, $this->themesService->getCustomThemes());
		$this->assertCount(0, $this->themesService->getNewCustomThemes());

		$this->fileService->getAppDataFolder(PicoService::DIR_THEMES)
			->newFolder('this_is_a_test')
			->newFile('index.twig');
		$this->assertCount(1, $this->themesService->getThemes());
		$this->assertCount(0, $this->themesService->getCustomThemes());
		$this->assertCount(1, $this->themesService->getNewCustomThemes());

		try {
			$this->themesService->assertValidTheme('this_is_a_test');
			$this->assertSame(true, false, 'should return an exception');
		} catch (ThemeNotFoundException $e) {
		} catch (PHPUnitException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->assertSame(true, false, 'should return ThemeNotFoundException');
		}

		$this->themesController->addCustomTheme('this_is_a_test');
		$this->assertCount(2, $this->themesService->getThemes());
		$this->assertCount(1, $this->themesService->getCustomThemes());
		$this->assertCount(0, $this->themesService->getNewCustomThemes());

		$this->themesService->assertValidTheme('this_is_a_test');

		$this->themesController->removeCustomTheme('this_is_a_test');
		$this->assertCount(1, $this->themesService->getThemes());
		$this->assertCount(0, $this->themesService->getCustomThemes());
		$this->assertCount(1, $this->themesService->getNewCustomThemes());

		$this->fileService->getAppDataFolder(PicoService::DIR_THEMES)
			->getFolder('this_is_a_test')->delete();
		$this->assertCount(1, $this->themesService->getThemes());
		$this->assertCount(0, $this->themesService->getCustomThemes());
		$this->assertCount(0, $this->themesService->getNewCustomThemes());

		try {
			$this->themesService->assertValidTheme('this_is_a_test');
			$this->assertSame(true, false, 'should return an exception');
		} catch (ThemeNotFoundException $e) {
		} catch (PHPUnitException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->assertSame(true, false, 'should return ThemeNotFoundException');
		}
	}
}
