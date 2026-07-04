<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2016 - 2025
 */

namespace OCA\Music\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IConfig;
use OCP\IDBConnection;

use OCA\Music\AppFramework\Db\Mapper;
use OCA\Music\AppFramework\Db\UniqueConstraintViolationException;
use OCA\Music\Utility\StringUtil;

/**
 * Common base class for data access classes of the Music app
 *
 * @method Entity findEntity(string $sql, array $params)
 * @method Entity[] findEntities(string $sql, array $params, ?int $limit=null, ?int $offset=null)
 * @method Entity delete(Entity $entity)
 *
 * @template EntityType of Entity
 * @extends Mapper<EntityType>
 * @phpstan-method EntityType findEntity(string $sql, array $params)
 * @phpstan-method EntityType[] findEntities(string $sql, array $params, ?int $limit=null, ?int $offset=null)
 * @phpstan-method EntityType delete(EntityType $entity)
 */
abstract class BaseMapper extends Mapper {
	public const SQL_DATE_FORMAT = 'Y-m-d H:i:s.v';

	protected string $nameColumn;
	protected ?array $uniqueColumns;
	protected ?string $parentIdColumn;
	protected string $dbType; // database type 'mysql', 'pgsql', or 'sqlite3'

	/**
	 * @param ?string[] $uniqueColumns List of column names composing the unique constraint of the table. Null if there's no unique index.
	 * @phpstan-param class-string<EntityType> $entityClass
	 */
	public function __construct(
			IDBConnection $db, IConfig $config, string $tableName, string $entityClass,
			string $nameColumn, ?array $uniqueColumns=null, ?string $parentIdColumn=null) {
		parent::__construct($db, $tableName, $entityClass);
		$this->nameColumn = $nameColumn;
		$this->uniqueColumns = $uniqueColumns;
		$this->parentIdColumn = $parentIdColumn;
		$this->dbType = $config->getSystemValue('dbtype');
	}

	/**
	 * Create an empty object of the entity class bound to this mapper
	 * @phpstan-return EntityType
	 */
	public function createEntity() : Entity {
		return new $this->entityClass();
	}

	public function unprefixedTableName() : string {
		return \str_replace('*PREFIX*', '', $this->getTableName());
	}

	/**
	 * Find a single entity by id and user_id
	 * @throws DoesNotExistException if the entity does not exist
	 * @throws MultipleObjectsReturnedException if more than one entity exists
	 * @phpstan-return EntityType
	 */
	public function find(int $id, string $userId) : Entity {
		$sql = $this->selectUserEntities("`{$this->getTableName()}`.`id` = ?");
		return $this->findEntity($sql, [$userId, $id]);
	}

