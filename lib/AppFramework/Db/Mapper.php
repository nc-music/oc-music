<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @copyright Copyright (c) 2023 - 2025, Pauli Järvinen
 *
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Music\AppFramework\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;

/**
 * The base class OCP\AppFramework\Db\Mapper is no longer shipped by NC26+.
 * This is a slightly modified copy of that class on NC25, the modifications are just stylistic.
 * OwnCloud still ships the platform version of the class and it's almost identical to this one;
 * the difference is just that the OC version still accepts also IDb type of handle in the constructor.
 * However, IDBConnection has been available since OC 8.1 and that's what we always use.
 * We use this copy of ours both on NC and OC.
 *
 * @template EntityType of Entity
 * @phpstan-property class-string<EntityType> $entityClass
 */
abstract class Mapper {
	protected string $tableName;
	protected string $entityClass;
	protected IDBConnection $db;

	/**
	 * @param IDBConnection $db Instance of the Db abstraction layer
	 * @param string $tableName the name of the table. set this to allow entity
	 * @param ?string $entityClass the name of the entity that the sql should be mapped to queries without using sql
	 * @phpstan-param class-string<EntityType> $entityClass
	 * @since 7.0.0
	 */
	public function __construct(IDBConnection $db, $tableName, $entityClass=null) {
		$this->db = $db;
		$this->tableName = '*PREFIX*' . $tableName;

		// if not given set the entity name to the class without the mapper part
		// cache it here for later use since reflection is slow
		if ($entityClass === null) {
			$this->entityClass = \str_replace('Mapper', '', \get_class($this));
		} else {
			$this->entityClass = $entityClass;
		}
	}

	/**
	 * @return string the table name
	 * @since 7.0.0
	 */
	public function getTableName() {
		return $this->tableName;
	}

	/**
	 * Deletes an entity from the table
	 * @param Entity $entity the entity that should be deleted
	 * @return Entity the deleted entity
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 * @since 7.0.0 - return value added in 8.1.0
	 */
	public function delete(Entity $entity) {
		$sql = 'DELETE FROM `' . $this->tableName . '` WHERE `id` = ?';
		$stmt = $this->execute($sql, [$entity->getId()]);
		$stmt->free();
		return $entity;
	}

	/**
	 * Creates a new entry in the db from an entity
	 * @param Entity $entity the entity that should be created
	 * @return Entity the saved entity with the set id
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 * @since 7.0.0
	 */
	public function insert(Entity $entity) {
		// get updated fields to save, fields have to be set using a setter to
		// be saved
		$properties = $entity->getUpdatedFields();
		$values = '';
		$columns = '';
		$params = [];

		// build the fields
		$i = 0;
		foreach ($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			$getter = 'get' . \ucfirst($property);

			$columns .= '`' . $column . '`';
			$values .= '?';

			// only append colon if there are more entries
			if ($i < \count($properties)-1) {
				$columns .= ',';
				$values .= ',';
			}

			$params[] = $entity->$getter();
			$i++;
		}

		$sql = 'INSERT INTO `' . $this->tableName . '`(' .
				$columns . ') VALUES(' . $values . ')';

		$stmt = $this->execute($sql, $params);

		$entity->setId((int) $this->db->lastInsertId($this->tableName));

		$stmt->free();

		return $entity;
	}

	/**
	 * Updates an entry in the db from an entity
	 * @throws \InvalidArgumentException if entity has no id
	 * @param Entity $entity the entity that should be created
	 * @return Entity the saved entity with the set id
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 * @since 7.0.0 - return value was added in 8.0.0
	 */
	public function update(Entity $entity) {
		// if entity wasn't changed it makes no sense to run a db query
		$properties = $entity->getUpdatedFields();
		if (\count($properties) === 0) {
			return $entity;
		}

		// entity needs an id
		$id = $entity->getId();
		if ($id === null) {
			throw new \InvalidArgumentException(
				'Entity which should be updated has no id'
			);
		}

		// get updated fields to save, fields have to be set using a setter to
		// be saved
		// do not update the id field
		unset($properties['id']);

		$columns = '';
		$params = [];

		// build the fields
		$i = 0;
		foreach ($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			$getter = 'get' . \ucfirst($property);

			$columns .= '`' . $column . '` = ?';

			// only append colon if there are more entries
			if ($i < \count($properties)-1) {
				$columns .= ',';
			}

			$params[] = $entity->$getter();
			$i++;
		}

		$sql = 'UPDATE `' . $this->tableName . '` SET ' .
				$columns . ' WHERE `id` = ?';
		$params[] = $id;

		$stmt = $this->execute($sql, $params);
		$stmt->free();

		return $entity;
	}

