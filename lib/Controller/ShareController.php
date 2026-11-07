<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2018 - 2026
 */

namespace OCA\Music\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\Share\Exceptions\ShareNotFound;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Http\FileStreamResponse;
use OCA\Music\Service\PlaylistFileService;
use OCA\Music\Service\Scanner;

/**
 * End-points for shared audio file handling. Methods of this class may be
 * used while there is no user logged in.
 */
class ShareController extends Controller {

	private IManager $shareManager;
	private Scanner $scanner;
	private PlaylistFileService $playlistFileService;
	private Logger $logger;

	public function __construct(
			string $appName,
			IRequest $request,
			Scanner $scanner,
			PlaylistFileService $playlistFileService,
			Logger $logger,
			IManager $shareManager
	) {
		parent::__construct($appName, $request);
		$this->shareManager = $shareManager;
		$this->scanner = $scanner;
		$this->playlistFileService = $playlistFileService;
		$this->logger = $logger;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function fileInfo(string $token, int $fileId) : JSONResponse {
		try {
			$share = $this->shareManager->getShareByToken($token);
			$shareOwner = $share->getShareOwner();

			// If non-zero fileId is given, the $share identified by the token should
			// be the file's parent directory. Otherwise the share is the target file.
			if ($fileId == 0) {
				$folder = $this->scanner->resolveUserFolder($shareOwner);
				$fileId = $share->getNodeId();
			} else {
				$folder = $this->getSharedFolder($token);
				if (empty($folder->getById($fileId))) {
					return new ErrorResponse(Http::STATUS_NOT_FOUND, 'no such file under the share');
				}
			}

			$info = $this->scanner->getFileInfo($fileId, $shareOwner, $folder);

			if ($info) {
				return new JSONResponse($info);
			} else {
				return new ErrorResponse(Http::STATUS_NOT_FOUND);
			}
		} catch (ShareNotFound $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'invalid share token');
		} catch (\OCP\Files\NotFoundException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		} catch (\OCP\Files\NotPermittedException $ex) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, $ex->getMessage());
		}
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function download(string $token, int $fileId) : Response {
		try {
			$sharedFolder = $this->getSharedFolder($token);
			$file = $sharedFolder->getById($fileId)[0] ?? null;
			if ($file instanceof File) {
				return new FileStreamResponse($file);
			} else {
				return new ErrorResponse(Http::STATUS_NOT_FOUND, 'no such file under the share');
			}
		} catch (ShareNotFound $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'invalid share token');
		} catch (\OCP\Files\NotFoundException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		} catch (\OCP\Files\NotPermittedException $ex) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, $ex->getMessage());
		}
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function parsePlaylist(string $token, int $fileId) : JSONResponse {
		try {
			$sharedFolder = $this->getSharedFolder($token);
			$result = $this->playlistFileService->parseFile($fileId, $sharedFolder);

			$bogusUrlId = -1;

			// compose the final result
			$result['files'] = \array_map(function ($fileInfo) use ($sharedFolder, &$bogusUrlId) {
				if (isset($fileInfo['url'])) {
					$fileInfo['id'] = $bogusUrlId--;
					$fileInfo['mimetype'] = null;
					$fileInfo['external'] = true;
					return $fileInfo;
				} else {
					$file = $fileInfo['file'];
					return [
						'id' => $file->getId(),
						'name' => $file->getName(),
						'path' => $sharedFolder->getRelativePath($file->getParent()->getPath()),
						'mimetype' => $file->getMimeType(),
						'caption' => $fileInfo['caption'],
						'external' => false
					];
				}
			}, $result['files']);
			return new JSONResponse($result);
		} catch (ShareNotFound $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'invalid share token');
		} catch (\OCP\Files\NotFoundException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $ex->getMessage());
		} catch (\OCP\Files\NotPermittedException $ex) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, $ex->getMessage());
		} catch (\UnexpectedValueException $ex) {
			return new ErrorResponse(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, $ex->getMessage());
		}
	}

	private function getSharedFolder(string $token) : Folder {
		$share = $this->shareManager->getShareByToken($token);

		if (($share->getPermissions() & \OCP\Constants::PERMISSION_READ) === 0) {
			// disallow upload-only shares
			throw new \OCP\Files\NotPermittedException('the share is not readable');
		}

		$fileOwner = $share->getShareOwner();
		$fileOwnerHome = $this->scanner->resolveUserFolder($fileOwner);

		$matchingFolders = $fileOwnerHome->getById($share->getNodeId());
		$folder = $matchingFolders[0] ?? null;
		if (!($folder instanceof Folder)) {
			throw new \OCP\Files\NotFoundException('the share is not a valid folder');
		}

		return $folder;
	}
}