	/**
	 * Find all entities matching the given IDs. Specifying the owning user is optional.
	 * @param integer[] $ids  IDs of the entities to be found
	 * @param string|null $userId
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findById(array $ids, ?string $userId=null) : array {
		$count = \count($ids);
		$condition = "`{$this->getTableName()}`.`id` IN ". $this->questionMarks($count);

		if (empty($userId)) {
			$sql = $this->selectEntities($condition);
		} else {
			$sql = $this->selectUserEntities($condition);
			$ids = \array_merge([$userId], $ids);
		}

		return $this->findEntities($sql, $ids);
	}

	/**
	 * Find all user's entities
	 * @param string|null $createdMin Optional minimum `created` timestamp.
	 * @param string|null $createdMax Optional maximum `created` timestamp.
	 * @param string|null $updatedMin Optional minimum `updated` timestamp.
	 * @param string|null $updatedMax Optional maximum `updated` timestamp.
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAll(string $userId, int $sortBy=SortBy::Name, ?int $limit=null, ?int $offset=null,
							?string $createdMin=null, ?string $createdMax=null, ?string $updatedMin=null, ?string $updatedMax=null) : array {
		$sorting = $this->formatSortingClause($sortBy);
		[$condition, $params] = $this->formatTimestampConditions($createdMin, $createdMax, $updatedMin, $updatedMax);
		$sql = $this->selectUserEntities($condition, $sorting);
		\array_unshift($params, $userId);
		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * Find all user's entities matching the given name
	 * @param string|null $createdMin Optional minimum `created` timestamp.
	 * @param string|null $createdMax Optional maximum `created` timestamp.
	 * @param string|null $updatedMin Optional minimum `updated` timestamp.
	 * @param string|null $updatedMax Optional maximum `updated` timestamp.
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllByName(
		?string $name, string $userId, int $matchMode=MatchMode::Exact, ?int $limit=null, ?int $offset=null,
		?string $createdMin=null, ?string $createdMax=null, ?string $updatedMin=null, ?string $updatedMax=null) : array {

		$params = [$userId];

		[$condition, $nameParams] = $this->formatNameConditions($name, $matchMode);
		$params = \array_merge($params, $nameParams);

		[$timestampConds, $timestampParams] = $this->formatTimestampConditions($createdMin, $createdMax, $updatedMin, $updatedMax);
		if (!empty($timestampConds)) {
			$condition .= ' AND ' . $timestampConds;
			$params = \array_merge($params, $timestampParams);
		}

		$sql = $this->selectUserEntities($condition, $this->formatSortingClause(SortBy::Name));

		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * Find all user's starred entities. It is safe to call this also on entity types
	 * not supporting starring in which case an empty array will be returned.
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllStarred(string $userId, ?int $limit=null, ?int $offset=null) : array {
		if (\property_exists($this->entityClass, 'starred')) {
			$sql = $this->selectUserEntities(
				"`{$this->getTableName()}`.`starred` IS NOT NULL",
				$this->formatSortingClause(SortBy::Name)
			);
			return $this->findEntities($sql, [$userId], $limit, $offset);
		} else {
			return [];
		}
	}

	/**
	 * Find IDSs of all user's starred entities. It is safe to call this also on entity types
	 * not supporting starring in which case an empty array will be returned.
	 * @return int[]
	 */
	public function findAllStarredIds(string $userId) : array {
		if (\property_exists($this->entityClass, 'starred')) {
			$sql = "SELECT `id` FROM `{$this->getTableName()}` WHERE `starred` IS NOT NULL AND `user_id` = ?";
			$result = $this->execute($sql, [$userId]);
			$return = \array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
			$result->closeCursor();
			return $return;
		} else {
			return [];
		}
	}

	/**
	 * Find all entities with user-given rating 1-5
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllRated(string $userId, ?int $limit=null, ?int $offset=null) : array {
		if (\property_exists($this->entityClass, 'rating')) {
			$sql = $this->selectUserEntities(
				"`{$this->getTableName()}`.`rating` > 0",
				$this->formatSortingClause(SortBy::Rating)
			);
			return $this->findEntities($sql, [$userId], $limit, $offset);
		} else {
			return [];
		}
	}

	/**
	 * Find all entities matching multiple criteria, as needed for the Ampache API method `advanced_search`
	 * @param string $conjunction Operator to use between the rules, either 'and' or 'or'
	 * @param array $rules Array of arrays: [['rule' => string, 'operator' => string, 'input' => string], ...]
	 * 				Here, 'rule' has dozens of possible values depending on the business layer in question
	 * 				(see https://ampache.org/api/api-advanced-search#available-search-rules, alias names not supported here),
	 * 				'operator' is one of
	 * 				['contain', 'notcontain', 'start', 'end', 'is', 'isnot', 'sounds', 'notsounds', 'regexp', 'notregexp',
	 * 				 '>=', '<=', '=', '!=', '>', '<', 'before', 'after', 'true', 'false', 'equal', 'ne', 'limit'],
	 * 				'input' is the right side value of the 'operator' (disregarded for the operators 'true' and 'false')
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllAdvanced(string $conjunction, array $rules, string $userId, int $sortBy=SortBy::Name, ?int $limit=null, ?int $offset=null) : array {
		$sqlConditions = [];
		$sqlParams = [$userId];

		foreach ($rules as $rule) {
			list('op' => $sqlOp, 'conv' => $sqlConv, 'param' => $param) = $this->advFormatSqlOperator($rule['operator'], (string)$rule['input'], $userId);
			$cond = $this->advFormatSqlCondition($rule['rule'], $sqlOp, $sqlConv);
			$sqlConditions[] = $cond;
			// On some conditions, the parameter may need to be repeated several times
			$paramCount = \substr_count($cond, '?');
			for ($i = 0; $i < $paramCount; ++$i) {
				$sqlParams[] = $param;
			}
		}
		$sqlConditions = \implode(" $conjunction ", $sqlConditions);

		$sql = $this->selectUserEntities($sqlConditions, $this->formatSortingClause($sortBy));
		return $this->findEntities($sql, $sqlParams, $limit, $offset);
	}

	/**
	 * Optionally, limit to given IDs which may be used to check the validity of those IDs.
	 * @return int[]
	 */
	public function findAllIds(string $userId, ?array $ids = null) : array {
		$sql = "SELECT `id` FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$params = [$userId];

		if ($ids !== null) {
			$sql .= ' AND `id` IN ' . $this->questionMarks(\count($ids));
			$params = \array_merge($params, $ids);
		}

		$result = $this->execute($sql, $params);

		$return = \array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $return;
	}

