<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2024, 2025
 */

namespace OCA\Music\Controller;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\BookmarkBusinessLayer;
use OCA\Music\BusinessLayer\GenreBusinessLayer;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\BusinessLayer\PodcastChannelBusinessLayer;
use OCA\Music\BusinessLayer\PodcastEpisodeBusinessLayer;
use OCA\Music\BusinessLayer\RadioStationBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\BaseMapper;
use OCA\Music\Db\Entity;
use OCA\Music\Db\SortBy;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\Random;
use OCA\Music\Utility\StringUtil;

class AdvSearchController extends Controller {

	private AlbumBusinessLayer $albumBusinessLayer;
	private ArtistBusinessLayer $artistBusinessLayer;
	private BookmarkBusinessLayer $bookmarkBusinessLayer;
	private GenreBusinessLayer $genreBusinessLayer;
	private PlaylistBusinessLayer $playlistBusinessLayer;
	private PodcastChannelBusinessLayer $podcastChannelBusinessLayer;
	private PodcastEpisodeBusinessLayer $podcastEpisodeBusinessLayer;
	private RadioStationBusinessLayer $radioStationBusinessLayer;
	private TrackBusinessLayer $trackBusinessLayer;
	private ?string $userId;
	private Random $random;
	private Logger $logger;

	public function __construct(
			string $appName,
			IRequest $request,
			AlbumBusinessLayer $albumBusinessLayer,
			ArtistBusinessLayer $artistBusinessLayer,
			BookmarkBusinessLayer $bookmarkBusinessLayer,
			GenreBusinessLayer $genreBusinessLayer,
			PlaylistBusinessLayer $playlistBusinessLayer,
			PodcastChannelBusinessLayer $podcastChannelBusinessLayer,
			PodcastEpisodeBusinessLayer $podcastEpisodeBusinessLayer,
			RadioStationBusinessLayer $radioStationBusinessLayer,
			TrackBusinessLayer $trackBusinessLayer,
			?string $userId, // null if this gets called after the user has logged out or on a public page
			Random $random,
			Logger $logger
	) {
		parent::__construct($appName, $request);
		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->artistBusinessLayer = $artistBusinessLayer;
		$this->bookmarkBusinessLayer = $bookmarkBusinessLayer;
		$this->genreBusinessLayer = $genreBusinessLayer;
		$this->playlistBusinessLayer = $playlistBusinessLayer;
		$this->podcastChannelBusinessLayer = $podcastChannelBusinessLayer;
		$this->podcastEpisodeBusinessLayer = $podcastEpisodeBusinessLayer;
		$this->radioStationBusinessLayer = $radioStationBusinessLayer;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->userId = $userId;
		$this->random = $random;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function search(string $entity, string $rules, string $conjunction='and', string $order='name', ?int $limit=null, ?int $offset=null) : JSONResponse {
		$rules = \json_decode($rules, true);

		foreach ($rules as $rule) {
			if (empty($rule['rule']) || empty($rule['operator']) || !isset($rule['input'])) {
				return new ErrorResponse(Http::STATUS_BAD_REQUEST, 'Invalid search rule');
			}
		}

		try {
			$businessLayer = $this->businessLayerForType($entity);

			if ($businessLayer !== null) {
				\assert($this->userId !== null, 'Unexpected error: AdvSearch run with userId === null');
				$entities = $businessLayer->findAllAdvanced(
					$conjunction, $rules, $this->userId, self::mapSortBy($order), ($order==='random') ? $this->random : null, $limit, $offset);
				$entityIds = ArrayUtil::extractIds($entities);
				return new JSONResponse([
					'id' => \md5($entity.\serialize($entityIds)), // use hash => identical results will have identical ID
					StringUtil::snakeToCamelCase($entity).'Ids' => $entityIds,
					'date' => (new \DateTime())->format(BaseMapper::SQL_DATE_FORMAT),
					'criteria' => \compact('entity', 'rules', 'conjunction', 'order', 'limit', 'offset')
				]);
			} else {
				return new ErrorResponse(Http::STATUS_BAD_REQUEST, "Entity type '$entity' is not supported");
			}
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, $e->getMessage());
		}
	}

	/** @phpstan-return ?BusinessLayer<covariant Entity> */
	private function businessLayerForType(string $type) : ?BusinessLayer {
		$map = [
			'album' => $this->albumBusinessLayer,
			'artist' => $this->artistBusinessLayer,
			'bookmark' => $this->bookmarkBusinessLayer,
			'genre' => $this->genreBusinessLayer,
			'playlist' => $this->playlistBusinessLayer,
			'podcast_channel' => $this->podcastChannelBusinessLayer,
			'podcast_episode' => $this->podcastEpisodeBusinessLayer,
			'radio_station' => $this->radioStationBusinessLayer,
			'track' => $this->trackBusinessLayer,
		];
		return $map[$type] ?? null;
	}

	private static function mapSortBy(string $order) : int {
		$map = [
			'name'			=> SortBy::Name,
			'parent'		=> SortBy::Parent,
			'newest'		=> SortBy::Newest,
			'play_count'	=> SortBy::PlayCount,
			'last_played'	=> SortBy::LastPlayed,
			'rating'		=> SortBy::Rating,
		];
		return $map[$order] ?? SortBy::Name;
	}

}