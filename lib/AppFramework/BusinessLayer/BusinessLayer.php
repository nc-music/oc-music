<?php declare(strict_types=1);
/**
 * ownCloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alessandro Cosentino <cosenal@gmail.com>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Alessandro Cosentino 2012
 * @copyright Bernhard Posselt 2012, 2014
 * @copyright Pauli Järvinen 2017 - 2025
 */

namespace OCA\Music\AppFramework\BusinessLayer;

use OCA\Music\Db\BaseMapper;
use OCA\Music\Db\Entity;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\SortBy;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\Random;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IL10N;

/**
 * @template EntityType of Entity
 */
abstract class BusinessLayer {
	/** @phpstan-var BaseMapper<EntityType> */
	protected BaseMapper $mapper;

	// Some SQLite installations can't handle more than 999 query args. Remember that `user_id` takes one slot in most queries.
	public const MAX_SQL_ARGS = 999;

	/**
	 * @phpstan-param BaseMapper<EntityType> $mapper
	 */
	public function __construct(BaseMapper $mapper) {
		$this->mapper = $mapper;
	}

	/**
	 * Delete an entity
	 * @param int $id the id of the entity
	 * @param string $userId the name of the user for security reasons
	 * @throws BusinessLayerException if the entity does not exist or more than one entity exists
	 * @phpstan-return EntityType
	 */
	public function delete(int $id, string $userId) : Entity {
		$entity = $this->find($id, $userId);
		return $this->mapper->delete($entity);
	}

	/**
	 * Deletes entities without specifying the owning user.
	 * This should never be called directly from the HTML API, but only in case
	 * we can actually trust the passed IDs (e.g. file deleted hook).
	 * @param array $ids the ids of the entities which should be deleted
	 */
	public function deleteById(array $ids) : void {
		if (\count($ids) > 0) {
			$this->mapper->deleteById($ids);
		}
	}

	/**
	 * Delete all entities of the given user
	 */
	public function deleteAll(string $userId) : void {
		$this->mapper->deleteAll($userId);
	}

	/**
	 * Finds an entity by id
	 * @param int $id the id of the entity
	 * @param string $userId the name of the user for security reasons
	 * @throws BusinessLayerException if the entity does not exist or more than one entity exists
	 * @phpstan-return EntityType
	 */
	public function find(int $id, string $userId) : Entity {
		try {
			return $this->mapper->find($id, $userId);
		} catch (DoesNotExistException $ex) {
			throw new BusinessLayerException($ex->getMessage());
		} catch (MultipleObjectsReturnedException $ex) {
			throw new BusinessLayerException($ex->getMessage());
		}
	}

	/**
	 * Finds an entity by id, or returns an empty entity instance if the requested one is not found
	 * @param int $id the id of the entity
	 * @param string $userId the name of the user for security reasons
	 * @phpstan-return EntityType
	 */
	public function findOrDefault(int $id, string $userId) : Entity {
		try {
			return $this->find($id, $userId);
		} catch (BusinessLayerException $ex) {
			return $this->mapper->createEntity();
		}
	}

