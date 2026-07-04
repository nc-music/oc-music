<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2017 - 2025
 */

namespace OCA\Music\Db;

use OCP\IDBConnection;

use OCA\Music\AppFramework\Db\UniqueConstraintViolationException;

class Cache {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param string $data
	 */
	public function add(string $userId, string $key, string $data) : void {
		$sql = 'INSERT INTO `*PREFIX*music_cache`
				(`user_id`, `key`, `data`) VALUES (?, ?, ?)';
		$this->executeUpdate($sql, [$userId, $key, $data]);
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param string $data
	 */
	public function update(string $userId, string $key, string $data) : void {
		$sql = 'UPDATE `*PREFIX*music_cache` SET `data` = ?
				WHERE `user_id` = ? AND `key` = ?';
		$this->executeUpdate($sql, [$data, $userId, $key]);
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param string $data
	 */
	public function set(string $userId, string $key, string $data) : void {
		try {
			$this->add($userId, $key, $data);
		} catch (UniqueConstraintViolationException $e) {
			$this->update($userId, $key, $data);
		}
	}

	/**
	 * Remove one or several key-value pairs
	 *
	 * @param string $userId User to target, omit to target all users
	 * @param string $key Key to target, omit to target all keys
	 */
	public function remove(?string $userId = null, ?string $key = null) : void {
		$sql = 'DELETE FROM `*PREFIX*music_cache`';
		$params = [];
		if ($userId !== null) {
			$sql .= ' WHERE `user_id` = ?';
			$params[] = $userId;

			if ($key !== null) {
				$sql .= ' AND `key` = ?';
				$params[] = $key;
			}
		} elseif ($key !== null) {
			$sql .= ' WHERE `key` = ?';
			$params[] = $key;
		}
		$this->executeUpdate($sql, $params);
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @return string|null
	 */
	public function get(string $userId, string $key) : ?string {
		$sql = 'SELECT `data` FROM `*PREFIX*music_cache`
				WHERE `user_id` = ? AND `key` = ?';
		$result = $this->db->executeQuery($sql, [$userId, $key]);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return \count($rows) ? $rows[0]['data'] : null;
	}

	/**
	 * Get all key-value pairs of one user, optionally limiting to keys with a given prefix.
	 * @param string $userId
	 * @param string|null $prefix
	 * @return array of arrays with keys 'key', 'data'
	 */
	public function getAll(string $userId, ?string $prefix = null) : array {
		$sql = 'SELECT `key`, `data` FROM `*PREFIX*music_cache`
				WHERE `user_id` = ?';
		$params = [$userId];

		if (!empty($prefix)) {
			$sql .= ' AND `key` LIKE ?';
			$params[] = $prefix . '%';
		}

		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Given a cache key and its exact content, return the owning user
	 */
	public function getOwner(string $key, string $data) : ?string {
		$sql = 'SELECT `user_id` FROM `*PREFIX*music_cache`
				WHERE `key` = ? AND `data` = ?';
		$result = $this->db->executeQuery($sql, [$key, $data]);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return \count($rows) ? $rows[0]['user_id'] : null;
	}

	private function executeUpdate(string $sql, array $params) : int {
		try {
			return $this->db->executeUpdate($sql, $params);
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
			throw new UniqueConstraintViolationException($e->getMessage(), $e->getCode(), $e);
		} catch (\OCP\DB\Exception $e) {
			// Nextcloud 21
			if ($e->getReason() == \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new UniqueConstraintViolationException($e->getMessage(), $e->getCode(), $e);
			} else {
				throw $e;
			}
		}
	}
}