	/**
	 * Find all entity IDs grouped by the given parent entity IDs. Not applicable on all entity types.
	 * @param int[] $parentIds
	 * @return array<int, int[]> like [parentId => childIds[]]; some parents may have an empty array of children
	 * @throws \DomainException if the entity type handled by this mapper doesn't have a parent relation
	 */
	public function findAllIdsByParentIds(string $userId, array $parentIds) : ?array {
		if ($this->parentIdColumn === null) {
			throw new \DomainException("Finding by parent is not applicable for the table {$this->getTableName()}");
		}

		$return = [];
		if (\count($parentIds) > 0) {
			$sql = "SELECT `id`, `{$this->parentIdColumn}` AS `parent_id` FROM `{$this->getTableName()}`
					WHERE `user_id` = ? AND `{$this->parentIdColumn}` IN " . $this->questionMarks(\count($parentIds));
			$params = \array_merge([$userId], $parentIds);
			$result = $this->execute($sql, $params);
			$rows = $result->fetchAll();
			$result->closeCursor();

			// ensure that the result contains also "parents" with no children and has the same order as $parentIds
			$return = \array_fill_keys($parentIds, []);
			foreach ($rows as $row) {
				$return[(int)$row['parent_id']][] = (int)$row['id'];
			}
		}

		return $return;
	}

