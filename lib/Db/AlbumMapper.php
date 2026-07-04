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
 * @copyright Pauli Järvinen 2016 - 2025
 */

namespace OCA\Music\Db;

use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\StringUtil;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Type hint a base class method to help Scrutinizer
 * @method Album updateOrInsert(Album $album)
 * @extends BaseMapper<Album>
 */
class AlbumMapper extends BaseMapper {
	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct($db, $config, 'music_albums', Album::class, 'name', ['user_id', 'hash'], 'album_artist_id');
	}

	/**
	 * Override the base implementation to include data from multiple tables
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::selectEntities()
	 */
	protected function selectEntities(string $condition, ?string $extension=null) : string {
		return "SELECT `*PREFIX*music_albums`.*, `artist`.`name` AS `album_artist_name`
				FROM `*PREFIX*music_albums`
				INNER JOIN `*PREFIX*music_artists` `artist`
				ON `*PREFIX*music_albums`.`album_artist_id` = `artist`.`id`
				WHERE $condition $extension";
	}

	/**
	 * Overridden from \OCA\Music\Db\BaseMapper to add support for sorting by artist.
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::formatSortingClause()
	 */
	protected function formatSortingClause(int $sortBy, bool $invertSort = false) : ?string {
		if ($sortBy === SortBy::Parent) {
			// Note: the alternative form "LOWER(`album_artist_name`) wouldn't work on PostgreSQL, see https://github.com/owncloud/music/issues/1046
			$dir = $invertSort ? 'DESC' : 'ASC';
			return "ORDER BY LOWER(`artist`.`name`) $dir, LOWER(`*PREFIX*music_albums`.`name`) $dir";
		} else {
			return parent::formatSortingClause($sortBy, $invertSort);
		}
	}

	/**
	 * returns artist IDs mapped to album IDs
	 * does not include album_artist_id
	 *
	 * @param integer[]|null $albumIds IDs of the albums; get all albums of the user if null given
	 * @param string $userId the user ID
	 * @return array<int, int[]> keys are albums IDs and values are arrays of artist IDs
	 */
	public function getPerformingArtistsByAlbumId(?array $albumIds, string $userId) : array {
		$sql = 'SELECT DISTINCT `track`.`album_id`, `track`.`artist_id`
				FROM `*PREFIX*music_tracks` `track`
				WHERE `track`.`user_id` = ? ';
		$params = [$userId];

		if ($albumIds !== null) {
			$sql .= 'AND `track`.`album_id` IN ' . $this->questionMarks(\count($albumIds));
			$params = \array_merge($params, $albumIds);
		}

		$result = $this->execute($sql, $params);
		$artistIds = [];
		while ($row = $result->fetch()) {
			$artistIds[$row['album_id']][] = (int)$row['artist_id'];
		}
		$result->closeCursor();
		return $artistIds;
	}

	/**
	 * returns release years mapped to album IDs
	 *
	 * @param integer[]|null $albumIds IDs of the albums; get all albums of the user if null given
	 * @param string $userId the user ID
	 * @return array<int, int[]> keys are albums IDs and values are arrays of years
	 */
	public function getYearsByAlbumId(?array $albumIds, string $userId) : array {
		$sql = 'SELECT DISTINCT `track`.`album_id`, `track`.`year`
				FROM `*PREFIX*music_tracks` `track`
				WHERE `track`.`user_id` = ?
				AND `track`.`year` IS NOT NULL ';
		$params = [$userId];

		if ($albumIds !== null) {
			$sql .= 'AND `track`.`album_id` IN ' . $this->questionMarks(\count($albumIds));
			$params = \array_merge($params, $albumIds);
		}

		$result = $this->execute($sql, $params);
		$years = [];
		while ($row = $result->fetch()) {
			$years[$row['album_id']][] = (int)$row['year'];
		}
		$result->closeCursor();
		return $years;
	}

	/**
	 * returns genres mapped to album IDs
	 *
	 * @param integer[]|null $albumIds IDs of the albums; get all albums of the user if null given
	 * @param string $userId the user ID
	 * @return array<int, Genre[]> keys are albums IDs and values are arrays of *partial* Genre objects (only id and name properties set)
	 */
	public function getGenresByAlbumId(?array $albumIds, string $userId) : array {
		$sql = 'SELECT DISTINCT `album_id`, `genre_id`, `*PREFIX*music_genres`.`name` AS `genre_name`
				FROM `*PREFIX*music_tracks`
				LEFT JOIN `*PREFIX*music_genres`
				ON `genre_id` = `*PREFIX*music_genres`.`id`
				WHERE `*PREFIX*music_tracks`.`user_id` = ?
				AND `genre_id` IS NOT NULL ';
		$params = [$userId];

		if ($albumIds !== null) {
			$sql .= 'AND `album_id` IN ' . $this->questionMarks(\count($albumIds));
			$params = \array_merge($params, $albumIds);
		}

		$result = $this->execute($sql, $params);
		$genres = [];
		while ($row = $result->fetch()) {
			$genre = new Genre();
			$genre->setUserId($userId);
			$genre->setId((int)$row['genre_id']);
			$genre->setName($row['genre_name']);
			$genres[$row['album_id']][] = $genre;
		}
		$result->closeCursor();
		return $genres;
	}

	/**
	 * returns number of disks per album ID
	 *
	 * @param integer[]|null $albumIds IDs of the albums; get all albums of the user if null given
	 * @param string $userId the user ID
	 * @return array<int, int> keys are albums IDs and values are disk counts
	 */
	public function getDiscCountByAlbumId(?array $albumIds, string $userId) : array {
		$sql = 'SELECT `album_id`, MAX(`disk`) AS `disc_count`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ?
				GROUP BY `album_id` ';
		$params = [$userId];

		if ($albumIds !== null) {
			$sql .= 'HAVING `album_id` IN ' . $this->questionMarks(\count($albumIds));
			$params = \array_merge($params, $albumIds);
		}

		$result = $this->execute($sql, $params);
		$diskCountByAlbum = [];
		while ($row = $result->fetch()) {
			$diskCountByAlbum[$row['album_id']] = (int)$row['disc_count'];
		}
		$result->closeCursor();
		return $diskCountByAlbum;
	}

	/**
	 * returns summed track play counts of each album of the user, omitting albums which have never been played
	 *
	 * @return array<int, int> keys are album IDs and values are play count sums; ordered largest counts first
	 */
	public function getAlbumTracksPlayCount(string $userId, ?int $limit=null, ?int $offset=null) : array {
		$sql = 'SELECT `album_id`, SUM(`play_count`) AS `sum_count`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ? AND `play_count` > 0
				GROUP BY `album_id`
				ORDER BY `sum_count` DESC, `album_id`'; // the second criterion is just to make the order predictable on even counts

		$result = $this->execute($sql, [$userId], $limit, $offset);
		$playCountByAlbum = [];
		while ($row = $result->fetch()) {
			$playCountByAlbum[$row['album_id']] = (int)$row['sum_count'];
		}
		$result->closeCursor();
		return $playCountByAlbum;
	}

	/**
	 * returns the latest play time of each album of the user, omitting albums which have never been played
	 *
	 * @return array<int, string> keys are album IDs and values are date-times; ordered latest times first
	 */
	public function getLatestAlbumPlayTimes(string $userId, ?int $limit=null, ?int $offset=null) : array {
		$sql = 'SELECT `album_id`, MAX(`last_played`) AS `latest_time`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ? AND `last_played` IS NOT NULL
				GROUP BY `album_id`
				ORDER BY `latest_time` DESC';

		$result = $this->execute($sql, [$userId], $limit, $offset);
		$latestTimeByAlbum = [];
		while ($row = $result->fetch()) {
			$latestTimeByAlbum[$row['album_id']] = $row['latest_time'];
		}
		$result->closeCursor();
		return $latestTimeByAlbum;
	}

	/**
	 * returns the latest play time of each album of the user, including albums which have never been played
	 *
	 * @return array<int, ?string> keys are album IDs and values are date-times (or null for never played);
	 *								ordered furthest times first
	 */
	public function getFurthestAlbumPlayTimes(string $userId, ?int $limit=null, ?int $offset=null) : array {
		$sql = 'SELECT `album_id`, MAX(`last_played`) AS `latest_time`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ?
				GROUP BY `album_id`
				ORDER BY `latest_time` ASC';

		$result = $this->execute($sql, [$userId], $limit, $offset);
		$latestTimeByAlbum = [];
		while ($row = $result->fetch()) {
			$latestTimeByAlbum[$row['album_id']] = $row['latest_time'];
		}
		$result->closeCursor();
		return $latestTimeByAlbum;
	}

	/**
	 * @return Album[]
	 */
	public function findAllByNameRecursive(string $name, string $userId, ?int $limit=null, ?int $offset=null) : array {
		$condition = '( LOWER(`artist`.`name`) LIKE LOWER(?) OR
						LOWER(`*PREFIX*music_albums`.`name`) LIKE LOWER(?) )';
		$sql = $this->selectUserEntities($condition, 'ORDER BY LOWER(`*PREFIX*music_albums`.`name`)');
		$name = BaseMapper::prepareSubstringSearchPattern($name);
		$params = [$userId, $name, $name];
		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * returns albums of a specified artist
	 * The artist may be an album_artist or the artist of a track
	 *
	 * @param integer $artistId
	 * @return Album[]
	 */
	public function findAllByArtist(int $artistId, string $userId, ?int $limit=null, ?int $offset=null) : array {
		$sql = $this->selectEntities(
				'`*PREFIX*music_albums`.`id` IN (
					SELECT DISTINCT `album`.`id`
					FROM `*PREFIX*music_albums` `album`
					WHERE `album`.`album_artist_id` = ?
						UNION
					SELECT DISTINCT `track`.`album_id`
					FROM `*PREFIX*music_tracks` `track`
					WHERE `track`.`artist_id` = ?
				) AND `*PREFIX*music_albums`.`user_id` = ?',
				'ORDER BY LOWER(`*PREFIX*music_albums`.`name`)');
		$params = [$artistId, $artistId, $userId];
		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * returns albums of a specified artists
	 * The artist must be album_artist on the album, artists of individual tracks are not considered
	 *
	 * @param int[] $artistIds
	 * @return Album[]
	 */
	public function findAllByAlbumArtist(array $artistIds, string $userId, ?int $limit=null, ?int $offset=null) : array {
		$sql = $this->selectUserEntities('`album_artist_id` IN ' . $this->questionMarks(\count($artistIds)));
		$params = \array_merge([$userId], $artistIds);
		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * @return Album[]
	 */
	public function findAllByGenre(int $genreId, string $userId, ?int $limit=null, ?int $offset=null) : array {
		$sql = $this->selectUserEntities('EXISTS '.
				'(SELECT 1 FROM `*PREFIX*music_tracks` `track`
				  WHERE `*PREFIX*music_albums`.`id` = `track`.`album_id`
				  AND `track`.`genre_id` = ?)');

		$params = [$userId, $genreId];
		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * @return boolean True if one or more albums were influenced
	 */
	public function updateFolderCover(int $coverFileId, int $folderId) : bool {
		$sql = 'SELECT DISTINCT `tracks`.`album_id`
				FROM `*PREFIX*music_tracks` `tracks`
				JOIN `*PREFIX*filecache` `files` ON `tracks`.`file_id` = `files`.`fileid`
				WHERE `files`.`parent` = ?';
		$params = [$folderId];
		$result = $this->execute($sql, $params);
		$albumIds = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		$updated = false;
		if (\count($albumIds) > 0) {
			$sql = 'UPDATE `*PREFIX*music_albums`
					SET `cover_file_id` = ?
					WHERE `cover_file_id` IS NULL AND `id` IN '. $this->questionMarks(\count($albumIds));
			$params = \array_merge([$coverFileId], $albumIds);
			$result = $this->execute($sql, $params);
			$updated = $result->rowCount() > 0;
			$result->closeCursor();
		}

		return $updated;
	}

	/**
	 * Set file ID to be used as cover for an album
	 */
	public function setCover(?int $coverFileId, int $albumId) : void {
		$sql = 'UPDATE `*PREFIX*music_albums`
				SET `cover_file_id` = ?
				WHERE `id` = ?';
		$params = [$coverFileId, $albumId];
		$result = $this->execute($sql, $params);
		$result->closeCursor();
	}

	/**
	 * @param integer[] $coverFileIds
	 * @param string[]|null $userIds the users whose music library is targeted; all users are targeted if omitted
	 * @return Album[] albums which got modified (with incomplete data, only id and user are valid),
	 *         empty array if none
	 */
	public function removeCovers(array $coverFileIds, ?array $userIds=null) : array {
		// find albums using the given file as cover
		$sql = 'SELECT `id`, `user_id` FROM `*PREFIX*music_albums` WHERE `cover_file_id` IN ' .
			$this->questionMarks(\count($coverFileIds));
		$params = $coverFileIds;
		if ($userIds !== null) {
			$sql .= ' AND `user_id` IN ' . $this->questionMarks(\count($userIds));
			$params = \array_merge($params, $userIds);
		}
		$albums = $this->findEntities($sql, $params);

		// if any albums found, remove the cover from those
		$count = \count($albums);
		if ($count) {
			$sql = 'UPDATE `*PREFIX*music_albums`
				SET `cover_file_id` = NULL
				WHERE `id` IN ' . $this->questionMarks($count);
			$params = ArrayUtil::extractIds($albums);
			$result = $this->execute($sql, $params);
			$result->closeCursor();
		}

		return $albums;
	}

	/**
	 * @param string|null $userId target user; omit to target all users
	 * @param ?int[] $parentIds limit search to albums having tracks in these folders; omit for no limit
	 * @return array<array{albumId: int, userId: string, parentFolderId: int}>
	 */
	public function getAlbumsWithoutCover(?string $userId = null, ?array $parentIds = null) : array {
		$sql = 'SELECT DISTINCT `albums`.`id`, `albums`.`user_id`, `files`.`parent`
				FROM `*PREFIX*music_albums` `albums`
				JOIN `*PREFIX*music_tracks` `tracks` ON `albums`.`id` = `tracks`.`album_id`
				JOIN `*PREFIX*filecache` `files` ON `tracks`.`file_id` = `files`.`fileid`
				WHERE `albums`.`cover_file_id` IS NULL';
		$params = [];
		if ($userId !== null) {
			$sql .= ' AND `albums`.`user_id` = ?';
			$params[] = $userId;
		}
		if (!empty($parentIds)) {
			$sql .= ' AND `files`.`parent` IN ' . $this->questionMarks(\count($parentIds));
			$params = \array_merge($params, $parentIds);
		}

		$result = $this->execute($sql, $params);
		$return = [];
		while ($row = $result->fetch()) {
			$return[] = [
				'albumId' => (int)$row['id'],
				'userId' => $row['user_id'],
				'parentFolderId' => (int)$row['parent']
			];
		}
		$result->closeCursor();

		return $return;
	}

	/**
	 * @return boolean True if a cover image was found and added for the album
	 */
	public function findAlbumCover(int $albumId, int $parentFolderId) : bool {
		$return = false;
		$imagesSql = 'SELECT `fileid`, `name`
					FROM `*PREFIX*filecache`
					JOIN `*PREFIX*mimetypes` ON `*PREFIX*mimetypes`.`id` = `*PREFIX*filecache`.`mimetype`
					WHERE `parent` = ? AND `*PREFIX*mimetypes`.`mimetype` LIKE \'image%\'';
		$params = [$parentFolderId];
		$result = $this->execute($imagesSql, $params);
		$images = $result->fetchAll();
		$result->closeCursor();
		if (\count($images) > 0) {
			$getImageRank = function($imageName) {
				$coverNames = ['cover', 'albumart', 'album', 'front', 'folder'];
				foreach ($coverNames as $i => $coverName) {
					if (StringUtil::startsWith($imageName, $coverName, /*$ignoreCase=*/true)) {
						return $i;
					}
				}
				return \count($coverNames);
			};

			\usort($images, fn($imageA, $imageB) =>
				$getImageRank($imageA['name']) <=> $getImageRank($imageB['name'])
			);
			$imageId = (int)$images[0]['fileid'];
			$this->setCover($imageId, $albumId);
			$return = true;
		}
		return $return;
	}

	/**
	 * Given an array of track IDs, find corresponding unique album IDs, including only
	 * those album which have a cover art set.
	 * @param int[] $trackIds
	 * @return Album[]
	 */
	public function findAlbumsWithCoversForTracks(array $trackIds, string $userId, int $limit) : array {
		$sql = 'SELECT DISTINCT `albums`.*
				FROM `*PREFIX*music_albums` `albums`
				JOIN `*PREFIX*music_tracks` `tracks` ON `albums`.`id` = `tracks`.`album_id`
				WHERE `albums`.`cover_file_id` IS NOT NULL
				AND `albums`.`user_id` = ?
				AND `tracks`.`id` IN ' . $this->questionMarks(\count($trackIds));
		$params = \array_merge([$userId], $trackIds);

		return $this->findEntities($sql, $params, $limit);
	}

	/**
	 * Returns the count of albums where the given Artist is featured in
	 * @param integer $artistId
	 * @return integer
	 */
	public function countByArtist(int $artistId) : int {
		$sql = 'SELECT COUNT(*) AS count FROM (
					SELECT DISTINCT `track`.`album_id`
					FROM `*PREFIX*music_tracks` `track`
					WHERE `track`.`artist_id` = ?
						UNION
					SELECT `album`.`id`
					FROM `*PREFIX*music_albums` `album`
					WHERE `album`.`album_artist_id` = ?
				) tmp';
		$params = [$artistId, $artistId];
		$result = $this->execute($sql, $params);
		$row = $result->fetch();
		$result->closeCursor();
		return (int)$row['count'];
	}

	/**
	 * Returns the count of albums where the given artist is the album artist
	 * @param integer $artistId
	 * @return integer
	 */
	public function countByAlbumArtist(int $artistId) : int {
		$sql = 'SELECT COUNT(*) AS count
				FROM `*PREFIX*music_albums` `album`
				WHERE `album`.`album_artist_id` = ?';
		$params = [$artistId];
		$result = $this->execute($sql, $params);
		$row = $result->fetch();
		$result->closeCursor();
		return (int)$row['count'];
	}

	/**
	 * Overridden from the base implementation to provide support for table-specific rules
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::advFormatSqlCondition()
	 */
	protected function advFormatSqlCondition(string $rule, string $sqlOp, string $conv) : string {
		// The extra subquery "mysqlhack" seen around some nested queries is needed in order for these to not be insanely slow on MySQL.
		// In case of 'recent_played', the MySQL 5.5.62 errored with "1235 This version of MySQL doesn't yet support 'LIMIT & IN/ALL/ANY/SOME subquery'" without the extra subquery.
		$condForRule = [
			'album_artist'	=> "$conv(`artist`.`name`) $sqlOp $conv(?)",
			'song_artist'	=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_artists` `ar` ON `t`.`artist_id` = `ar`.`id` WHERE $conv(`ar`.`name`) $sqlOp $conv(?))",
			'song'			=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` WHERE $conv(`t`.`title`) $sqlOp $conv(?))",
			'original_year'	=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` FROM `*PREFIX*music_tracks` GROUP BY `album_id` HAVING MIN(`year`) $sqlOp ?) mysqlhack)",
			'songrating'	=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` WHERE `t`.`rating` $sqlOp ?)",
			'artistrating'	=> "`artist`.rating $sqlOp ?",
			'played_times'	=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` from `*PREFIX*music_tracks` GROUP BY `album_id` HAVING SUM(`play_count`) $sqlOp ?) mysqlhack)",
			'last_play'		=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` from `*PREFIX*music_tracks` GROUP BY `album_id` HAVING MAX(`last_played`) $sqlOp ?) mysqlhack)",
			'myplayed'		=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` from `*PREFIX*music_tracks` GROUP BY `album_id` HAVING MAX(`last_played`) $sqlOp) mysqlhack)", // operator "IS NULL" or "IS NOT NULL"
			'myplayedartist'=> "`album_artist_id` IN (SELECT * FROM (SELECT `artist_id` from `*PREFIX*music_tracks` GROUP BY `artist_id` HAVING MAX(`last_played`) $sqlOp) mysqlhack)", // operator "IS NULL" or "IS NOT NULL"
			'song_count'	=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` FROM `*PREFIX*music_tracks` GROUP BY `album_id` HAVING COUNT(`id`) $sqlOp ?) mysqlhack)",
			'disk_count'	=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` FROM `*PREFIX*music_tracks` GROUP BY `album_id` HAVING MAX(`disk`) $sqlOp ?) mysqlhack)",
			'time'			=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` FROM `*PREFIX*music_tracks` GROUP BY `album_id` HAVING SUM(`length`) $sqlOp ?) mysqlhack)",
			'album_genre'	=> "`*PREFIX*music_albums`.`id` IN (SELECT * FROM (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_genres` `g` ON `t`.`genre_id` = `g`.`id` GROUP BY `album_id` HAVING $conv(" . $this->sqlGroupConcat('`g`.`name`') . ") $sqlOp $conv(?)) mysqlhack)",
			'song_genre'	=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_genres` `g` ON `t`.`genre_id` = `g`.`id` WHERE $conv(`g`.`name`) $sqlOp $conv(?))",
			'no_genre'		=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_genres` `g` ON `t`.`genre_id` = `g`.`id` WHERE `g`.`name` " . (($sqlOp == 'IS NOT NULL') ? '=' : '!=') . ' "")',
			'playlist'		=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` WHERE $sqlOp EXISTS (SELECT 1 from `*PREFIX*music_playlists` `p` WHERE `p`.`id` = ? AND `p`.`track_ids` LIKE " . $this->sqlConcat("'%|'", "`t`.`id`", "'|%'") . '))',
			'playlist_name'	=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` WHERE EXISTS (SELECT 1 from `*PREFIX*music_playlists` `p` WHERE $conv(`p`.`name`) $sqlOp $conv(?) AND `p`.`track_ids` LIKE " . $this->sqlConcat("'%|'", "`t`.`id`", "'|%'") . '))',
			'file'			=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*filecache` `f` ON `t`.`file_id` = `f`.`fileid` WHERE $conv(`f`.`name`) $sqlOp $conv(?))",
			'recent_played'	=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM (SELECT `album_id`, MAX(`last_played`) FROM `*PREFIX*music_tracks` WHERE `user_id` = ? GROUP BY `album_id` ORDER BY MAX(`last_played`) DESC LIMIT $sqlOp) mysqlhack)",
			'mbid_song'		=> "`*PREFIX*music_albums`.`id` IN (SELECT `album_id` FROM `*PREFIX*music_tracks` `t` WHERE `t`.`mbid` $sqlOp ?)",
			'mbid_artist'	=> "`artist`.`mbid` $sqlOp ?",
			'has_image'		=> "`*PREFIX*music_albums`.`cover_file_id` $sqlOp" // operator "IS NULL" or "IS NOT NULL"
		];

		// Add alias rules
		$condForRule['year'] = $condForRule['original_year'];	// we only have one kind of year
		$condForRule['played'] = $condForRule['myplayed'];		// we give no access to other people's data; not part of the API spec but Ample uses this
		$condForRule['artist'] = $condForRule['album_artist'];
		$condForRule['genre'] = $condForRule['album_genre'];
		$condForRule['album'] = parent::advFormatSqlCondition('title', $sqlOp, $conv);
		$condForRule['mbid_album'] = parent::advFormatSqlCondition('mbid', $sqlOp, $conv);

		return $condForRule[$rule] ?? parent::advFormatSqlCondition($rule, $sqlOp, $conv);
	}
}
