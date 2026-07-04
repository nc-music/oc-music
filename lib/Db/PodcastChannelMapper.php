<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2021 - 2025
 */

namespace OCA\Music\Db;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Type hint a base class method to help Scrutinizer
 * @method PodcastChannel insert(PodcastChannel $channel)
 * @extends BaseMapper<PodcastChannel>
 */
class PodcastChannelMapper extends BaseMapper {
	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct($db, $config, 'music_podcast_channels', PodcastChannel::class, 'title', ['user_id', 'rss_hash']);
	}

	/**
	 * @return int[]
	 */
	public function findAllIdsWithNoUpdateSince(string $userId, \DateTime $timeLimit) : array {
		$sql = "SELECT `id` FROM `{$this->getTableName()}` WHERE `user_id` = ? AND `update_checked` < ?";
		$result = $this->execute($sql, [$userId, $timeLimit->format(BaseMapper::SQL_DATE_FORMAT)]);
		$rows = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		return \array_map('intval', $rows);
	}

	/**
	 * Overridden from the base implementation to provide support for table-specific rules
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::advFormatSqlCondition()
	 */
	protected function advFormatSqlCondition(string $rule, string $sqlOp, string $conv) : string {
		$condForRule = [
			'podcast_episode'	=> "`*PREFIX*music_podcast_channels`.`id` IN (SELECT `channel_id` FROM `*PREFIX*music_podcast_episodes` `e` WHERE $conv(`e`.`title`) $sqlOp $conv(?))",
			'time'				=> "`*PREFIX*music_podcast_channels`.`id` IN (SELECT * FROM (SELECT `channel_id` FROM `*PREFIX*music_podcast_episodes` GROUP BY `channel_id` HAVING SUM(`duration`) $sqlOp ?) mysqlhack)",
			'pubdate'			=> "`published` $sqlOp ?"
		];

		return $condForRule[$rule] ?? parent::advFormatSqlCondition($rule, $sqlOp, $conv);
	}
}
