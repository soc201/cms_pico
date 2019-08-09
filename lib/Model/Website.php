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

namespace OCA\CMSPico\Model;

use OCA\CMSPico\AppInfo\Application;
use OCA\CMSPico\Exceptions\TemplateNotFoundException;
use OCA\CMSPico\Exceptions\ThemeNotCompatibleException;
use OCA\CMSPico\Exceptions\ThemeNotFoundException;
use OCA\CMSPico\Exceptions\WebsiteForeignOwnerException;
use OCA\CMSPico\Exceptions\WebsiteInvalidDataException;
use OCA\CMSPico\Exceptions\WebsiteInvalidFilesystemException;
use OCA\CMSPico\Exceptions\WebsiteNotPermittedException;
use OCA\CMSPico\Files\FileInterface;
use OCA\CMSPico\Files\StorageFile;
use OCA\CMSPico\Files\StorageFolder;
use OCA\CMSPico\Service\AssetsService;
use OCA\CMSPico\Service\MiscService;
use OCA\CMSPico\Service\PicoService;
use OCA\CMSPico\Service\PluginsService;
use OCA\CMSPico\Service\TemplatesService;
use OCA\CMSPico\Service\ThemesService;
use OCP\Files\Folder as OCFolder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node as OCNode;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;

class Website extends WebsiteCore
{
	/** @var int */
	const SITE_LENGTH_MIN = 3;

	/** @var int */
	const SITE_LENGTH_MAX = 255;

	/** @var string */
	const SITE_REGEX = '^[a-z][a-z0-9_-]+[a-z0-9]$';

	/** @var int */
	const NAME_LENGTH_MIN = 3;

	/** @var int */
	const NAME_LENGTH_MAX = 255;

	/** @var IConfig */
	private $config;

	/** @var IL10N */
	private $l10n;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var PicoService */
	private $picoService;

	/** @var AssetsService */
	private $assetsService;

	/** @var PluginsService */
	private $pluginsService;

	/** @var ThemesService */
	private $themesService;

	/** @var TemplatesService */
	private $templatesService;

	/** @var MiscService */
	private $miscService;

	/** @var StorageFolder */
	private $folder;

	/**
	 * Website constructor.
	 *
	 * @param array|string|null $data
	 */
	public function __construct($data = null)
	{
		$this->config = \OC::$server->getConfig();
		$this->l10n = \OC::$server->getL10N(Application::APP_NAME);
		$this->groupManager = \OC::$server->getGroupManager();
		$this->rootFolder = \OC::$server->getRootFolder();
		$this->urlGenerator = \OC::$server->getURLGenerator();
		$this->picoService = \OC::$server->query(PicoService::class);
		$this->assetsService = \OC::$server->query(AssetsService::class);
		$this->pluginsService = \OC::$server->query(PluginsService::class);
		$this->themesService = \OC::$server->query(ThemesService::class);
		$this->templatesService = \OC::$server->query(TemplatesService::class);
		$this->miscService = \OC::$server->query(MiscService::class);

		parent::__construct($data);
	}

	/**
	 * @return string
	 */
	public function getTimeZone(): string
	{
		$serverTimeZone = date_default_timezone_get() ?: 'UTC';
		return $this->config->getUserValue($this->getUserId(), 'core', 'timezone', $serverTimeZone);
	}

	/**
	 * @param string $absolutePath
	 *
	 * @return string
	 * @throws WebsiteInvalidFilesystemException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getFileContent(string $absolutePath): string
	{
		$folder = $this->picoService->getContentFolder($this);
		$basePath = $this->picoService->getContentPath($this);

		try {
			$relativePath = $this->miscService->getRelativePath($absolutePath, $basePath);
		} catch (InvalidPathException $e) {
			$folder = $this->assetsService->getAssetsFolder($this);
			$basePath = $this->assetsService->getAssetsPath($this);

			try {
				$relativePath = $this->miscService->getRelativePath($absolutePath, $basePath);
			} catch (InvalidPathException $e) {
				$folder = $this->pluginsService->getPluginsFolder();
				$basePath = $this->pluginsService->getPluginsPath();

				try {
					$relativePath = $this->miscService->getRelativePath($absolutePath, $basePath);
				} catch (InvalidPathException $e) {
					$folder = $this->themesService->getThemesFolder();
					$basePath = $this->themesService->getThemesPath();

					try {
						$relativePath = $this->miscService->getRelativePath($absolutePath, $basePath);
					} catch (InvalidPathException $e) {
						// the requested file is neither in the content nor assets, plugins or themes folder
						// Pico mustn't have access to any other directory
						throw new InvalidPathException();
					}
				}
			}
		}

		/** @var FileInterface $file */
		$file = $folder->get($relativePath);
		if (!$file->isFile()) {
			throw new InvalidPathException();
		}

