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
 * @copyright Pauli Järvinen 2020 - 2025
 */

namespace OCA\Music\Db;

use OCP\IDBConnection;

use OCA\Music\AppFramework\Db\Mapper;

/**
 * @method AmpacheSession findEntity(string $sql, array $params)
 * @extends Mapper<AmpacheSession>
 */
class AmpacheSessionMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_ampache_sessions', AmpacheSession::class);
	}

	/**
	 * @param string $token
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 */
	public function findByToken($token) : AmpacheSession {
		$sql = 'SELECT *
				FROM `*PREFIX*music_ampache_sessions`
				WHERE `token` = ? AND `expiry` > ?';
		$params = [$token, \time()];

		return $this->findEntity($sql, $params);
	}

	public function extend(string $token, int $expiry) : void {
		$sql = 'UPDATE `*PREFIX*music_ampache_sessions`
				SET `expiry` = ?
				WHERE `token` = ?';

		$params = [$expiry, $token];
		$result = $this->execute($sql, $params);
		$result->closeCursor();
	}

	public function cleanUp() : void {
		$sql = 'DELETE FROM `*PREFIX*music_ampache_sessions`
				WHERE `expiry` < ?';
		$params = [\time()];
		$result = $this->execute($sql, $params);
		$result->closeCursor();
	}

	public function revokeSessions(int $ampacheUserId) : void {
		$sql = 'DELETE FROM `*PREFIX*music_ampache_sessions`
				WHERE `ampache_user_id` = ?';
		$params = [$ampacheUserId];
		$result = $this->execute($sql, $params);
		$result->closeCursor();
	}
}