	/**
	 * Find all IDs and names of user's entities of this kind.
	 * Optionally, limit results based on a parent entity (not applicable for all entity types) or update/insert times or name
	 * @param bool $excludeChildless Exclude entities having no child-entities if applicable for this business layer (eg. artists without albums)
	 * @return array of arrays like ['id' => string, 'name' => ?string]
	 */
	public function findAllIdsAndNames(string $userId, ?int $parentId, ?int $limit=null, ?int $offset=null,
			?string $createdMin=null, ?string $createdMax=null, ?string $updatedMin=null, ?string $updatedMax=null,
			bool $excludeChildless=false, ?string $name=null) : array {
		$sql = "SELECT `id`, `{$this->nameColumn}` AS `name` FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$params = [$userId];
		if ($parentId !== null) {
			if ($this->parentIdColumn === null) {
				throw new \DomainException("The parentId filtering is not applicable for the table {$this->getTableName()}");
			} else {
				$sql .= " AND {$this->parentIdColumn} = ?";
				$params[] = $parentId;
			}
		}

		[$timestampConds, $timestampParams] = $this->formatTimestampConditions($createdMin, $createdMax, $updatedMin, $updatedMax);
		if (!empty($timestampConds)) {
			$sql .= " AND $timestampConds";
			$params = \array_merge($params, $timestampParams);
		}

		if ($excludeChildless) {
			$sql .= ' AND ' . $this->formatExcludeChildlessCondition();
		}

		if (!empty($name)) {
			[$nameCond, $nameParams] = $this->formatNameConditions($name, MatchMode::Substring);
			$sql .= " AND $nameCond";
			$params = \array_merge($params, $nameParams);
		}

		$sql .= ' ' . $this->formatSortingClause(SortBy::Name);

		if ($limit !== null) {
			$sql .= ' LIMIT ?';
			$params[] = $limit;
		}
		if ($offset !== null) {
			$sql .= ' OFFSET ?';
			$params[] = $offset;
		}

		$result = $this->execute($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Find IDs of all users owning any entities of this mapper
	 * @return string[]
	 */
	public function findAllUsers() : array {
		$sql = "SELECT DISTINCT(`user_id`) FROM `{$this->getTableName()}`";
		$result = $this->execute($sql);
		$rows = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Delete all entities with given IDs without specifying the user
	 * @param integer[] $ids  IDs of the entities to be deleted
	 */
	public function deleteById(array $ids) : void {
		$count = \count($ids);
		if ($count === 0) {
			return;
		}
		$this->deleteByCond('`id` IN ' . $this->questionMarks($count), $ids);
	}

	/**
	 * Delete all entities matching the given SQL condition
	 * @param string $condition SQL 'WHERE' condition (without the keyword 'WHERE')
	 * @param array $params SQL parameters for the condition
	 */
	protected function deleteByCond(string $condition, array $params) : void {
		$sql = "DELETE FROM `{$this->getTableName()}` WHERE ". $condition;
		$result = $this->execute($sql, $params);
		$result->closeCursor();
	}

	/**
	 * Delete all entities of the given user
	 */
	public function deleteAll(string $userId) : void {
		$sql = "DELETE FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$result = $this->execute($sql, [$userId]);
		$result->closeCursor();
	}

	/**
	 * Tests if entity with given ID and user ID exists in the database
	 */
	public function exists(int $id, string $userId) : bool {
		$sql = "SELECT 1 FROM `{$this->getTableName()}` WHERE `id` = ? AND `user_id` = ?";
		$result = $this->execute($sql, [$id, $userId]);
		$row = $result->fetch();
		$result->closeCursor();
		return (bool)$row;
	}

	/**
	 * Count all entities of a user
	 */
	public function count(string $userId) : int {
		$sql = "SELECT COUNT(*) AS count FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$result = $this->execute($sql, [$userId]);
		$row = $result->fetch();
		$result->closeCursor();
		return \intval($row['count']);
	}

	/**
	 * Get the largest entity ID of the user
	 */
	public function maxId(string $userId) : ?int {
		$sql = "SELECT MAX(`id`) AS max_id FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$result = $this->execute($sql, [$userId]);
		$row = $result->fetch();
		$result->closeCursor();
		$max = $row['max_id'];
		return $max === null ? null : (int)$max;
	}

	/**
	 * {@inheritDoc}
	 * @see Mapper::insert()
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 */
	public function insert(\OCP\AppFramework\Db\Entity $entity) : Entity {
		if (!($entity instanceof Entity)) {
			// Because of Liskov Substitution Principle, this class must technically accept any platform Entity.
			// However, the function only works correctly for our own Entity type. The return type can be narrowed
			// from the parent, thanks to the covariance rules of PHP 7.4 and later.
			throw new \BadMethodCallException('$entity must be of type ' . Entity::class);
		}

		$now = new \DateTime();
		$nowStr = $now->format(self::SQL_DATE_FORMAT);
		$entity->setCreated($nowStr);
		$entity->setUpdated($nowStr);

		try {
			return parent::insert($entity);
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
			throw new UniqueConstraintViolationException($e->getMessage(), $e->getCode(), $e);
		} catch (\OCP\DB\Exception $e) {
			// Nextcloud 21+
			if ($e->getReason() == \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new UniqueConstraintViolationException($e->getMessage(), $e->getCode(), $e);
			} else {
				throw $e;
			}
		}
	}

	/**
	 * {@inheritDoc}
	 * @see Mapper::update()
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 */
	public function update(\OCP\AppFramework\Db\Entity $entity) : Entity {
		if (!($entity instanceof Entity)) {
			// Because of Liskov Substitution Principle, this class must technically accept any platform Entity.
			// However, the function only works correctly for our own Entity type. The return type can be narrowed
			// from the parent, thanks to the covariance rules of PHP 7.4 and later.
			throw new \BadMethodCallException('$entity must be of type ' . Entity::class);
		}

		$now = new \DateTime();
		$entity->setUpdated($now->format(self::SQL_DATE_FORMAT));
		return parent::update($entity);
	}

	/**
	 * Insert an entity, or if an entity with the same identity already exists,
	 * update the existing entity.
	 * Note: The functions insertOrUpdate and updateOrInsert get the exactly same thing done. The only difference is
	 * that the former is optimized for cases where the entity doesn't exist and the latter for cases where it does exist.
	 * @return Entity The inserted or updated entity, containing also the id field
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 */
	public function insertOrUpdate(Entity $entity) : Entity {
		try {
			return $this->insert($entity);
		} catch (UniqueConstraintViolationException $ex) {
			$existingId = $this->findIdOfConflict($entity);
			$entity->setId($existingId);
			// The previous call to $this->insert has set the `created` property of $entity.
			// Set it again using the data from the existing entry.
			$entity->setCreated($this->getCreated($existingId));
			return $this->update($entity);
		}
	}

	/**
	 * Update an entity whose unique constraint fields match the given entity. If such entity is not found,
	 * a new entity is inserted.
	 * Note: The functions insertOrUpdate and updateOrInsert get the exactly same thing done. The only difference is
	 * that the former is optimized for cases where the entity doesn't exist and the latter for cases where it does exist.
	 * @param Entity $entity
	 * @return Entity The inserted or updated entity, containing also the id field
	 * @phpstan-param EntityType $entity
	 * @phpstan-return EntityType
	 */
	public function updateOrInsert(Entity $entity) : Entity {
		try {
			$existingId = $this->findIdOfConflict($entity);
			$entity->setId($existingId);
			return $this->update($entity);
		} catch (DoesNotExistException $ex) {
			return $this->insertOrUpdate($entity);
		}
	}

	/**
	 * Set the "starred" column of the given entities
	 * @param \DateTime|null $date
	 * @param integer[] $ids
	 * @param string $userId
	 * @return int number of modified entities
	 */
	public function setStarredDate(?\DateTime $date, array $ids, string $userId) : int {
		$count = \count($ids);
		if (!empty($date)) {
			$date = $date->format(self::SQL_DATE_FORMAT);
		}

		$sql = "UPDATE `{$this->getTableName()}` SET `starred` = ?
				WHERE `id` IN {$this->questionMarks($count)} AND `user_id` = ?";
		$params = \array_merge([$date], $ids, [$userId]);
		$result = $this->execute($sql, $params);
		$modCount = $result->rowCount();
		$result->closeCursor();

		return $modCount;
	}

	public function latestInsertTime(string $userId) : ?\DateTime {
		$sql = "SELECT MAX(`{$this->getTableName()}`.`created`) FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$result = $this->execute($sql, [$userId]);
		$createdTime = $result->fetch(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		return ($createdTime === null) ? null : new \DateTime($createdTime);
	}

	public function latestUpdateTime(string $userId) : ?\DateTime {
		$sql = "SELECT MAX(`{$this->getTableName()}`.`updated`) FROM `{$this->getTableName()}` WHERE `user_id` = ?";
		$result = $this->execute($sql, [$userId]);
		$createdTime = $result->fetch(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		return ($createdTime === null) ? null : new \DateTime($createdTime);
	}

	/**
	 * helper creating a string like '(?,?,?)' with the specified number of elements
	 */
	protected function questionMarks(int $count) : string {
		$questionMarks = [];
		for ($i = 0; $i < $count; $i++) {
			$questionMarks[] = '?';
		}
		return '(' . \implode(',', $questionMarks) . ')';
	}

	/**
	 * Build a SQL SELECT statement which selects all entities of the given user,
	 * and optionally applies other conditions, too.
	 * This is built upon `selectEntities` which may be overridden by the derived class.
	 * @param string|null $condition Optional extra condition. This will get automatically
	 *                               prefixed with ' AND ', so don't include that.
	 * @param string|null $extension Any extension (e.g. ORDER BY, LIMIT) to be added after
	 *                               the conditions in the SQL statement
	 */
	protected function selectUserEntities(?string $condition=null, ?string $extension=null) : string {
		$allConditions = "`{$this->getTableName()}`.`user_id` = ?";

		if (!empty($condition)) {
			$allConditions .= " AND ($condition)";
		}

		return $this->selectEntities($allConditions, $extension);
	}

	/**
	 * Build a SQL SELECT statement which selects all entities matching the given condition.
	 * The derived class may override this if necessary.
	 * @param string $condition This will get automatically prefixed with ' WHERE '
	 * @param string|null $extension Any extension (e.g. ORDER BY, LIMIT) to be added after
	 *                               the conditions in the SQL statement
	 */
	protected function selectEntities(string $condition, ?string $extension=null) : string {
		return "SELECT * FROM `{$this->getTableName()}` WHERE $condition $extension ";
	}

	/**
	 * @return array with two values: The SQL condition as string and the SQL parameters as string[]
	 */
	protected function formatNameConditions(?string $name, int $matchMode) : array {
		$params = [];
		$nameCol = "`{$this->getTableName()}`.`{$this->nameColumn}`";
		if ($name === null) {
			$condition = "$nameCol IS NULL";
		} else {
			if ($matchMode === MatchMode::Exact) {
				$condition = "LOWER($nameCol) = LOWER(?)";
			} else {
				$condition = "LOWER($nameCol) LIKE LOWER(?)";
			}
			if ($matchMode === MatchMode::Substring) {
				$params[] = self::prepareSubstringSearchPattern($name);
			} else {
				$params[] = $name;
			}
		}
		return [$condition, $params];
	}

	/**
	 * @return array with two values: The SQL condition as string and the SQL parameters as string[]
	 */
	protected function formatTimestampConditions(?string $createdMin, ?string $createdMax, ?string $updatedMin, ?string $updatedMax) : array {
		$conditions = [];
		$params = [];

		if (!empty($createdMin)) {
			$conditions[] = "`{$this->getTableName()}`.`created` >= ?";
			$params[] = $createdMin;
		}

		if (!empty($createdMax)) {
			$conditions[] = "`{$this->getTableName()}`.`created` <= ?";
			$params[] = $createdMax;
		}

		if (!empty($updatedMin)) {
			$conditions[] = "`{$this->getTableName()}`.`updated` >= ?";
			$params[] = $updatedMin;
		}

		if (!empty($updatedMax)) {
			$conditions[] = "`{$this->getTableName()}`.`updated` <= ?";
			$params[] = $updatedMax;
		}

		return [\implode(' AND ', $conditions), $params];
	}

	/**
	 * Convert given sorting condition to an SQL clause. Derived class may override this if necessary.
	 * @param int $sortBy One of the constants defined in the class SortBy
	 */
	protected function formatSortingClause(int $sortBy, bool $invertSort = false) : ?string {
		$table = $this->getTableName();
		if ($sortBy == SortBy::Name) {
			$dir = $invertSort ? 'DESC' : 'ASC';
			return "ORDER BY LOWER(`$table`.`{$this->nameColumn}`) $dir";
		} elseif ($sortBy == SortBy::Newest) {
			$dir = $invertSort ? 'ASC' : 'DESC';
			return "ORDER BY `$table`.`id` $dir"; // abuse the fact that IDs are ever-incrementing values
		} elseif ($sortBy == SortBy::Rating) {
			if (\property_exists($this->entityClass, 'rating')) {
				$dir = $invertSort ? 'ASC' : 'DESC';
				return "ORDER BY `$table`.`rating` $dir";
			} else {
				return null;
			}
		} else {
			return null;
		}
	}

	/**
	 * Return an SQL condition to exclude entities having no children. The default implementation is empty
	 * and derived classes may override this if applicable.
	 */
	protected function formatExcludeChildlessCondition() : string {
		return '1=1';
	}

	protected static function prepareSubstringSearchPattern(string $input) : string {
		// possibly multiparted query enclosed in quotation marks is handled as a single substring,
		// while the default interpretation of multipart string is that each of the parts can be found
		// separately as substring in the given order
		if (StringUtil::startsWith($input, '"') && StringUtil::endsWith($input, '"')) {
			// remove the quotation
			$pattern = \substr($input, 1, -1);
		} else {
			// split to parts by whitespace
			$parts = \preg_split('/\s+/', $input, -1, PREG_SPLIT_NO_EMPTY);
			// glue the parts back together with a wildcard character
			$pattern = \implode('%', $parts);
		}
		return "%$pattern%";
	}

	/**
	 * Format SQL operator, conversion, and parameter matching the given advanced search operator.
	 * @return array{op: string, conv: string, param: string|int|null}
	 */
	protected function advFormatSqlOperator(string $ruleOperator, string $ruleInput, string $userId) {
		if ($this->dbType == 'sqlite3' && ($ruleOperator == 'regexp' || $ruleOperator == 'notregexp')) {
			$this->registerRegexpFuncForSqlite();
		}

		$pgsql = ($this->dbType == 'pgsql');

		switch ($ruleOperator) {
			case 'contain':		return ['op' => 'LIKE',									'conv' => 'LOWER',		'param' => "%$ruleInput%"];
			case 'notcontain':	return ['op' => 'NOT LIKE',								'conv' => 'LOWER',		'param' => "%$ruleInput%"];
			case 'start':		return ['op' => 'LIKE',									'conv' => 'LOWER',		'param' => "$ruleInput%"];
			case 'end':			return ['op' => 'LIKE',									'conv' => 'LOWER',		'param' => "%$ruleInput"];
			case 'is':			return ['op' => '=',									'conv' => 'LOWER',		'param' => "$ruleInput"];
			case 'isnot':		return ['op' => '!=',									'conv' => 'LOWER',		'param' => "$ruleInput"];
			case 'sounds':		return ['op' => '=',									'conv' => 'SOUNDEX',	'param' => $ruleInput]; // requires extension `fuzzystrmatch` on PgSQL
			case 'notsounds':	return ['op' => '!=',									'conv' => 'SOUNDEX',	'param' => $ruleInput]; // requires extension `fuzzystrmatch` on PgSQL
			case 'regexp':		return ['op' => $pgsql ? '~' : 'REGEXP',				'conv' => 'LOWER',		'param' => $ruleInput];
			case 'notregexp':	return ['op' => $pgsql ? '!~' : 'NOT REGEXP',			'conv' => 'LOWER',		'param' => $ruleInput];
			case 'true':		return ['op' => 'IS NOT NULL',							'conv' => '',			'param' => null];
			case 'false':		return ['op' => 'IS NULL',								'conv' => '',			'param' => null];
			case 'equal':		return ['op' => '',										'conv' => '',			'param' => $ruleInput];
			case 'ne':			return ['op' => 'NOT',									'conv' => '',			'param' => $ruleInput];
			case 'limit':		return ['op' => (string)(int)$ruleInput,				'conv' => '',			'param' => $userId]; // this is a bit hacky, userId needs to be passed as an SQL param while simple sanitation suffices for the limit
			case 'before':		return ['op' => '<',									'conv' => '',			'param' => $ruleInput];
			case 'after':		return ['op' => '>',									'conv' => '',			'param' => $ruleInput];
			default:			return ['op' => self::sanitizeNumericOp($ruleOperator),	'conv' => '',			'param' => (int)$ruleInput]; // all numerical operators fall here
		}
	}

	protected static function sanitizeNumericOp(string $comparisonOperator) : string {
		if (\in_array($comparisonOperator, ['>=', '<=', '=', '!=', '>', '<'])) {
			return $comparisonOperator;
		} else {
			throw new \DomainException("Bad advanced search operator: $comparisonOperator");
		}
	}

	/**
	 * Format SQL condition matching the given advanced search rule and SQL operator.
	 * Derived classes should override this to provide support for table-specific rules.
	 * @param string $rule	Identifier of the property which is the target of the SQL condition. The identifiers match the Ampache API specification.
	 * @param string $sqlOp	SQL (comparison) operator to be used
	 * @param string $conv	SQL conversion function to be applied on the target column and the parameter (e.g. "LOWER")
	 * @return string SQL condition statement to be used in the "WHERE" clause
	 */
	protected function advFormatSqlCondition(string $rule, string $sqlOp, string $conv) : string {
		$table = $this->getTableName();
		$nameCol = $this->nameColumn;

		switch ($rule) {
			case 'title':			return "$conv(`$table`.`$nameCol`) $sqlOp $conv(?)";
			case 'my_flagged':		return "`$table`.`starred` $sqlOp";
			case 'favorite':		return "($conv(`$table`.`$nameCol`) $sqlOp $conv(?) AND `$table`.`starred` IS NOT NULL)"; // title search among flagged
			case 'myrating':		// fall through, we provide no access to other people's data
			case 'rating':			return "`$table`.`rating` $sqlOp ?";
			case 'added':			return "`$table`.`created` $sqlOp ?";
			case 'updated':			return "`$table`.`updated` $sqlOp ?";
			case 'mbid':			return "`$table`.`mbid` $sqlOp ?";
			case 'recent_added':	return "`$table`.`id` IN (SELECT * FROM (SELECT `id` FROM `$table` WHERE `user_id` = ? ORDER BY `created` DESC LIMIT $sqlOp) mysqlhack)";
			case 'recent_updated':	return "`$table`.`id` IN (SELECT * FROM (SELECT `id` FROM `$table` WHERE `user_id` = ? ORDER BY `updated` DESC LIMIT $sqlOp) mysqlhack)";
			default:				throw new \DomainException("Rule '$rule' not supported on this entity type");
		}
	}

	protected function sqlConcat(string ...$parts) : string {
		if ($this->dbType == 'sqlite3') {
			return '(' . \implode(' || ', $parts) . ')';
		} else {
			return 'CONCAT(' . \implode(', ', $parts) . ')';
		}
	}

	protected function sqlGroupConcat(string $column) : string {
		if ($this->dbType == 'pgsql') {
			return "string_agg($column, ',')";
		} else {
			return "GROUP_CONCAT($column)";
		}
	}

	protected function sqlCoalesce(string $value, string $replacement) : string {
		if ($this->dbType == 'pgsql') {
			return "COALESCE($value, $replacement)";
		} else {
			return "IFNULL($value, $replacement)";
		}
	}

	/**
	 * @param string $datetime Date-and-time string in UTC
	 */
	protected function sqlDateToEpoch(string $datetime) : string {
		if ($this->dbType == 'pgsql') {
			return "DATE_PART('EPOCH', $datetime)";
		} elseif ($this->dbType == 'sqlite3') {
			return "CAST(strftime('%s', $datetime) AS INT)";
		} else { // 'mysql'
			// MySQL function UNIX_TIMESTAMP "helpfully" converts given datetime from session timezone to UTC
			// and there's no way to prevent this. Hence, we need to convert the UTC first to the session time zone.
			// In addition to being stupid, this is ambiguous for one hour per year when the DST ends. As result, this
			// function is slightly bugged and the result is off-by-one-hour during this DST shift hour.
			return "UNIX_TIMESTAMP(CONVERT_TZ($datetime, '+00:00', @@session.time_zone))";
		}
	}

	/**
	 * SQLite connects the operator REGEXP to the function of the same name but doesn't ship the function itself.
	 * Hence, we need to register it as a user-function. This happens by creating a suitable wrapper for the PHP
	 * native preg_match function. Based on https://stackoverflow.com/a/18484596.
	 */
	private function registerRegexpFuncForSqlite() : void {
		// skip if the function already exists
		if (!$this->funcExistsInSqlite('regexp')) {
			// We need to use a private interface here to drill down to the native DB connection. The interface is
			// slightly different on NC compared to OC.
			if (\method_exists($this->db, 'getInner')) {
				$connection = $this->db->/** @scrutinizer ignore-call */getInner()->getWrappedConnection();
				$pdo = $connection->getWrappedConnection();
			} elseif (\method_exists($this->db, 'getWrappedConnection')) {
				$pdo = $this->db->/** @scrutinizer ignore-call */getWrappedConnection();
			}

			if (isset($pdo)) {
				$pdo->sqliteCreateFunction(
					'regexp',
					function ($pattern, $data, $delimiter = '~', $modifiers = 'isuS') {
						if (isset($pattern, $data) === true) {
							return (\preg_match(\sprintf('%1$s%2$s%1$s%3$s', $delimiter, $pattern, $modifiers), $data) > 0);
						}
						return null;
					}
				);
			}
		}
	}

	private function funcExistsInSqlite(string $funcName) : bool {
		// If the SQLite version is very old, it may not have the `pragma_function_list` table available. In such cases,
		// assume that the queried function doesn't exist. It doesn't really make any harm if that leads to registering
		// the same function again.
		try {
			$result = $this->execute('SELECT EXISTS(SELECT 1 FROM `pragma_function_list` WHERE `NAME` = ?)', [$funcName]);
			$row = $result->fetch();
			$result->closeCursor();
			return (bool)\current($row);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Find ID of an existing entity which conflicts with the unique constraint of the given entity
	 */
	private function findIdOfConflict(Entity $entity) : int {
		if (empty($this->uniqueColumns)) {
			throw new \BadMethodCallException('not supported');
		}

		$properties = \array_map(fn($col) => $entity->columnToProperty($col), $this->uniqueColumns);
		$values = \array_map(fn($prop) => $entity->$prop, $properties);

		$conds = \array_map(fn($col) => "`$col` = ?", $this->uniqueColumns);
		$sql = "SELECT `id` FROM {$this->getTableName()} WHERE " . \implode(' AND ', $conds);

		$result = $this->execute($sql, $values);
		$id = $result->fetchOne();
		$result->closeCursor();

		if ($id === false) {
			throw new DoesNotExistException('Conflicting entity not found');
		}

		return (int)$id;
	}

	private function getCreated(int $id) : string {
		$sql = "SELECT `created` FROM {$this->getTableName()} WHERE `id` = ?";
		$result = $this->execute($sql, [$id]);
		$created = $result->fetchOne();
		$result->closeCursor();
		if ($created === false) {
			throw new DoesNotExistException('ID not found');
		}
		return $created;
	}
}