		return $file->getContent();
	}

	/**
	 * @param string $path
	 * @param array  $meta
	 *
	 * @throws InvalidPathException
	 * @throws WebsiteInvalidFilesystemException
	 * @throws WebsiteNotPermittedException
	 * @throws NotPermittedException
	 */
	public function assertViewerAccess(string $path, array $meta = [])
	{
		$exceptionClass = WebsiteNotPermittedException::class;
		if ($this->getType() === self::TYPE_PUBLIC) {
			if (empty($meta['access'])) {
				return;
			}

			$groupAccess = $meta['access'];
			if (!is_array($groupAccess)) {
				$groupAccess = explode(',', strtolower($groupAccess));
			}

			foreach ($groupAccess as $group) {
				if ($group === 'public') {
					return;
				}

				if ($this->getViewer() && $this->groupManager->groupExists($group)) {
					if ($this->groupManager->isInGroup($this->getViewer(), $group)) {
						return;
					}
				}
			}

			$exceptionClass = NotPermittedException::class;
		}

		if ($this->getViewer()) {
			if ($this->getViewer() === $this->getUserId()) {
				return;
			}

			/** @var OCFolder $viewerOCFolder */
			$viewerOCFolder = $this->rootFolder->getUserFolder($this->getViewer());
			$viewerAccessClosure = function (OCNode $node) use ($viewerOCFolder) {
				$nodeId = $node->getId();

				$viewerNodes = $viewerOCFolder->getById($nodeId);
				foreach ($viewerNodes as $viewerNode) {
					if ($viewerNode->isReadable()) {
						return true;
					}
				}

				return false;
			};

			$websiteFolder = $this->getWebsiteFolder();

			$path = $this->miscService->normalizePath($path);
			while ($path && ($path !== '.')) {
				$file = null;

				try {
					/** @var StorageFile|StorageFolder $file */
					$file = $websiteFolder->get($path);
				} catch (NotFoundException $e) {}

				if ($file) {
					if ($viewerAccessClosure($file->getOCNode())) {
						return;
					}

					throw new $exceptionClass();
				}

				$path = dirname($path);
			}

			if ($viewerAccessClosure($websiteFolder->getOCNode())) {
				return;
			}
		}

		throw new $exceptionClass();
	}

	/**
	 * @throws WebsiteInvalidDataException
	 */
	public function assertValidName()
	{
		if (strlen($this->getName()) < self::NAME_LENGTH_MIN) {
			throw new WebsiteInvalidDataException('name', $this->l10n->t('The name of the website must be longer.'));
		}
		if (strlen($this->getName()) > self::NAME_LENGTH_MAX) {
			throw new WebsiteInvalidDataException('name', $this->l10n->t('The name of the website is too long.'));
		}
	}

	/**
	 * @throws WebsiteInvalidDataException
	 */
	public function assertValidSite()
	{
		if (strlen($this->getSite()) < self::SITE_LENGTH_MIN) {
			throw new WebsiteInvalidDataException('site', $this->l10n->t('The identifier of the website must be longer.'));
		}
		if (strlen($this->getSite()) > self::SITE_LENGTH_MAX) {
			throw new WebsiteInvalidDataException('site', $this->l10n->t('The identifier of the website is too long.'));
		}

		if (preg_match('/' . self::SITE_REGEX . '/', $this->getSite()) !== 1) {
			throw new WebsiteInvalidDataException(
				'site',
				$this->l10n->t('The identifier of the website can only contains alpha numeric chars.')
			);
		}
	}

	/**
	 * @throws WebsiteInvalidDataException
	 */
	public function assertValidPath()
	{
		try {
			$path = $this->miscService->normalizePath($this->getPath());
			if ($path === '') {
				throw new InvalidPathException();
			}
		} catch (InvalidPathException $e) {
			throw new WebsiteInvalidDataException(
				'path',
				$this->l10n->t('The path of the website is invalid.')
			);
		}

		$userFolder = $this->rootFolder->getUserFolder($this->getUserId());

		try {
			/** @var OCFolder $ocFolder */
			$ocFolder = $userFolder->get(dirname($path));
			if (!($ocFolder instanceof OCFolder)) {
				throw new InvalidPathException();
			}
		} catch (\Exception $e) {
			if (($e instanceof InvalidPathException) || ($e instanceof NotFoundException)) {
				throw new WebsiteInvalidDataException(
					'path',
					$this->l10n->t('Parent folder of the website\'s path not found.')
				);
			}

			throw $e;
		}
	}

	/**
	 * @throws ThemeNotFoundException
	 * @throws ThemeNotCompatibleException
	 */
	public function assertValidTheme()
	{
		$this->themesService->assertValidTheme($this->getTheme());
	}

	/**
	 * @throws TemplateNotFoundException
	 */
	public function assertValidTemplate()
	{
		$this->templatesService->assertValidTemplate($this->getTemplateSource());
	}

	/**
	 * @param string $userId
	 *
	 * @throws WebsiteForeignOwnerException
	 */
	public function assertOwnedBy($userId)
	{
		if ($this->getUserId() !== $userId) {
			throw new WebsiteForeignOwnerException();
		}
	}

	/**
	 * @param string|null $folderName
	 *
	 * @return StorageFolder
	 * @throws WebsiteInvalidFilesystemException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function getWebsiteFolder(string $folderName = null): StorageFolder
	{
		if ($this->folder === null) {
			try {
				$ocUserFolder = $this->rootFolder->getUserFolder($this->getUserId());
				$userFolder = new StorageFolder($ocUserFolder);

				/** @var StorageFolder $websiteFolder */
				$websiteFolder = $userFolder->get($this->getPath());
				if (!$websiteFolder->isFolder()) {
					throw new InvalidPathException();
				}

				$this->folder = $websiteFolder->fakeRoot();
			} catch (InvalidPathException $e) {
				throw new WebsiteInvalidFilesystemException($e);
			} catch (NotFoundException $e) {
				throw new WebsiteInvalidFilesystemException($e);
			}
		}

		if ($folderName) {
			/** @var StorageFolder $folder */
			$folder = $this->folder->get($folderName);
			if (!$folder->isFolder()) {
				throw new InvalidPathException();
			}

			return $folder;
		}

		return $this->folder;
	}

	/**
	 * @return string
	 */
	public function getWebsiteUrl(): string
	{
		if (!$this->getProxyRequest()) {
			$route = Application::APP_NAME . '.Pico.getPage';
			$parameters = [ 'site' => $this->getSite(), 'page' => '' ];
			return $this->urlGenerator->linkToRoute($route, $parameters) . '/';
		} else {
			return \OC::$WEBROOT . '/sites/' . urlencode($this->getSite()) . '/';
		}
	}
}
