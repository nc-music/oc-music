<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Utility;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Cache;

class Concurrency {
	private const SEMAPHORE_KEY_BASE = 0xa5e63947; // arbitrarily selected 32-bit base value

	private Cache $cache;
	private Logger $logger;

	public function __construct(Cache $cache, Logger $logger) {
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/** @return mixed - false|\SysvSemaphore on PHP8.0+, false|resource on older versions */
	public function mutexReserve(string $userId, string $key) {
		if (!\extension_loaded('sysvsem')) {
			$this->logger->warning('PHP extension sysvsem should be installed to guarantee correct behavior');
			return false;
		}

		$mutexKey = self::SEMAPHORE_KEY_BASE + $this->cache->forcedGetId($userId, "mutex_key.$key");
		$mutex = \sem_get($mutexKey);

		if ($mutex !== false) {
			\sem_acquire($mutex);
		} else {
			$this->logger->warning('Failed to acquire the semaphore');
		}

		return $mutex;
	}

	/** @param mixed $mutex - false|\SysvSemaphore on PHP8.0+, false|resource on older versions*/
	public function mutexRelease($mutex) : void {
		if ($mutex !== false) {
			\sem_release($mutex);
		}
	}
}