	/**
	 * Checks if an array is associative
	 * @param array $array
	 * @return bool true if associative
	 * @since 8.1.0
	 */
	private function isAssocArray(array $array) {
		return \array_values($array) !== $array;
	}

	/**
	 * Returns the correct PDO constant based on the value type
	 * @param mixed $value
	 * @return int PDO constant
	 * @since 8.1.0
	 */
	private function getPDOType($value) {
		switch (\gettype($value)) {
			case 'integer':
				return \PDO::PARAM_INT;
			case 'boolean':
				return \PDO::PARAM_BOOL;
			default:
				return \PDO::PARAM_STR;
		}
	}

	/**
	 * Runs an sql query
	 * @param string $sql the prepare string
	 * @param array $params the params which should replace the ? in the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @return mixed the database query result (depending on the Doctrine DBAL version, this can be either a Statement or a Result)
	 * @since 7.0.0
	 */
	protected function execute($sql, array $params=[], $limit=null, $offset=null) {
		$query = $this->db->prepare($sql, $limit, $offset);

		if ($this->isAssocArray($params)) {
			foreach ($params as $key => $param) {
				$pdoConstant = $this->getPDOType($param);
				$query->bindValue($key, $param, $pdoConstant);
			}
		} else {
			$index = 1;  // bindParam is 1 indexed
			foreach ($params as $param) {
				$pdoConstant = $this->getPDOType($param);
				$query->bindValue($index, $param, $pdoConstant);
				$index++;
			}
		}

		$result = $query->execute();
		return \is_bool($result) ? $query : $result;
	}

	/**
	 * Returns an db result and throws exceptions when there are more or less
	 * results
	 * @see findEntity
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException if more than one item exist
	 * @return array the result as row
	 * @since 7.0.0
	 */
	protected function findOneQuery($sql, array $params=[], $limit=null, $offset=null) {
		$stmt = $this->execute($sql, $params, $limit, $offset);
		$row = $stmt->fetch();

		if ($row === false || $row === null) {
			$stmt->free();
			$msg = $this->buildDebugMessage(
				'Did expect one result but found none when executing',
				$sql,
				$params,
				$limit,
				$offset
			);
			throw new DoesNotExistException($msg);
		}
		$row2 = $stmt->fetch();
		$stmt->free();
		//MDB2 returns null, PDO and doctrine false when no row is available
		if (! ($row2 === false || $row2 === null)) {
			$msg = $this->buildDebugMessage(
				'Did not expect more than one result when executing',
				$sql,
				$params,
				$limit,
				$offset
			);
			throw new MultipleObjectsReturnedException($msg);
		} else {
			return $row;
		}
	}

	/**
	 * Builds an error message by prepending the $msg to an error message which
	 * has the parameters
	 * @see findEntity
	 * @param string $msg
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @return string formatted error message string
	 * @since 9.1.0
	 */
	private function buildDebugMessage($msg, $sql, array $params=[], $limit=null, $offset=null) {
		return $msg .
					': query "' .	$sql . '"; ' .
					'parameters ' . \print_r($params, true) . '; ' .
					'limit "' . $limit . '"; '.
					'offset "' . $offset . '"';
	}

	/**
	 * Creates an entity from a row. Automatically determines the entity class
	 * from the current mapper name (MyEntityMapper -> MyEntity)
	 * @param array $row the row which should be converted to an entity
	 * @return Entity the entity
	 * @phpstan-return EntityType
	 * @since 7.0.0
	 */
	protected function mapRowToEntity($row) {
		return \call_user_func($this->entityClass .'::fromRow', $row);
	}

	/**
	 * Runs a sql query and returns an array of entities
	 * @param string $sql the prepare string
	 * @param array $params the params which should replace the ? in the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @return Entity[] all fetched entities
	 * @phpstan-return EntityType[]
	 * @since 7.0.0
	 */
	protected function findEntities($sql, array $params=[], $limit=null, $offset=null) {
		$stmt = $this->execute($sql, $params, $limit, $offset);

		$entities = [];

		while ($row = $stmt->fetch()) {
			$entities[] = $this->mapRowToEntity($row);
		}

		$stmt->free();

		return $entities;
	}

	/**
	 * Returns an db result and throws exceptions when there are more or less
	 * results
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException if more than one item exist
	 * @return Entity the entity
	 * @phpstan-return EntityType
	 * @since 7.0.0
	 */
	protected function findEntity($sql, array $params=[], $limit=null, $offset=null) {
		return $this->mapRowToEntity($this->findOneQuery($sql, $params, $limit, $offset));
	}
}
