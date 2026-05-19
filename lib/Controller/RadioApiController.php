<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2025
 */

namespace OCA\Music\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppFramework\Utility\FileExistsException;
use OCA\Music\BusinessLayer\RadioStationBusinessLayer;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Http\FileResponse;
use OCA\Music\Http\RelayStreamResponse;
use OCA\Music\Service\PlaylistFileService;
use OCA\Music\Service\RadioService;
use OCA\Music\Service\StreamTokenService;
use OCA\Music\Utility\HttpUtil;

class RadioApiController extends Controller {
	private IConfig $config;
	private IURLGenerator $urlGenerator;
	private RadioStationBusinessLayer $businessLayer;
	private RadioService $service;
	private StreamTokenService $tokenService;
	private PlaylistFileService $playlistFileService;
	private string $userId;
	private IRootFolder $rootFolder;
	private Logger $logger;

	public function __construct(
			string $appName,
			IRequest $request,
			IConfig $config,
			IURLGenerator $urlGenerator,
			RadioStationBusinessLayer $businessLayer,
			RadioService $service,
			StreamTokenService $tokenService,
			PlaylistFileService $playlistFileService,
			?string $userId,
			IRootFolder $rootFolder,
			Logger $logger
	) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->businessLayer = $businessLayer;
		$this->service = $service;
		$this->tokenService = $tokenService;
		$this->playlistFileService = $playlistFileService;
		$this->userId = $userId ?? ''; // ensure non-null to satisfy Scrutinizer; may be null when resolveStreamUrl used on public share
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
	}

	/**
	 * lists all radio stations
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getAll() : JSONResponse {
		$stations = $this->businessLayer->findAll($this->userId);
		return new JSONResponse(
			\array_map(fn($s) => $s->toApi(), $stations)
		);
	}

	/**
	 * creates a station
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function create(?string $name, ?string $streamUrl, ?string $homeUrl) : JSONResponse {
		if ($streamUrl === null) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, "Mandatory argument 'streamUrl' not given");
		}
		
		try {
			$station = $this->businessLayer->create($this->userId, $name, $streamUrl, $homeUrl);
			return new JSONResponse($station->toApi());
		} catch (\DomainException $ex) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, $ex->getMessage());
		}
	}

	/**
	 * deletes a station
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function delete(int $id) : JSONResponse {
		try {
			$this->businessLayer->delete($id, $this->userId);
			return new JSONResponse([]);
		} catch (BusinessLayerException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		}
	}

	/**
	 * get a single radio station
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function get(int $id) : JSONResponse {
		try {
			$station = $this->businessLayer->find($id, $this->userId);
			return new JSONResponse($station->toApi());
		} catch (BusinessLayerException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		}
	}

	/**
	 * update a station
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update(int $id, ?string $name = null, ?string $streamUrl = null, ?string $homeUrl = null) : JSONResponse {
		if ($name === null && $streamUrl === null && $homeUrl === null) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, "at least one of the args ['name', 'streamUrl', 'homeUrl'] must be given");
		}

		try {
			$station = $this->businessLayer->updateStation($id, $this->userId, $name, $streamUrl, $homeUrl);
			return new JSONResponse($station->toApi());
		} catch (BusinessLayerException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		} catch (\DomainException $ex) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, $ex->getMessage());
		}
	}

	/**
	 * export all radio stations to a file
	 *
	 * @param string $name target file name
	 * @param string $path parent folder path
	 * @param string $oncollision action to take on file name collision,
	 *								supported values:
	 *								- 'overwrite' The existing file will be overwritten
	 *								- 'keepboth' The new file is named with a suffix to make it unique
	 *								- 'abort' (default) The operation will fail
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportAllToFile(string $name, string $path, string $oncollision='abort') : JSONResponse {
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$exportedFilePath = $this->playlistFileService->exportRadioStationsToFile(
					$this->userId, $userFolder, $path, $name, $oncollision);
			return new JSONResponse(['wrote_to_file' => $exportedFilePath]);
		} catch (\OCP\Files\NotFoundException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'folder not found');
		} catch (FileExistsException $ex) {
			return new ErrorResponse(Http::STATUS_CONFLICT, 'file already exists', ['path' => $ex->getPath(), 'suggested_name' => $ex->getAltName()]);
		} catch (\OCP\Files\NotPermittedException $ex) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, 'user is not allowed to write to the target file');
		}
	}

	/**
	 * import radio stations from a file
	 * @param string $filePath path of the file to import
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function importFromFile(string $filePath) : JSONResponse {
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$result = $this->playlistFileService->importRadioStationsFromFile($this->userId, $userFolder, $filePath);
			$result['stations'] = \array_map(fn($s) => $s->toApi(), $result['stations']);
			return new JSONResponse($result);
		} catch (\OCP\Files\NotFoundException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'playlist file not found');
		} catch (\UnexpectedValueException $ex) {
			return new ErrorResponse(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, $ex->getMessage());
		}
	}

	/**
	 * reset all the radio stations of the user
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function resetAll() : JSONResponse {
		$this->businessLayer->deleteAll($this->userId);
		return new JSONResponse(['success' => true]);
	}

	/**
	* get metadata for a channel
	*
	* @NoAdminRequired
	* @NoCSRFRequired
	*/
	public function getChannelInfo(int $id, ?string $type=null) : JSONResponse {
		try {
			$station = $this->businessLayer->find($id, $this->userId);
			$streamUrl = $station->getStreamUrl();

			switch ($type) {
				case 'icy':
					$metadata = $this->service->readIcyMetadata($streamUrl, 3, 5);
					break;
				case 'shoutcast-v1':
					$metadata = $this->service->readShoutcastV1Metadata($streamUrl);
					break;
				case 'shoutcast-v2':
					$metadata = $this->service->readShoutcastV2Metadata($streamUrl);
					break;
				case 'icecast':
					$metadata = $this->service->readIcecastMetadata($streamUrl);
					break;
				default:
					$metadata = $this->service->readIcyMetadata($streamUrl, 3, 5)
							?? $this->service->readShoutcastV2Metadata($streamUrl)
							?? $this->service->readIcecastMetadata($streamUrl)
							?? $this->service->readShoutcastV1Metadata($streamUrl);
					break;
			}

			return new JSONResponse($metadata);
		} catch (BusinessLayerException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		}
	}

	/**
	 * get stream URL for a radio station
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function stationStreamUrl(int $id) : JSONResponse {
		try {
			$station = $this->businessLayer->find($id, $this->userId);
			return $this->doResolveStreamUrl($station->getStreamUrl());
		} catch (BusinessLayerException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		}
	}

	/**
	 * get audio stream for a radio station
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function stationStream(int $id) : Response {
		try {
			$station = $this->businessLayer->find($id, $this->userId);
			$streamUrl = $station->getStreamUrl();
			$resolved = $this->service->resolveStreamUrl($streamUrl);
			if ($this->streamRelayEnabled()) {
				return new RelayStreamResponse($resolved['url']);
			} else {
				return new RedirectResponse($resolved['url']);
			}
		} catch (BusinessLayerException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		}
	}

	/**
	 * get the actual stream URL from the given public URL
	 *
	 * Available without login since no user data is handled and this may be used on link-shared folder.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function resolveStreamUrl(string $url, ?string $token) : JSONResponse {
		$url = \rawurldecode($url);

		if ($token === null) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'a security token must be passed');
		} elseif (!$this->tokenService->urlTokenIsValid($url, \rawurldecode($token))) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'the security token is invalid');
		} else {
			return $this->doResolveStreamUrl($url);
		}
	}

	private function doResolveStreamUrl(string $url) : JSONResponse {
		$resolved = $this->service->resolveStreamUrl($url);
		$relayEnabled = $this->streamRelayEnabled();
		if ($resolved['url'] === null) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'failed to read from the stream URL');
		} else {
			$token = $this->tokenService->tokenForUrl($resolved['url']);
			if ($resolved['hls']) {
				$resolved['url'] = $this->urlGenerator->linkToRoute(
					'music.radioApi.hlsManifest',
					['url' => \rawurlencode($resolved['url']), 'token' => \rawurlencode($token)]
				);
			} elseif ($relayEnabled) {
				$resolved['url'] = $this->urlGenerator->linkToRoute(
					'music.radioApi.streamFromUrl',
					['url' => \rawurlencode($resolved['url']), 'token' => \rawurlencode($token)]
				);
			}
		}
		return new JSONResponse($resolved);
	}

	/**
	 * create a relayed stream for the given URL if relaying enabled; otherwise just redirect to the URL
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function streamFromUrl(string $url, ?string $token) : Response {
		$url = \rawurldecode($url);

		if ($token === null) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'a security token must be passed');
		} elseif (!$this->tokenService->urlTokenIsValid($url, \rawurldecode($token))) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'the security token is invalid');
		} elseif ($this->streamRelayEnabled()) {
			return new RelayStreamResponse($url);
		} else {
			return new RedirectResponse($url);
		}
	}

	/**
	 * get manifest of a HLS stream
	 *
	 * This fetches the manifest file from the given URL and returns a modified version of it.
	 * The front-end can't easily stream directly from the original source because of the Content-Security-Policy.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function hlsManifest(string $url, ?string $token) : Response {
		$url = \rawurldecode($url);

		if (!$this->hlsEnabled()) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, 'the cloud admin has disabled HLS streaming');
		} elseif ($token === null) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'a security token must be passed');
		} elseif (!$this->tokenService->urlTokenIsValid($url, \rawurldecode($token))) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'the security token is invalid');
		} else {
			list('content' => $content, 'status_code' => $status, 'content_type' => $contentType)
				= $this->service->getHlsManifest($url);

			return new FileResponse([
				'content' => $content,
				'mimetype' => $contentType
			], $status);
		}
	}

	/**
	 * get one segment of a HLS stream
	 *
	 * The segment is fetched from the given URL and relayed as such to the client.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function hlsSegment(string $url, ?string $token) : Response {
		$url = \rawurldecode($url);

		if (!$this->hlsEnabled()) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, 'the cloud admin has disabled HLS streaming');
		} elseif ($token === null) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'a security token must be passed');
		} elseif (!$this->tokenService->urlTokenIsValid($url, \rawurldecode($token))) {
			return new ErrorResponse(Http::STATUS_UNAUTHORIZED, 'the security token is invalid');
		} else {
			list('content' => $content, 'status_code' => $status, 'content_type' => $contentType, 'message' => $message)
				= HttpUtil::loadFromUrl($url);

			if ($content === false) {
				$this->logger->warning("Failed to load HLS segment from $url, status: $status $message");
				return new ErrorResponse(Http::STATUS_NOT_FOUND, 'failed to read the HLS segment');
			} else {
				return new FileResponse([
					'content' => $content,
					'mimetype' => $contentType ?? 'application/octet-stream'
				], $status);
			}
		}
	}

	private function hlsEnabled() : bool {
		$enabled = (bool)$this->config->getSystemValue('music.enable_radio_hls', true);
		if ($this->userId === '') {
			$enabled = (bool)$this->config->getSystemValue('music.enable_radio_hls_on_share', $enabled);
		}
		return $enabled;
	}

	private function streamRelayEnabled() : bool {
		$enabled = (bool)$this->config->getSystemValue('music.relay_radio_stream', true);
		if ($this->userId === '') {
			$enabled = (bool)$this->config->getSystemValue('music.relay_radio_stream_on_share', $enabled);
		}
		return $enabled;
	}
}
