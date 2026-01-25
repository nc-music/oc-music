<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013
 * @copyright Pauli Järvinen 2016 - 2025
 */

namespace OCA\Music\BusinessLayer;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Cache;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\SortBy;
use OCA\Music\Db\TrackMapper;
use OCA\Music\Db\Track;
use OCA\Music\Service\FileSystemService;
use OCA\Music\Service\Scrobbling\IScrobbler;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\StringUtil;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Base class functions with the actually used inherited types to help IDE and Scrutinizer:
 * @method Track find(int $trackId, string $userId)
 * @method Track[] findAll(string $userId, int $sortBy=SortBy::Name, ?int $limit=null, ?int $offset=null)
 * @method Track[] findAllByName(string $name, string $userId, int $matchMode=MatchMode::Exact, ?int $limit=null, ?int $offset=null)
 * @property TrackMapper $mapper
 * @extends BusinessLayer<Track>
 */
class TrackBusinessLayer extends BusinessLayer implements IScrobbler {

	private FileSystemService $fileSystemService;
	private Logger $logger;
	private Cache $cache;

	public function __construct(TrackMapper $trackMapper, FileSystemService $fileSystemService, Logger $logger, Cache $cache) {
		parent::__construct($trackMapper);
		$this->fileSystemService = $fileSystemService;
		$this->logger = $logger;
		$this->cache = $cache;
	}

	/**
	 * Returns all tracks filtered by artist (both album and track artists are considered)
	 * @param int|int[] $artistId
	 * @return Track[]
	 */
	public function findAllByArtist(/*mixed*/ $artistId, string $userId, ?int $limit=null, ?int $offset=null) : array {
		if (empty($artistId)) {
			return [];
		} else {
			if (!\is_array($artistId)) {
				$artistId = [$artistId];
			}
			return $this->mapper->findAllByArtist($artistId, $userId, $limit, $offset);
		}
	}

	/**
	 * Returns all tracks filtered by album. Optionally, filter also by the performing artist.
	 * @param int|int[] $albumId
	 * @return Track[]
	 */
	public function findAllByAlbum(/*mixed*/ $albumId, string $userId, ?int $artistId=null, ?int $limit=null, ?int $offset=null) : array {
		if (empty($albumId)) {
			return [];
		} else {
			if (!\is_array($albumId)) {
				$albumId = [$albumId];
			}
			return $this->mapper->findAllByAlbum($albumId, $userId, $artistId, $limit, $offset);
		}
	}

	/**
	 * Returns all tracks filtered by parent folder
	 * @return Track[]
	 */
	public function findAllByFolder(int $folderId, string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findAllByFolder($folderId, $userId, $limit, $offset);
	}

	/**
	 * Returns all tracks filtered by genre
	 * @return Track[]
	 */
	public function findAllByGenre(int $genreId, string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findAllByGenre($genreId, $userId, $limit, $offset);
	}

	/**
	 * Returns all tracks filtered by name (of track/album/artist)
	 * @param string $name the name of the track/album/artist
	 * @param string $userId the name of the user
	 * @return Track[]
	 */
	public function findAllByNameRecursive(string $name, string $userId, ?int $limit=null, ?int $offset=null) : array {
		$name = \trim($name);
		return $this->mapper->findAllByNameRecursive($name, $userId, $limit, $offset);
	}

	/**
	 * Returns all tracks specified by name, artist name, and/or album name
	 * @return Track[] Tracks matching the criteria
	 */
	public function findAllByNameArtistOrAlbum(?string $name, ?string $artistName, ?string $albumName, string $userId) : array {
		if ($name !== null) {
			$name = \trim($name);
		}
		if ($artistName !== null) {
			$artistName = \trim($artistName);
		}

		return $this->mapper->findAllByNameArtistOrAlbum($name, $artistName, $albumName, $userId);
	}

	/**
	 * Find most frequently played tracks
	 * @return Track[]
	 */
	public function findFrequentPlay(string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findFrequentPlay($userId, $limit, $offset);
	}

	/**
	 * Find most recently played tracks
	 * @return Track[]
	 */
	public function findRecentPlay(string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findRecentPlay($userId, $limit, $offset);
	}

	/**
	 * Find least recently played tracks
	 * @return Track[]
	 */
	public function findNotRecentPlay(string $userId, ?int $limit=null, ?int $offset=null) : array {
		return $this->mapper->findNotRecentPlay($userId, $limit, $offset);
	}