	/**
	 * Find all entities matching the given IDs.
	 * Specifying the user is optional; if omitted, the caller should make sure that
	 * user's data is not leaked to unauthorized users.
	 * @param integer[] $ids  IDs of the entities to be found
	 * @param string|null $userId
	 * @param bool $preserveOrder If true, then the result will be in the same order as @a $ids
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findById(array $ids, ?string $userId=null, bool $preserveOrder=false) : array {
		$entities = [];
		if (\count($ids) > 0) {
			// don't use more than 999 SQL args in one query since that may be a problem for SQLite
			$idChunks = \array_chunk($ids, 998);
			foreach ($idChunks as $idChunk) {
				$entities = \array_merge($entities, $this->mapper->findById($idChunk, $userId));
			}
		}

		if ($preserveOrder) {
			$lut = ArrayUtil::createIdLookupTable($entities);
			$result = [];
			foreach ($ids as $id) {
				if (isset($lut[$id])) {
					$result[] = $lut[$id];
				}
			}
		} else {
			$result = $entities;
		}

		return $result;
	}

	/**
	 * Finds all entities
	 * @param string $userId the name of the user
	 * @param integer $sortBy sort order of the result set
	 * @param integer|null $limit
	 * @param integer|null $offset
	 * @param string|null $createdMin Optional minimum `created` timestamp.
	 * @param string|null $createdMax Optional maximum `created` timestamp.
	 * @param string|null $updatedMin Optional minimum `updated` timestamp.
	 * @param string|null $updatedMax Optional maximum `updated` timestamp.
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAll(
			string $userId, int $sortBy=SortBy::Name, ?int $limit=null, ?int $offset=null,
			?string $createdMin=null, ?string $createdMax=null, ?string $updatedMin=null, ?string $updatedMax=null) : array {
		return $this->mapper->findAll($userId, $sortBy, $limit, $offset, $createdMin, $createdMax, $updatedMin, $updatedMax);
	}

	/**
	 * Return all entities with name matching the search criteria
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
		if ($name !== null) {
			$name = \trim($name);
		}
		return $this->mapper->findAllByName($name, $userId, $matchMode, $limit, $offset, $createdMin, $createdMax, $updatedMin, $updatedMax);
	}

	/**
	 * Find all starred entities
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllStarred(string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findAllStarred($userId, $limit, $offset);
	}

	/**
	 * Find IDSs of all starred entities
	 * @return int[]
	 */
	public function findAllStarredIds(string $userId) : array {
		return $this->mapper->findAllStarredIds($userId);
	}

	/**
	 * Find all entities with user-given rating 1-5
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllRated(string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findAllRated($userId, $limit, $offset);
	}

	/**
	 * Find all entities matching multiple criteria, as needed for the Ampache API method `advanced_search`
	 * @param string $conjunction Operator to use between the rules, either 'and' or 'or'
	 * @param array $rules Array of arrays: [['rule' => string, 'operator' => string, 'input' => string], ...]
	 * 				Here, 'rule' has dozens of possible values depending on the business layer in question,
	 * 				(see https://ampache.org/api/api-advanced-search#available-search-rules, alias names not supported here),
	 * 				'operator' is one of 
	 * 				['contain', 'notcontain', 'start', 'end', 'is', 'isnot', 'sounds', 'notsounds', 'regexp', 'notregexp',
	 * 				 '>=', '<=', '=', '!=', '>', '<', 'before', 'after', 'true', 'false', 'equal', 'ne', 'limit'],
	 * 				'input' is the right side value of the 'operator' (disregarded for the operators 'true' and 'false')
	 * @param Random $random When the randomization utility is passed, the result set will be in random order (still supporting proper paging).
	 * 						 In this case, the argument $sortBy is ignored.
	 * @return Entity[]
	 * @phpstan-return EntityType[]
	 */
	public function findAllAdvanced(
			string $conjunction, array $rules, string $userId, int $sortBy=SortBy::Name,
			?Random $random=null, ?int $limit=null, ?int $offset=null) : array {

		if ($conjunction !== 'and' && $conjunction !== 'or') {
			throw new BusinessLayerException("Bad conjunction '$conjunction'");
		}
		try {
			if ($random !== null) {
				// in case the random order is requested, the limit/offset handling happens after the DB query
				$entities = $this->mapper->findAllAdvanced($conjunction, $rules, $userId, SortBy::Name);
				$indices = $random->getIndices(\count($entities), $offset, $limit, $userId, 'adv_search_'.$this->mapper->unprefixedTableName());
				$entities = ArrayUtil::multiGet($entities, $indices);
			} else {
				$entities = $this->mapper->findAllAdvanced($conjunction, $rules, $userId, $sortBy, $limit, $offset);
			}
			return $entities;
		} catch (\Exception $e) {
			// catch everything as many kinds of DB exceptions are possible on various cloud versions
			throw new BusinessLayerException($e->getMessage());
		}
	}

	/**
	 * Find IDs of all user's entities of this kind.
	 * Optionally, limit to given IDs which may be used to check the validity of those IDs.
	 * @return int[]
	 */
	public function findAllIds(string $userId, ?array $ids = null) : array {
		if ($ids === null || \count($ids) > 0) {
			return $this->mapper->findAllIds($userId, $ids);
		} else {
			return [];
		}
	}

