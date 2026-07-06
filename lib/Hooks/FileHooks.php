<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2017 - 2025
 */

namespace OCA\Music\Hooks;

use OCA\Music\AppFramework\Core\Logger;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;

use OCA\Music\AppInfo\Application;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Service\Scanner;

class FileHooks {
	private IRootFolder $filesystemRoot;

	public function __construct(IRootFolder $filesystemRoot) {
		$this->filesystemRoot = $filesystemRoot;
	}

	/**
	 * Invoke auto update of music database after file or folder deletion
	 * @param Node $node pointing to the file or folder
	 */
	private static function deleted(Node $node) : void {
		$scanner = self::inject(Scanner::class);

		if ($node->getType() == FileInfo::TYPE_FILE) {
			$scanner->delete($node->getId());
		} else {
			$scanner->deleteFolder($node);
		}
	}

	/**
	 * Invoke auto update of music database after file update or file creation
	 * @param Node $node pointing to the file
	 */
	private static function updated(Node $node) : void {
		// At least on Nextcloud 13, it sometimes happens that this hook is triggered
		// when the core creates some temporary file and trying to access the provided
		// node throws an exception, probably because the temp file is already removed
		// by the time the execution gets here. See #636.
		// Furthermore, when the core opens a file in stream mode for writing using
		// File::fopen, this hook gets triggered immediately after the opening succeeds,
		// before anything is actually written and while the file is *exclusively locked
		// because of the write mode*. See #638.
		try {
			self::handleUpdated($node);
		} catch (\OCP\Files\NotFoundException $e) {
			$logger = self::inject(Logger::class);
			$logger->warning('FileHooks::updated triggered for a non-existing file');
		} catch (\OCP\Lock\LockedException $e) {
			$logger = self::inject(Logger::class);
			$logger->warning('FileHooks::updated triggered for a locked file ' . $node->getName());
		}
	}

	private static function handleUpdated(Node $node) : void {
		// we are interested only about updates on files, not on folders
		if ($node->getType() == FileInfo::TYPE_FILE) {
			$scanner = self::inject(Scanner::class);
			$userId = self::getUser($node);

			// Ignore event if we got no user or folder or the user has not yet scanned the music
			// collection. The last condition is especially to prevent problems when creating new user
			// and the default file set contains one or more audio files (see the discussion in #638).
			if (!empty($userId) && self::userHasMusicLib($userId)) {
				$scanner->update($node, $userId, $node->getPath());
			}
		}
	}

	private static function moved(Node $node) : void {
		try {
			self::handleMoved($node);
		} catch (\OCP\Files\NotFoundException $e) {
			$logger = self::inject(Logger::class);
			$logger->warning('FileHooks::moved triggered for a non-existing file');
		} catch (\OCP\Lock\LockedException $e) {
			$logger = self::inject(Logger::class);
			$logger->warning('FileHooks::moved triggered for a locked file ' . $node->getName());
		}
	}

	private static function handleMoved(Node $node) : void {
		$scanner = self::inject(Scanner::class);
		$userId = self::getUser($node);

		if (!empty($userId) && self::userHasMusicLib($userId)) {
			if ($node->getType() == FileInfo::TYPE_FILE) {
				$scanner->fileMoved($node, $userId);
			} else {
				$scanner->folderMoved($node, $userId);
			}
		}
	}

	private static function getUser(Node $node) : ?string {
		$userId = self::inject('userId');

		// When a file is uploaded to a folder shared by link, we end up here without current user.
		// In that case, fall back to using file owner
		if (empty($userId)) {
			// At least some versions of NC may violate their PhpDoc and return null owner, hence we need to aid PHPStan a bit about the type.
			/** @var \OCP\IUser|null $owner */
			$owner = $node->getOwner();
			$userId = $owner ? $owner->getUID() : null;
		}

		return $userId;
	}

	/**
	 * Get the dependency identified by the given name
	 * @return mixed
	 */
	private static function inject(string $id) {
		$app = \OC::$server->query(Application::class);
		return $app->get($id);
	}

	/**
	 * Check if user has any scanned tracks in his/her music library
	 */
	private static function userHasMusicLib(string $userId) : bool {
		$trackBusinessLayer = self::inject(TrackBusinessLayer::class);
		return 0 < $trackBusinessLayer->count($userId);
	}

	private static function postRenamed(Node $source, Node $target) : void {
		// Beware: the $source describes the past state of the file and some of its functions will throw upon calling

		if ($source->getParent()->getId() != $target->getParent()->getId()) {
			self::moved($target);
		} else {
			self::updated($target);
		}
	}

	private static function safeExecute(callable $func) : void {
		// Don't let any exceptions or errors leak out of this method, no matter what unforeseen oddities happen.
		// We never want to prevent the actual file operation since our reactions to them are anyway non-crucial.
		// Especially during a server version update involving also Music app version update, the system may be
		// running a partially updated application version and that may lead to unexpected fatal errors, see
		// https://github.com/nc-music/oc-music/issues/1231.
		try {
			try {
				$func();
			} catch (\Throwable $error) {
				$logger = self::inject(Logger::class);
				$logger->error("Error occurred while executing Music app file hook: {$error->getMessage()}. Stack trace: {$error->getTraceAsString()}");
			}
		} catch (\Throwable $error) {
			// even logging the error failed so just ignore
		}
	}

	public static function safeUpdated(Node $node) : void {
		self::safeExecute(fn() => self::updated($node));
	}

	public static function safeDeleted(Node $node) : void {
		self::safeExecute(fn() => self::deleted($node));
	}

	public static function safePostRenamed(Node $source, Node $target) : void {
		self::safeExecute(fn() => self::postRenamed($source, $target));
	}

	public function register() : void {
		$this->filesystemRoot->listen('\OC\Files', 'postWrite', [__CLASS__, 'safeUpdated']);
		$this->filesystemRoot->listen('\OC\Files', 'preDelete', [__CLASS__, 'safeDeleted']);
		$this->filesystemRoot->listen('\OC\Files', 'postRename', [__CLASS__, 'safePostRenamed']);
	}
}