	/**
	 * Returns the track for a file id
	 * @return Track|null
	 */
	public function findByFileId(int $fileId, string $userId) : ?Track {
		try {
			return $this->mapper->findByFileId($fileId, $userId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Returns file IDs of all indexed tracks of the user.
	 * Optionally, limit the search to files residing (directly or indirectly) in the given folder.
	 * @return int[]
	 */
	public function findAllFileIds(string $userId, ?int $folderId=null) : array {
		$parentIds = ($folderId !== null) ? $this->fileSystemService->findAllDescendantFolders($folderId) : null;
		return $this->mapper->findAllFileIds($userId, $parentIds);
	}

	/**
	 * Returns file IDs of all indexed tracks of the user which should be rescanned to ensure that the library details are up-to-date.
	 * The track may be considered "dirty" for one of two reasons:
	 * - its 'modified' time in the file system (actually in the cloud's file cache) is later than the 'updated' field of the entity in the database
	 * - it has been specifically marked as dirty, maybe in response to being moved to another directory
	 * Optionally, limit the search to files residing (directly or indirectly) in the given folder.
	 * @return int[]
	 */
	public function findDirtyFileIds(string $userId, ?int $folderId=null) : array {
		$parentIds = ($folderId !== null) ? $this->fileSystemService->findAllDescendantFolders($folderId) : null;
		return $this->mapper->findDirtyFileIds($userId, $parentIds);
	}

	/**
	 * Returns all genre IDs associated with the given artist
	 * @return int[]
	 */
	public function getGenresByArtistId(int $artistId, string $userId) : array {
		return $this->mapper->getGenresByArtistId($artistId, $userId);
	}

	/**
	 * Returns file IDs of the tracks which do not have genre scanned. This is not the same
	 * thing as unknown genre, which is stored as empty string and means that the genre has
	 * been scanned but was not found from the track metadata.
	 * @return int[]
	 */
	public function findFilesWithoutScannedGenre(string $userId) : array {
		return $this->mapper->findFilesWithoutScannedGenre($userId);
	}

	public function countByArtist(int $artistId) : int {
		return $this->mapper->countByArtist($artistId);
	}

	public function countByAlbum(int $albumId) : int {
		return $this->mapper->countByAlbum($albumId);
	}

	/**
	 * @return integer Duration in seconds
	 */
	public function totalDurationOfAlbum(int $albumId) : int {
		return $this->mapper->totalDurationOfAlbum($albumId);
	}

	/**
	 * @return integer Duration in seconds
	 */
	public function totalDurationByArtist(int $artistId) : int {
		return $this->mapper->totalDurationByArtist($artistId);
	}

	/**
	 * Update "last played" timestamp and increment the total play count of the track.
	 */
	public function recordTrackPlayed(int $trackId, string $userId, ?\DateTime $timeOfPlay = null) : void {
		$timeOfPlay = $timeOfPlay ?? new \DateTime();

		if (!$this->mapper->recordTrackPlayed($trackId, $userId, $timeOfPlay)) {
			throw new BusinessLayerException("Track with ID $trackId was not found");
		}

		// Update also "now playing" if the client hasn't updated it separately
		try {
			$nowPlaying = $this->getNowPlaying($userId);
		} catch (BusinessLayerException $e) {
			// malformed data, we can overwrite it no problem
			$nowPlaying = null;
		}

		if ($nowPlaying !== null) {
			$track = $nowPlaying['track'];
			$nowPlayingTimestamp = $nowPlaying['timeOfPlay'];

			// prevent the same track from getting an updated timestamp until the track is played through
			if ($track->getId() === $trackId && $timeOfPlay->getTimestamp() - $nowPlayingTimestamp < $track->getLength()) {
				return;
			}

			// rate-limit updates of now playing track when calling from recordTrackPlayed
			if ($timeOfPlay->getTimestamp() < $nowPlayingTimestamp + 3) {
				return;
			}
		}

		$this->setNowPlaying($trackId, $userId, $timeOfPlay);
	}

	/**
	 * Save the track to config as the "now playing" track with the provided timestamp
	 */
	public function setNowPlaying(int $trackId, string $userId, ?\DateTime $timeOfPlay = null) : void {
		$this->find($trackId, $userId);
		$data = [
			'trackId' => $trackId,
			'timeOfPlay' => ($timeOfPlay ?? new \DateTime())->getTimestamp()
		];
		$this->cache->set($userId, 'nowPlaying', \json_encode($data));
	}

	/**
	 * Return the "now playing" track along with its time of play
	 * @return ?array{track: Track, timeOfPlay: int} - null if no data available
	 * @throws BusinessLayerException if data available but somehow incorrect
	 */
	public function getNowPlaying(string $userId) : ?array {
		$rawData = $this->cache->get($userId, 'nowPlaying');
		if ($rawData === null) {
			return null;
		}

		$nowPlayingData = \json_decode($rawData, true);
		if (!isset($nowPlayingData['trackId'], $nowPlayingData['timeOfPlay'])) {
			throw new BusinessLayerException('Malformed now playing data');
		}

		[$trackId, $timeOfPlay] = ArrayUtil::multiGet($nowPlayingData, ['trackId', 'timeOfPlay']);

		$track = $this->find($trackId, $userId);

		return [
			'track' => $track,
			'timeOfPlay' => $timeOfPlay
		];
	}

	/**
	 * Adds a track if it does not exist already or updates an existing track
	 * @param string $title the title of the track
	 * @param int|null $number the number of the track
	 * @param int|null $discNumber the number of the disc
	 * @param int|null $year the year of the release
	 * @param int $genreId the genre id of the track
	 * @param int $artistId the artist id of the track
	 * @param int $albumId the album id of the track
	 * @param int $fileId the file id of the track
	 * @param string $mimetype the mimetype of the track
	 * @param string $userId the name of the user
	 * @param int $length track length in seconds
	 * @param int $bitrate track bitrate in bits (not kbits)
	 * @return Track The added/updated track
	 */
	public function addOrUpdateTrack(
			string $title, ?int $number, ?int $discNumber, ?int $year, int $genreId, int $artistId, int $albumId,
			int $fileId, string $mimetype, string $userId, ?int $length=null, ?int $bitrate=null) : Track {
		$track = new Track();
		$track->setTitle(StringUtil::truncate($title, 256)); // some DB setups can't truncate automatically to column max size
		$track->setNumber($number);
		$track->setDisk($discNumber);
		$track->setYear($year);
		$track->setGenreId($genreId);
		$track->setArtistId($artistId);
		$track->setAlbumId($albumId);
		$track->setFileId($fileId);
		$track->setMimetype($mimetype);
		$track->setUserId($userId);
		$track->setLength($length);
		$track->setBitrate($bitrate);
		$track->setDirty(0);
		return $this->mapper->insertOrUpdate($track);
	}

	/**
	 * Deletes tracks
	 * @param int[] $fileIds file IDs of the tracks to delete
	 * @param string[]|null $userIds the target users; if omitted, the tracks matching the
	 *                      $fileIds are deleted from all users
	 * @return array|false  False is returned if no such track was found; otherwise array of six arrays
	 *         (named 'deletedTracks', 'remainingAlbums', 'remainingArtists', 'obsoleteAlbums',
	 *         'obsoleteArtists', and 'affectedUsers'). These contain the track, album, artist, and
	 *         user IDs of the deleted tracks. The 'obsolete' entities are such which no longer
	 *         have any tracks while 'remaining' entities have some left.
	 */
	public function deleteTracks(array $fileIds, ?array $userIds=null) {
		$tracks = ($userIds !== null)
			? $this->mapper->findByFileIds($fileIds, $userIds)
			: $this->mapper->findAllByFileIds($fileIds);

		if (\count($tracks) === 0) {
			$result = false;
		} else {
			// delete all the matching tracks
			$trackIds = ArrayUtil::extractIds($tracks);
			$this->deleteById($trackIds);

			// find all distinct albums, artists, and users of the deleted tracks
			$artists = [];
			$albums = [];
			$users = [];
			foreach ($tracks as $track) {
				$artists[$track->getArtistId()] = 1;
				$albums[$track->getAlbumId()] = 1;
				$users[$track->getUserId()] = 1;
			}
			$artists = \array_keys($artists);
			$albums = \array_keys($albums);
			$users = \array_keys($users);

			// categorize each artist as 'remaining' or 'obsolete'
			$remainingArtists = [];
			$obsoleteArtists = [];
			foreach ($artists as $artistId) {
				if ($this->mapper->countByArtist($artistId) === 0) {
					$obsoleteArtists[] = $artistId;
				} else {
					$remainingArtists[] = $artistId;
				}
			}

			// categorize each album as 'remaining' or 'obsolete'
			$remainingAlbums = [];
			$obsoleteAlbums = [];
			foreach ($albums as $albumId) {
				if ($this->mapper->countByAlbum($albumId) === 0) {
					$obsoleteAlbums[] = $albumId;
				} else {
					$remainingAlbums[] = $albumId;
				}
			}

			$result = [
				'deletedTracks'    => $trackIds,
				'remainingAlbums'  => $remainingAlbums,
				'remainingArtists' => $remainingArtists,
				'obsoleteAlbums'   => $obsoleteAlbums,
				'obsoleteArtists'  => $obsoleteArtists,
				'affectedUsers'    => $users
			];
		}

		return $result;
	}

	/**
	 * Marks tracks as dirty, ultimately requesting the user to rescan them
	 * @param int[] $fileIds file IDs of the tracks to mark as dirty
	 * @param string[]|null $userIds the target users; if omitted, the tracks matching the
	 *                      $fileIds are marked for all users
	 */
	public function markTracksDirty(array $fileIds, ?array $userIds=null) : void {
		// be prepared for huge number of file IDs
		$chunkMaxSize = self::MAX_SQL_ARGS - \count($userIds ?? []);
		$idChunks = \array_chunk($fileIds, $chunkMaxSize);
		foreach ($idChunks as $idChunk) {
			$this->mapper->markTracksDirty($idChunk, $userIds);
		}
	}
}
