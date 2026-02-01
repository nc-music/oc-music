<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\AmpacheSessionMapper;
use OCA\Music\Db\AmpacheUserMapper;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Service\LibrarySettings;
use OCA\Music\Service\Scanner;
use OCA\Music\Utility\AppInfo;
use OCA\Music\Utility\StringUtil;
use OCA\Music\Service\Scrobbling\ExternalScrobbler;

class SettingController extends Controller {
	private const DEFAULT_PASSWORD_LENGTH = 10;
	/* Character set without look-alike characters. Similar but even more stripped set would be found
	 * on Nextcloud as ISecureRandom::CHAR_HUMAN_READABLE but that's not available on ownCloud. */
	private const API_KEY_CHARSET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	private AmpacheSessionMapper $ampacheSessionMapper;
	private AmpacheUserMapper $ampacheUserMapper;
	private Scanner $scanner;
	private string $userId;
	private LibrarySettings $librarySettings;
	private ISecureRandom $secureRandom;
	private IURLGenerator $urlGenerator;
	private Logger $logger;
	/** @var ExternalScrobbler[] $externalScrobblers */
	private array $externalScrobblers;

	public function __construct(
			string $appName,
			IRequest $request,
			AmpacheSessionMapper $ampacheSessionMapper,
			AmpacheUserMapper $ampacheUserMapper,
			Scanner $scanner,
			?string $userId,
			LibrarySettings $librarySettings,
			ISecureRandom $secureRandom,
			IURLGenerator $urlGenerator,
			Logger $logger,
			array $externalScrobblers
	) {
		parent::__construct($appName, $request);

		$this->ampacheSessionMapper = $ampacheSessionMapper;
		$this->ampacheUserMapper = $ampacheUserMapper;
		$this->scanner = $scanner;
		$this->userId = $userId ?? ''; // ensure non-null to satisfy Scrutinizer; the null case should happen only when the user has already logged out
		$this->librarySettings = $librarySettings;
		$this->secureRandom = $secureRandom;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->externalScrobblers = $externalScrobblers;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @UseSession to keep the session reserved while execution in progress
	 */
	public function userPath(string $value) : JSONResponse {
		$prevPath = $this->librarySettings->getPath($this->userId);
		$success = $this->librarySettings->setPath($this->userId, $value);

		if ($success) {
			$this->scanner->updatePath($prevPath, $value, $this->userId);
		}

		return new JSONResponse(['success' => $success]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function userExcludedPaths(array $value) : JSONResponse {
		$success = $this->librarySettings->setExcludedPaths($this->userId, $value);
		return new JSONResponse(['success' => $success]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function enableScanMetadata(bool $value) : JSONResponse {
		$this->librarySettings->setScanMetadataEnabled($this->userId, $value);
		return new JSONResponse(['success' => true]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function ignoredArticles(array $value) : JSONResponse {
		$this->librarySettings->setIgnoredArticles($this->userId, $value);
		return new JSONResponse(['success' => true]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getAll() : JSONResponse {
		return new JSONResponse([
			'path' => $this->librarySettings->getPath($this->userId),
			'excludedPaths' => $this->librarySettings->getExcludedPaths($this->userId),
			'scanMetadata' => $this->librarySettings->getScanMetadataEnabled($this->userId),
			'ignoredArticles' => $this->librarySettings->getIgnoredArticles($this->userId),
			'ampacheUrl' => $this->getAmpacheUrl(),
			'subsonicUrl' => $this->getSubsonicUrl(),
			'ampacheKeys' => $this->ampacheUserMapper->getAll($this->userId),
			'appVersion' => AppInfo::getVersion(),
			'user' => $this->userId,
			'scrobblers' => $this->getScrobbleAuth()
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getUserKeys() : JSONResponse {
		return new JSONResponse($this->ampacheUserMapper->getAll($this->userId));
	}

	private function getAmpacheUrl() : string {
		return (string)\str_replace(
			'/server/xml.server.php',
			'',
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('music.ampache.xmlApi'))
		);
	}

	private function getSubsonicUrl() : string {
		return (string)\str_replace(
			'/rest/dummy',
			'',
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(
				'music.subsonic.handleRequest',
				['method' => 'dummy']
			))
		);
	}

	private function getScrobbleAuth(): array {
		$services = [];
		foreach ($this->externalScrobblers as $scrobbler) {
			$tokenRequestUrl = $scrobbler->getTokenRequestUrl();
			$services[] = [
				'service' => $scrobbler->getName(),
				'identifier' => $scrobbler->getIdentifier(),
				'configured' => $tokenRequestUrl && $scrobbler->getApiSecret(),
				'tokenRequestUrl' => $tokenRequestUrl,
				'hasSession' => $scrobbler->getApiSession($this->userId) !== null
			];
		}

		return $services;
	}

	private function storeUserKey(?string $description, string $password) : ?int {
		$hash = \hash('sha256', $password);
		$description = StringUtil::truncate($description, 64); // some DB setups can't truncate automatically to column max size
		return $this->ampacheUserMapper->addUserKey($this->userId, $hash, $description);
	}

	/**
	 * @NoAdminRequired
	 */
	public function createUserKey(?int $length, ?string $description) : JSONResponse {
		if ($length === null || $length < self::DEFAULT_PASSWORD_LENGTH) {
			$length = self::DEFAULT_PASSWORD_LENGTH;
		}

		$password = $this->secureRandom->generate($length, self::API_KEY_CHARSET);

		$id = $this->storeUserKey($description, $password);

		if ($id === null) {
			return new ErrorResponse(Http::STATUS_INTERNAL_SERVER_ERROR, 'Error while saving the credentials');
		}

		return new JSONResponse(['id' => $id, 'password' => $password, 'description' => $description], Http::STATUS_CREATED);
	}

	/**
	 * The CORS-version of the key creation function is targeted for external clients. We need separate function
	 * because the CORS middleware blocks the normal internal access on Nextcloud versions older than 25 as well
	 * as on ownCloud 10.0, at least (but not on OC 10.4+).
	 *
	 * @NoAdminRequired
	 * @CORS
	 */
	public function createUserKeyCors(?int $length, ?string $description) : JSONResponse {
		return $this->createUserKey($length, $description);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function removeUserKey(int $id) : JSONResponse {
		$this->ampacheSessionMapper->revokeSessions($id);
		$this->ampacheUserMapper->removeUserKey($this->userId, $id);
		return new JSONResponse(['success' => true]);
	}
}
