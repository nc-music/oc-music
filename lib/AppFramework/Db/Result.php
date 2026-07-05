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

namespace OCA\Music\AppFramework\Db;

/**
 * A wrapper for the DBAL result object, which provides a unified interface for the different cloud versions.
 */
class Result {

	/** @var mixed */
	private $impl;

	/**
	 * @param mixed $inner the database statement object (depending on the cloud version, this can be
	 * 				\Doctrine\DBAL\Driver\Statement, \Doctrine\DBAL\Statement, \OCP\DB\IPreparedStatement,
	 * 				\Doctrine\DBAL\Result, or \OCP\DB\IResult)
	 */
	public function __construct($inner) {
		$this->impl = $inner;
	}

	public function closeCursor() : void
	{
		if (method_exists($this->impl, 'closeCursor')) {
			$this->impl->closeCursor();
		} else if (method_exists($this->impl, 'free')) {
			$this->impl->free();
		} else {
			throw new \RuntimeException('Result object does not support closeCursor() or free()');
		}
	}

	public function columnCount() : int
	{
		return $this->impl->columnCount();
	}

	/** @return mixed|mixed[]|false */
	public function fetch(int $fetchStyle = \PDO::FETCH_ASSOC)
	{
		return $this->impl->fetch($fetchStyle);
	}

	public function fetchAll(int $fetchStyle = \PDO::FETCH_ASSOC) : array
	{
		return $this->impl->fetchAll($fetchStyle);
	}

	/** @return mixed|false */
	public function fetchOne(int $columnIndex = 0)
	{
		if (method_exists($this->impl, 'fetchOne')) {
			return $this->impl->fetchOne();
		} else if (method_exists($this->impl, 'fetchColumn')) {
			return $this->impl->fetchColumn($columnIndex);
		} else {
			throw new \RuntimeException('Result object does not support fetchOne() or fetchColumn()');
		}
	}

	public function rowCount() : int
	{
		return $this->impl->rowCount();
	}
}