	/**
	 * Find all entity IDs grouped by the given parent entity IDs. Not applicable on all entity types.
	 * @param int[] $parentIds
	 * @return array<int, int[]> like [parentId => childIds[]]; some parents may have an empty array of children
	 * @throws BusinessLayerException if the entity type handled by this business layer doesn't have a parent relation
	 */
	public function findAllIdsByParentIds(string $userId, array $parentIds) : ?array {
		try {
			return $this->mapper->findAllIdsByParentIds($userId, $parentIds);
		} catch (\DomainException $ex) {
			throw new BusinessLayerException($ex->getMessage());
		}
	}

	/**
	 * Find all IDs and names of user's entities of this kind.
	 * Optionally, limit results based on a parent entity (not applicable for all entity types) or update/insert times or name
	 * @param bool $excludeChildless Exclude entities having no child-entities if applicable for this business layer (eg. artists without albums)
	 * @return array of arrays like ['id' => string, 'name' => string]
	 */
	public function findAllIdsAndNames(string $userId, IL10N $l10n, ?int $parentId=null, ?int $limit=null, ?int $offset=null,
			?string $createdMin=null, ?string $createdMax=null, ?string $updatedMin=null, ?string $updatedMax=null,
			bool $excludeChildless=false, ?string $name=null) : array {
		try {
			$idsAndNames = $this->mapper->findAllIdsAndNames(
				$userId, $parentId, $limit, $offset, $createdMin, $createdMax, $updatedMin, $updatedMax, $excludeChildless, $name);
		} catch (\DomainException $ex) {
			throw new BusinessLayerException($ex->getMessage());
		}
		foreach ($idsAndNames as &$idAndName) {
			if (empty($idAndName['name'])) {
				$emptyEntity = $this->mapper->createEntity();
				$idAndName['name'] = $emptyEntity->getNameString($l10n);
			}
		}
		return $idsAndNames;
	}

	/**
	 * Find IDs of all users owning any entities of this business layer
	 * @return string[]
	 */
	public function findAllUsers() : array {
		return $this->mapper->findAllUsers();
	}

	/**
	 * Set the given entities as "starred" on this date
	 * @param int[] $ids
	 * @param string $userId
	 * @return int number of modified entities
	 */
	public function setStarred(array $ids, string $userId) : int {
		if (\count($ids) > 0) {
			return $this->mapper->setStarredDate(new \DateTime(), $ids, $userId);
		} else {
			return 0;
		}
	}

	/**
	 * Remove the "starred" status of the given entities
	 * @param integer[] $ids
	 * @param string $userId
	 * @return int number of modified entities
	 */
	public function unsetStarred(array $ids, string $userId) : int {
		if (\count($ids) > 0) {
			return $this->mapper->setStarredDate(null, $ids, $userId);
		} else {
			return 0;
		}
	}

	/**
	 * Set rating for the entity by id
	 * @throws BusinessLayerException if the entity does not exist or more than one entity exists
	 * @throws \BadMethodCallException if the entity type of this business layer doesn't support rating
	 * @phpstan-return EntityType
	 */
	public function setRating(int $id, int $rating, string $userId) : Entity {
		$entity = $this->find($id, $userId);
		if (\property_exists($entity, 'rating')) {
			// Scrutinizer and PHPStan don't understand the connection between the property 'rating' and the method 'setRating'
			$entity->/** @scrutinizer ignore-call */setRating($rating); // @phpstan-ignore method.notFound
			return $this->mapper->update($entity);
		} else {
			throw new \BadMethodCallException('rating not supported on the entity type ' . \get_class($entity));
		}
	}

	/**
	 * Tests if entity with given ID and user ID exists in the database
	 */
	public function exists(int $id, string $userId) : bool {
		return $this->mapper->exists($id, $userId);
	}

	/**
	 * Get the number of entities
	 */
	public function count(string $userId) : int {
		return $this->mapper->count($userId);
	}

	/**
	 * Get the largest entity ID of the user
	 */
	public function maxId(string $userId) : ?int {
		return $this->mapper->maxId($userId);
	}

	/**
	 * Get the timestamp of the latest insert operation on the entity type in question
	 */
	public function latestInsertTime(string $userId) : \DateTime {
		return $this->mapper->latestInsertTime($userId) ?? new \DateTime('1970-01-01');
	}

	/**
	 * Get the timestamp of the latest update operation on the entity type in question
	 */
	public function latestUpdateTime(string $userId) : \DateTime {
		return $this->mapper->latestUpdateTime($userId) ?? new \DateTime('1970-01-01');
	}
}
