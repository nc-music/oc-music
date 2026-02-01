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
 * @copyright Pauli Järvinen 2017 - 2025
 */

namespace OCA\Music\Controller;

use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppFramework\Utility\MethodAnnotationReader;
use OCA\Music\AppFramework\Utility\RequestParameterExtractor;
use OCA\Music\AppFramework\Utility\RequestParameterExtractorException;

use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\BookmarkBusinessLayer;
use OCA\Music\BusinessLayer\GenreBusinessLayer;
use OCA\Music\BusinessLayer\Library;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\BusinessLayer\PodcastChannelBusinessLayer;
use OCA\Music\BusinessLayer\PodcastEpisodeBusinessLayer;
use OCA\Music\BusinessLayer\RadioStationBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;

use OCA\Music\Db\Album;
use OCA\Music\Db\AmpacheSession;
use OCA\Music\Db\Artist;
use OCA\Music\Db\BaseMapper;
use OCA\Music\Db\Bookmark;
use OCA\Music\Db\Entity;
use OCA\Music\Db\Genre;
use OCA\Music\Db\RadioStation;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\Playlist;
use OCA\Music\Db\PodcastChannel;
use OCA\Music\Db\PodcastEpisode;
use OCA\Music\Db\SortBy;
use OCA\Music\Db\Track;

use OCA\Music\Http\ErrorResponse;
use OCA\Music\Http\FileResponse;
use OCA\Music\Http\FileStreamResponse;
use OCA\Music\Http\RelayStreamResponse;
use OCA\Music\Http\XmlResponse;

use OCA\Music\Middleware\AmpacheException;

use OCA\Music\Service\AmpacheImageService;
use OCA\Music\Service\AmpachePreferences;
use OCA\Music\Service\CoverService;
use OCA\Music\Service\DetailsService;
use OCA\Music\Service\FileSystemService;
use OCA\Music\Service\IScrobbler;
use OCA\Music\Service\LastfmService;
use OCA\Music\Service\LibrarySettings;
use OCA\Music\Service\PodcastService;

use OCA\Music\Utility\AppInfo;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\Random;
use OCA\Music\Utility\StringUtil;
use OCA\Music\Utility\Util;

class AmpacheController extends ApiController {
	private IConfig $config;
	private IL10N $l10n;
	private IURLGenerator $urlGenerator;
	private IUserManager $userManager;
	private AlbumBusinessLayer $albumBusinessLayer;
	private ArtistBusinessLayer $artistBusinessLayer;
	private BookmarkBusinessLayer $bookmarkBusinessLayer;
	private GenreBusinessLayer $genreBusinessLayer;
	private PlaylistBusinessLayer $playlistBusinessLayer;
	private PodcastChannelBusinessLayer $podcastChannelBusinessLayer;
	private PodcastEpisodeBusinessLayer $podcastEpisodeBusinessLayer;
	private RadioStationBusinessLayer $radioStationBusinessLayer;
	private TrackBusinessLayer $trackBusinessLayer;
	private Library $library;
	private PodcastService $podcastService;
	private AmpacheImageService $imageService;
	private CoverService $coverService;
	private DetailsService $detailsService;
	private FileSystemService $fileSystemService;
	private LastfmService $lastfmService;
	private LibrarySettings $librarySettings;
	private Random $random;
	private Logger $logger;
	private IScrobbler $scrobbler;

	private bool $jsonMode;
	private ?AmpacheSession $session;
	private array $namePrefixes;

	public const ALL_TRACKS_PLAYLIST_ID = -1;
	public const API4_VERSION = '4.4.0';
	public const API5_VERSION = '5.6.0';
	public const API6_VERSION = '6.7.1';
	public const API_MIN_COMPATIBLE_VERSION = '350001';

	public function __construct(
			string $appName,
			IRequest $request,
			IConfig $config,
			IL10N $l10n,
			IURLGenerator $urlGenerator,
			IUserManager $userManager,
			AlbumBusinessLayer $albumBusinessLayer,
			ArtistBusinessLayer $artistBusinessLayer,
			BookmarkBusinessLayer $bookmarkBusinessLayer,
			GenreBusinessLayer $genreBusinessLayer,
			PlaylistBusinessLayer $playlistBusinessLayer,
			PodcastChannelBusinessLayer $podcastChannelBusinessLayer,
			PodcastEpisodeBusinessLayer $podcastEpisodeBusinessLayer,
			RadioStationBusinessLayer $radioStationBusinessLayer,
			TrackBusinessLayer $trackBusinessLayer,
			Library $library,
			PodcastService $podcastService,
			AmpacheImageService $imageService,
			CoverService $coverService,
			DetailsService $detailsService,
			FileSystemService $fileSystemService,
			LastfmService $lastfmService,
			LibrarySettings $librarySettings,
			Random $random,
			Logger $logger,
			IScrobbler $scrobbler
	) {
		parent::__construct($appName, $request, 'POST, GET', 'Authorization, Content-Type, Accept, X-Requested-With');

		$this->config = $config;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->artistBusinessLayer = $artistBusinessLayer;
		$this->bookmarkBusinessLayer = $bookmarkBusinessLayer;
		$this->genreBusinessLayer = $genreBusinessLayer;
		$this->playlistBusinessLayer = $playlistBusinessLayer;
		$this->podcastChannelBusinessLayer = $podcastChannelBusinessLayer;
		$this->podcastEpisodeBusinessLayer = $podcastEpisodeBusinessLayer;
		$this->radioStationBusinessLayer = $radioStationBusinessLayer;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->library = $library;
		$this->podcastService = $podcastService;
		$this->imageService = $imageService;
		$this->coverService = $coverService;
		$this->detailsService = $detailsService;
		$this->fileSystemService = $fileSystemService;
		$this->lastfmService = $lastfmService;
		$this->librarySettings = $librarySettings;
		$this->random = $random;
		$this->logger = $logger;
		$this->scrobbler = $scrobbler;

		$this->jsonMode = false;
		$this->session = null;
		$this->namePrefixes = [];
	}

	public function setJsonMode(bool $useJsonMode) : void {
		$this->jsonMode = $useJsonMode;
	}

	public function setSession(AmpacheSession $session) : void {
		$this->session = $session;
		$this->namePrefixes = $this->librarySettings->getIgnoredArticles($session->getUserId());
	}

	public function ampacheResponse(array $content) : Response {
		if ($this->jsonMode) {
			return new JSONResponse($this->prepareResultForJsonApi($content));
		} else {
			return new XmlResponse($this->prepareResultForXmlApi($content), ['id', 'index', 'count', 'code', 'errorCode'], true, true, 'text');
		}
	}

	public function ampacheErrorResponse(int $code, string $message) : Response {
		$this->logger->debug($message);

		if ($this->apiMajorVersion() > 4) {
			$code = $this->mapApiV4ErrorToV5($code);
			$content = [
				'error' => [
					'errorCode' => (string)$code,
					'errorAction' => $this->request->getParam('action'),
					'errorType' => 'system',
					'errorMessage' => $message
				]
			];
		} else {
			$content = [
				'error' => [
					'code' => (string)$code,
					'text' => $message
				]
			];
		}
		return $this->ampacheResponse($content);
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 * @NoSameSiteCookieRequired
	 * @CORS
	 */
	public function xmlApi(string $action) : Response {
		// differentiation between xmlApi and jsonApi is made already by the middleware
		return $this->dispatch($action);
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 * @NoSameSiteCookieRequired
	 * @CORS
	 */
	public function jsonApi(string $action) : Response {
		// differentiation between xmlApi and jsonApi is made already by the middleware
		return $this->dispatch($action);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param string|int|bool $xml
	 */
	public function internalApi(string $action, /*mixed*/ $xml='0') : Response {
		$this->setJsonMode(!\filter_var($xml, FILTER_VALIDATE_BOOLEAN));
		return $this->dispatch($action);
	}

	protected function dispatch(string $action) : Response {
		$this->logger->debug("Ampache action '$action' requested");

		// Allow calling any functions annotated to be part of the API
		if (\method_exists($this, $action)) {
			$annotationReader = new MethodAnnotationReader($this, $action);
			if ($annotationReader->hasAnnotation('AmpacheAPI')) {
				// custom "filter" which modifies the value of the request argument `limit`
				$limitFilter = function(?string $value) : int {
					// The value "none" is is interpreted as "no limit".
					// Any other non-integer values and integer value 0 are interpreted as the default limit of 5000 entries
					if (StringUtil::caselessEqual($value, 'none')) {
						$value = Util::SINT32_MAX;
					} else {
						$value = (int)$value;
						if ($value <= 0) {
							$value = 5000;
						}
					}
					return $value;
				};

				$parameterExtractor = new RequestParameterExtractor($this->request, ['limit' => $limitFilter]);
				try {
					$parameterValues = $parameterExtractor->getParametersForMethod($this, $action);
				} catch (RequestParameterExtractorException $ex) {
					throw new AmpacheException($ex->getMessage(), 400);
				}
				$response = \call_user_func_array([$this, $action], $parameterValues);
				// The API methods may return either a Response object or an array, which should be converted to Response
				if (!($response instanceof Response)) {
					$response = $this->ampacheResponse($response);
				}
				return $response;
			}
		}

		// No method was found for this action
		$this->logger->warning("Unsupported Ampache action '$action' requested");
		throw new AmpacheException('Action not supported', 405);
	}

	/***********************
	 * Ampache API methods *
	 ***********************/

	/**
	 * Get the handshake result. The actual user authentication and session creation logic has happened prior to calling
	 * this in the class AmpacheMiddleware.
	 *
	 * @AmpacheAPI
	 */
	protected function handshake() : array {
		\assert($this->session !== null);
		$user = $this->userId();
		$updateTime = \max($this->library->latestUpdateTime($user), $this->playlistBusinessLayer->latestUpdateTime($user));
		$addTime = \max($this->library->latestInsertTime($user), $this->playlistBusinessLayer->latestInsertTime($user));
		$genresKey = $this->genreKey() . 's';
		$playlistCount = $this->playlistBusinessLayer->count($user);

		return [
			'session_expire' => \date('c', $this->session->getExpiry()),
			'auth' => $this->session->getToken(),
			'api' => $this->apiVersionString(),
			'update' => $updateTime->format('c'),
			'add' => $addTime->format('c'),
			'clean' => \date('c', \time()), // TODO: actual time of the latest item removal
			'songs' => $this->trackBusinessLayer->count($user),
			'artists' => $this->artistBusinessLayer->count($user),
			'albums' => $this->albumBusinessLayer->count($user),
			'playlists' => $playlistCount,
			'searches' => 1, // "All tracks"
			'playlists_searches' => $playlistCount + 1,
			'podcasts' => $this->podcastChannelBusinessLayer->count($user),
			'podcast_episodes' => $this->podcastEpisodeBusinessLayer->count($user),
			'live_streams' => $this->radioStationBusinessLayer->count($user),
			$genresKey => $this->genreBusinessLayer->count($user),
			'videos' => 0,
			'catalogs' => 0,
			'shares' => 0,
			'licenses' => 0,
			'labels' => 0,
			'max_song' => $this->trackBusinessLayer->maxId($user),
			'max_album' => $this->albumBusinessLayer->maxId($user),
			'max_artist' => $this->artistBusinessLayer->maxId($user),
			'max_video' => null,
			'max_podcast' => $this->podcastChannelBusinessLayer->maxId($user),
			'max_podcast_episode' => $this->podcastEpisodeBusinessLayer->maxId($user),
			'username' => $user
		];
	}

	/**
	 * Get the result for the 'goodbye' command. The actual logout is handled by AmpacheMiddleware.
	 *
	 * @AmpacheAPI
	 */
	protected function goodbye() : array {
		\assert($this->session !== null);
		return ['success' => "goodbye: {$this->session->getToken()}"];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function ping() : array {
		$response = [
			'server' => AppInfo::getFullName() . ' ' . AppInfo::getVersion(),
			'version' => $this->apiVersionString(),
			'compatible' => self::API_MIN_COMPATIBLE_VERSION
		];

		if ($this->session) {
			// in case ping is called within a valid session, the response will contain also the "handshake fields"
			$response += $this->handshake();
		}

		return $response;
	}

	/**
	 * @AmpacheAPI
	 */
	protected function get_indexes(string $type, ?string $filter, ?string $add, ?string $update, ?bool $include, int $limit, int $offset=0) : array {
		if ($type === 'album_artist' || $type === 'song_artist') {
			list($addMin, $addMax, $updateMin, $updateMax) = self::parseTimeParameters($add, $update);
			if ($type === 'album_artist') {
				$entities = $this->artistBusinessLayer->findAllHavingAlbums(
					$this->userId(), SortBy::Name, $limit, $offset, $filter, MatchMode::Substring, $addMin, $addMax, $updateMin, $updateMax);
			} else {
				$entities = $this->artistBusinessLayer->findAllHavingTracks(
					$this->userId(), SortBy::Name, $limit, $offset, $filter, MatchMode::Substring, $addMin, $addMax, $updateMin, $updateMax);
			}
			$type = 'artist';
		} else {
			$businessLayer = $this->getBusinessLayer($type);
			$entities = $this->findEntities($businessLayer, $filter, false, $limit, $offset, $add, $update);
		}

		// We support the 'include' argument only for podcasts. On the original Ampache server, also other types have support but
		// only 'podcast' and 'playlist' are documented to be supported and the implementation is really messy for the 'playlist'
		// type, with inconsistencies between XML and JSON formats and XML-structures unlike any other actions.
		if ($type == 'podcast' && $include) {
			$this->injectEpisodesToChannels($entities);
		}

		return $this->renderEntitiesIndex($entities, $type);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function index(
			string $type, ?string $filter, ?string $add, ?string $update,
			?bool $include, int $limit, int $offset=0, bool $exact=false) : array {
		$userId = $this->userId();
		
		if ($type === 'album_artist' || $type === 'song_artist') {
			list($addMin, $addMax, $updateMin, $updateMax) = self::parseTimeParameters($add, $update);
			$matchMode = $exact ? MatchMode::Exact : MatchMode::Substring;
			if ($type === 'album_artist') {
				$entities = $this->artistBusinessLayer->findAllHavingAlbums(
					$userId, SortBy::Name, $limit, $offset, $filter, $matchMode, $addMin, $addMax, $updateMin, $updateMax);
			} else {
				$entities = $this->artistBusinessLayer->findAllHavingTracks(
					$userId, SortBy::Name, $limit, $offset, $filter, $matchMode, $addMin, $addMax, $updateMin, $updateMax);
			}
		} else {
			$businessLayer = $this->getBusinessLayer($type);
			$entities = $this->findEntities($businessLayer, $filter, $exact, $limit, $offset, $add, $update);
		}

		if ($include) {
			$childType = self::getChildEntityType($type);
			if ($childType !== null) {
				if ($type == 'playlist') {
					$idsWithChildren = [];
					foreach ($entities as $playlist) {
						\assert($playlist instanceof Playlist);
						$idsWithChildren[$playlist->getId()] = $playlist->getTrackIdsAsArray();
					}
				} else {
					$idsWithChildren = $this->getBusinessLayer($childType)->findAllIdsByParentIds($userId, ArrayUtil::extractIds($entities));
				}
				return $this->renderIdsWithChildren($idsWithChildren, $type, $childType);
			}
		}

		return $this->renderEntityIdIndex($entities, $type);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function list(string $type, ?string $filter, ?string $add, ?string $update, int $limit, int $offset=0) : array {
		$isAlbumArtist = ($type == 'album_artist');
		if ($isAlbumArtist) {
			$type = 'artist';
		}

		list($addMin, $addMax, $updateMin, $updateMax) = self::parseTimeParameters($add, $update);

		$businessLayer = $this->getBusinessLayer($type);
		$entities = $businessLayer->findAllIdsAndNames(
			$this->userId(), $this->l10n, null, $limit, $offset, $addMin, $addMax, $updateMin, $updateMax, $isAlbumArtist, $filter);

		return [
			'list' => \array_map(
				fn($idAndName) => $idAndName + $this->prefixAndBaseName($idAndName['name']),
				$entities
			)
		];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function browse(string $type, ?string $filter, ?string $add, ?string $update, int $limit, int $offset=0) : array {
		// note: the argument 'catalog' is disregarded in our implementation
		if ($type == 'root') {
			$catalogId = null;
			$childType = 'catalog';
			$children = [
				['id' => 'music', 'name' => 'music'],
				['id' => 'podcasts', 'name' => 'podcasts']
			];
		} else {
			if ($type == 'catalog') {
				$catalogId = null;
				$parentId = null;

				switch ($filter) {
					case 'music':
						$childType = 'artist';
						break;
					case 'podcasts':
						$childType = 'podcast';
						break;
					default:
						throw new AmpacheException("Filter '$filter' is not a valid catalog", 400);
				}
			} else {
				$catalogId = StringUtil::startsWith($type, 'podcast') ? 'podcasts' : 'music';
				$parentId = empty($filter) ? null : (int)$filter;

				switch ($type) {
					case 'podcast':
						$childType = 'podcast_episode';
						break;
					case 'artist':
						$childType = 'album';
						break;
					case 'album':
						$childType = 'song';
						break;
					default:
						throw new AmpacheException("Type '$type' is not supported", 400);
				}
			}

			$businessLayer = $this->getBusinessLayer($childType);
			list($addMin, $addMax, $updateMin, $updateMax) = self::parseTimeParameters($add, $update);
			$children = $businessLayer->findAllIdsAndNames(
				$this->userId(), $this->l10n, $parentId, $limit, $offset, $addMin, $addMax, $updateMin, $updateMax, true);
		}

		return [
			'catalog_id' => $catalogId,
			'parent_id' => $filter,
			'parent_type' => $type,
			'child_type' => $childType,
			'browse' => \array_map(fn($idAndName) => $idAndName + $this->prefixAndBaseName($idAndName['name']), $children)
		];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function stats(string $type, ?string $filter, int $limit, int $offset=0) : array {
		$userId = $this->userId();

		// Support for API v3.x: Originally, there was no 'filter' argument and the 'type'
		// argument had that role. The action only supported albums in this old format.
		// The 'filter' argument was added and role of 'type' changed in API v4.0.
		if (empty($filter)) {
			$filter = $type;
			$type = 'album';
		}

		// Note: In addition to types specified in APIv6, we support also types 'genre' and 'live_stream'
		// as that's possible without extra effort. All types don't support all possible filters.
		$businessLayer = $this->getBusinessLayer($type);

		$getEntitiesIfSupported = function(
				BusinessLayer $businessLayer, string $method, string $userId,
				int $limit, int $offset) use ($type, $filter) {
			if (\method_exists($businessLayer, $method)) {
				return $businessLayer->$method($userId, $limit, $offset);
			} else {
				throw new AmpacheException("Filter $filter not supported for type $type", 400);
			}
		};

		switch ($filter) {
			case 'newest':
				$entities = $businessLayer->findAll($userId, SortBy::Newest, $limit, $offset);
				break;
			case 'flagged':
				$entities = $businessLayer->findAllStarred($userId, $limit, $offset);
				break;
			case 'random':
				$entities = $businessLayer->findAll($userId, SortBy::Name);
				$indices = $this->random->getIndices(\count($entities), $offset, $limit, $userId, 'ampache_stats_'.$type);
				$entities = ArrayUtil::multiGet($entities, $indices);
				break;
			case 'frequent':
				$entities = $getEntitiesIfSupported($businessLayer, 'findFrequentPlay', $userId, $limit, $offset);
				break;
			case 'recent':
				$entities = $getEntitiesIfSupported($businessLayer, 'findRecentPlay', $userId, $limit, $offset);
				break;
			case 'forgotten':
				$entities = $getEntitiesIfSupported($businessLayer, 'findNotRecentPlay', $userId, $limit, $offset);
				break;
			case 'highest':
				$entities = $businessLayer->findAllRated($userId, $limit, $offset);
				break;
			default:
				throw new AmpacheException("Unsupported filter $filter", 400);
		}

		return $this->renderEntities($entities, $type);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function artists(
			?string $filter, ?string $add, ?string $update, int $limit, int $offset=0,
			bool $exact=false, bool $album_artist=false, ?string $include=null) : array {
		$userId = $this->userId();

		if ($album_artist) {
			$matchMode =  $exact ? MatchMode::Exact : MatchMode::Substring;
			list($addMin, $addMax, $updateMin, $updateMax) = self::parseTimeParameters($add, $update);
			$artists = $this->artistBusinessLayer->findAllHavingAlbums(
				$userId, SortBy::Name, $limit, $offset, $filter, $matchMode, $addMin, $addMax, $updateMin, $updateMax);
		} else {
			$artists = $this->findEntities($this->artistBusinessLayer, $filter, $exact, $limit, $offset, $add, $update);
		}

		$include = Util::explode(',', $include);
		if (\in_array('songs', $include)) {
			$this->library->injectTracksToArtists($artists, $userId);
		}
		if (\in_array('albums', $include)) {
			$this->library->injectAlbumsToArtists($artists, $userId);
		}

		return $this->renderArtists($artists);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function artist(int $filter, ?string $include) : array {
		$userId = $this->userId();
		$artists = [$this->artistBusinessLayer->find($filter, $userId)];

		$include = Util::explode(',', $include);
		if (\in_array('songs', $include)) {
			$this->library->injectTracksToArtists($artists, $userId);
		}
		if (\in_array('albums', $include)) {
			$this->library->injectAlbumsToArtists($artists, $userId);
		}

		return $this->renderArtists($artists);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function artist_albums(int $filter, int $limit, int $offset=0) : array {
		$userId = $this->userId();
		$albums = $this->albumBusinessLayer->findAllByArtist($filter, $userId, $limit, $offset);
		return $this->renderAlbums($albums);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function artist_songs(int $filter, int $limit, int $offset=0, bool $top50=false) : array {
		$userId = $this->userId();
		if ($top50) {
			$tracks = $this->lastfmService->getTopTracks($filter, $userId, 50);
			$tracks = \array_slice($tracks, $offset, $limit);
		} else {
			$tracks = $this->trackBusinessLayer->findAllByArtist($filter, $userId, $limit, $offset);
		}
		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function album_songs(int $filter, int $limit, int $offset=0) : array {
		$userId = $this->userId();
		$tracks = $this->trackBusinessLayer->findAllByAlbum($filter, $userId, null, $limit, $offset);
		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function song(int $filter) : array {
		$userId = $this->userId();
		$track = $this->trackBusinessLayer->find($filter, $userId);

		// parse and include also lyrics when fetching an individual song
		$rootFolder = $this->librarySettings->getFolder($userId);
		$lyrics = $this->detailsService->getLyricsAsPlainText($track->getFileId(), $rootFolder);
		if ($lyrics !== null) {
			$lyrics = \mb_ereg_replace("\n", "<br />", $lyrics); // It's not documented but Ampache proper uses HTML line breaks for the lyrics
			$track->setLyrics($lyrics);
		}

		return $this->renderSongs([$track]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function songs(
			?string $filter, ?string $add, ?string $update,
			int $limit, int $offset=0, bool $exact=false) : array {

		$tracks = $this->findEntities($this->trackBusinessLayer, $filter, $exact, $limit, $offset, $add, $update);
		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function search_songs(string $filter, int $limit, int $offset=0) : array {
		$userId = $this->userId();
		$tracks = $this->trackBusinessLayer->findAllByNameRecursive($filter, $userId, $limit, $offset);
		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function albums(
			?string $filter, ?string $add, ?string $update, int $limit, int $offset=0,
			bool $exact=false, ?string $include=null) : array {

		$albums = $this->findEntities($this->albumBusinessLayer, $filter, $exact, $limit, $offset, $add, $update);

		if ($include == 'songs') {
			$this->library->injectTracksToAlbums($albums, $this->userId());
		}

		return $this->renderAlbums($albums);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function album(int $filter, ?string $include) : array {
		$userId = $this->userId();
		$albums = [$this->albumBusinessLayer->find($filter, $userId)];

		if ($include == 'songs') {
			$this->library->injectTracksToAlbums($albums, $userId);
		}

		return $this->renderAlbums($albums);
	}

	/**
	 * @AmpacheAPI
	 *
	 * This is a proprietary extension to the API
	 */
	protected function folders(int $limit, int $offset=0) : array {
		$userId = $this->userId();
		$musicFolder = $this->librarySettings->getFolder($userId);
		$folders = $this->fileSystemService->findAllFolders($userId, $musicFolder);

		// disregard any (parent) folders without any direct track children
		$folders = \array_filter($folders, fn($folder) => \count($folder['trackIds']) > 0);

		ArrayUtil::sortByColumn($folders, 'name');
		$folders = \array_slice($folders, $offset, $limit);

		return [
			'folder' => \array_map(fn($folder) => [
				'id' => (string)$folder['id'],
				'name' => $folder['name'],
			], $folders)
		];
	}

	/**
	 * @AmpacheAPI
	 *
	 * This is a proprietary extension to the API
	 */
	protected function folder_songs(int $filter, int $limit, int $offset=0) : array {
		$userId = $this->userId();
		$tracks = $this->trackBusinessLayer->findAllByFolder($filter, $userId, $limit, $offset);
		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function get_similar(string $type, int $filter, int $limit, int $offset=0) : array {
		$userId = $this->userId();
		if ($type == 'artist') {
			$entities = $this->lastfmService->getSimilarArtists($filter, $userId);
		} elseif ($type == 'song') {
			$entities = $this->lastfmService->getSimilarTracks($filter, $userId);
		} else {
			throw new AmpacheException("Type '$type' is not supported", 400);
		}
		$entities = \array_slice($entities, $offset, $limit);
		return $this->renderEntities($entities, $type);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlists(
			?string $filter, ?string $add, ?string $update, int $limit, int $offset=0,
			bool $exact=false, bool $hide_search=false, bool $include=false) : array {

		$userId = $this->userId();
		$playlists = $this->findEntities($this->playlistBusinessLayer, $filter, $exact, $limit, $offset, $add, $update);

		// append "All tracks" if "searches" are not forbidden, and not filtering by any criteria, and it is not off-limits
		$allTracksIndex = $this->playlistBusinessLayer->count($userId);
		if (!$hide_search && empty($filter) && empty($add) && empty($update)
				&& self::indexIsWithinOffsetAndLimit($allTracksIndex, $offset, $limit)) {
			$playlists[] = $this->getAllTracksPlaylist();
		}

		return $this->renderPlaylists($playlists, $include);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function user_playlists(
			?string $filter, ?string $add, ?string $update,	int $limit, int $offset=0, bool $exact=false) : array {
		// alias for playlists without smart lists
		return $this->playlists($filter, $add, $update, $limit, $offset, $exact, true);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function user_smartlists() : array {
		// the only "smart list" currently supported is "All tracks", hence supporting any kind of filtering criteria
		// isn't worthwhile
		return $this->renderPlaylists([$this->getAllTracksPlaylist()]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist(int $filter, bool $include=false) : array {
		$userId = $this->userId();
		if ($filter == self::ALL_TRACKS_PLAYLIST_ID) {
			$playlist = $this->getAllTracksPlaylist();
		} else {
			$playlist = $this->playlistBusinessLayer->find($filter, $userId);
		}
		return $this->renderPlaylists([$playlist], $include);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_hash(int $filter) : array {
		if ($filter == self::ALL_TRACKS_PLAYLIST_ID) {
			$playlist = $this->getAllTracksPlaylist();
		} else {
			$playlist = $this->playlistBusinessLayer->find($filter, $this->userId());
		}
		return [ 'md5' => $playlist->getTrackIdsHash() ];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_songs(int $filter, int $limit, int $offset=0, bool $random=false) : array {
		// In random mode, the pagination is handled manually after fetching the songs. Declare $rndLimit and $rndOffset
		// regardless of the random mode because PHPStan and Scrutinizer are not smart enough to otherwise know that they
		// are guaranteed to be defined in the second random block in the end of this function.
		$rndLimit = $limit;
		$rndOffset = $offset;
		if ($random) {
			$limit = null;
			$offset = null;
		}

		$userId = $this->userId();
		if ($filter == self::ALL_TRACKS_PLAYLIST_ID) {
			$tracks = $this->trackBusinessLayer->findAll($userId, SortBy::Parent, $limit, $offset);
			foreach ($tracks as $index => $track) {
				$track->setNumberOnPlaylist($index + 1);
			}
		} else {
			$tracks = $this->playlistBusinessLayer->getPlaylistTracks($filter, $userId, $limit, $offset);
		}

		if ($random) {
			$indices = $this->random->getIndices(\count($tracks), $rndOffset, $rndLimit, $userId, 'ampache_playlist_songs');
			$tracks = ArrayUtil::multiGet($tracks, $indices);
		}

		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_create(string $name) : array {
		$playlist = $this->playlistBusinessLayer->create($name, $this->userId());
		return $this->renderPlaylists([$playlist]);
	}

	/**
	 * @AmpacheAPI
	 *
	 * @param int $filter Playlist ID
	 * @param ?string $name New name for the playlist
	 * @param ?string $items Track IDs
	 * @param ?string $tracks 1-based indices of the tracks
	 */
	protected function playlist_edit(int $filter, ?string $name, ?string $items, ?string $tracks) : array {
		$edited = false;
		$userId = $this->userId();

		if (!empty($name)) {
			$this->playlistBusinessLayer->rename($name, $filter, $userId);
			$edited = true;
		}

		$newTrackIds = \array_map('intval', Util::explode(',', $items));
		$newTrackOrdinals = \array_map('intval', Util::explode(',', $tracks));

		if (\count($newTrackIds) != \count($newTrackOrdinals)) {
			throw new AmpacheException("Arguments 'items' and 'tracks' must contain equal amount of elements", 400);
		} elseif (\count($newTrackIds) > 0) {
			$trackIds = $this->playlistBusinessLayer->getPlaylistTrackIds($filter, $userId);

			for ($i = 0, $count = \count($newTrackIds); $i < $count; ++$i) {
				$trackId = $newTrackIds[$i];
				if (!$this->trackBusinessLayer->exists($trackId, $userId)) {
					throw new AmpacheException("Invalid song ID $trackId", 404);
				}
				$trackIds[$newTrackOrdinals[$i]-1] = $trackId;
			}

			$this->playlistBusinessLayer->setTracks($trackIds, $filter, $userId);
			$edited = true;
		}

		if ($edited) {
			return ['success' => 'playlist changes saved'];
		} else {
			throw new AmpacheException('Nothing was changed', 400);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_delete(int $filter) : array {
		$this->playlistBusinessLayer->delete($filter, $this->userId());
		return ['success' => 'playlist deleted'];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_add_song(int $filter, int $song, bool $check=false) : array {
		$userId = $this->userId();
		if (!$this->trackBusinessLayer->exists($song, $userId)) {
			throw new AmpacheException("Invalid song ID $song", 404);
		}

		$trackIds = $this->playlistBusinessLayer->getPlaylistTrackIds($filter, $userId);

		if ($check && \in_array($song, $trackIds)) {
			throw new AmpacheException("Can't add a duplicate item when check is enabled", 400);
		}

		$trackIds[] = $song;
		$this->playlistBusinessLayer->setTracks($trackIds, $filter, $userId);
		return ['success' => 'song added to playlist'];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_add(int $filter, int $id, string $type) : array {
		$userId = $this->userId();

		if (!$this->getBusinessLayer($type)->exists($id, $userId)) {
			throw new AmpacheException("Invalid $type ID $id", 404);
		}

		$trackIds = $this->playlistBusinessLayer->getPlaylistTrackIds($filter, $userId);

		$newIds = $this->trackIdsForEntity($id, $type);
		$trackIds = \array_merge($trackIds, $newIds);

		$this->playlistBusinessLayer->setTracks($trackIds, $filter, $userId);
		return ['success' => "songs added to playlist"];
	}

	/**
	 * @AmpacheAPI
	 *
	 * @param int $filter Playlist ID
	 * @param ?int $song Track ID
	 * @param ?int $track 1-based index of the track
	 * @param ?int $clear Value 1 erases all the songs from the playlist
	 */
	protected function playlist_remove_song(int $filter, ?int $song, ?int $track, ?int $clear) : array {
		$userId = $this->userId();
		$trackIds = $this->playlistBusinessLayer->getPlaylistTrackIds($filter, $userId);

		if ($clear === 1) {
			$trackIds = [];
			$message = 'all songs removed from playlist';
		} elseif ($song !== null) {
			if (!\in_array($song, $trackIds)) {
				throw new AmpacheException("Song $song not found in playlist", 404);
			}
			$trackIds = ArrayUtil::diff($trackIds, [$song]);
			$message = 'song removed from playlist';
		} elseif ($track !== null) {
			if ($track < 1 || $track > \count($trackIds)) {
				throw new AmpacheException("Track ordinal $track is out of bounds", 404);
			}
			unset($trackIds[$track-1]);
			$message = 'song removed from playlist';
		} else {
			throw new AmpacheException("One of the arguments 'clear', 'song', 'track' is required", 400);
		}

		$this->playlistBusinessLayer->setTracks($trackIds, $filter, $userId);
		return ['success' => $message];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function playlist_generate(
			?string $filter, ?int $album, ?int $artist, ?int $flag,
			int $limit, int $offset=0, string $mode='random', string $format='song') : array {

		$tracks = $this->findEntities($this->trackBusinessLayer, $filter, false); // $limit and $offset are applied later

		// filter the found tracks according to the additional requirements
		if ($album !== null) {
			$tracks = \array_filter($tracks, fn($track) => ($track->getAlbumId() == $album));
		}
		if ($artist !== null) {
			$tracks = \array_filter($tracks, fn($track) => ($track->getArtistId() == $artist));
		}
		if ($flag == 1) {
			$tracks = \array_filter($tracks, fn($track) => ($track->getStarred() !== null));
		}
		// After filtering, there may be "holes" between the array indices. Reindex the array.
		$tracks = \array_values($tracks);

		if ($mode == 'random') {
			$userId = $this->userId();
			$indices = $this->random->getIndices(\count($tracks), $offset, $limit, $userId, 'ampache_playlist_generate');
			$tracks = ArrayUtil::multiGet($tracks, $indices);
		} else { // 'recent', 'forgotten', 'unplayed'
			throw new AmpacheException("Mode '$mode' is not supported", 400);
		}

		switch ($format) {
			case 'song':
				return $this->renderSongs($tracks);
			case 'index':
				return $this->renderSongsIndex($tracks);
			case 'id':
				return $this->renderEntityIds($tracks);
			default:
				throw new AmpacheException("Format '$format' is not supported", 400);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function podcasts(?string $filter, ?string $include, int $limit, int $offset=0, bool $exact=false) : array {
		$channels = $this->findEntities($this->podcastChannelBusinessLayer, $filter, $exact, $limit, $offset);

		if ($include === 'episodes') {
			$this->injectEpisodesToChannels($channels);
		}

		return $this->renderPodcastChannels($channels);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function podcast(int $filter, ?string $include) : array {
		$userId = $this->userId();
		$channel = $this->podcastChannelBusinessLayer->find($filter, $userId);

		if ($include === 'episodes') {
			$channel->setEpisodes($this->podcastEpisodeBusinessLayer->findAllByChannel($filter, $userId));
		}

		return $this->renderPodcastChannels([$channel]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function podcast_create(string $url) : array {
		$result = $this->podcastService->subscribe($url, $this->userId());

		switch ($result['status']) {
			case PodcastService::STATUS_OK:
				return $this->renderPodcastChannels([$result['channel']]);
			case PodcastService::STATUS_INVALID_URL:
				throw new AmpacheException("Invalid URL $url", 400);
			case PodcastService::STATUS_INVALID_RSS:
				throw new AmpacheException("The document at URL $url is not a valid podcast RSS feed", 400);
			case PodcastService::STATUS_ALREADY_EXISTS:
				throw new AmpacheException('User already has this podcast channel subscribed', 400);
			default:
				throw new AmpacheException("Unexpected status code {$result['status']}", 400);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function podcast_delete(int $filter) : array {
		$status = $this->podcastService->unsubscribe($filter, $this->userId());

		switch ($status) {
			case PodcastService::STATUS_OK:
				return ['success' => 'podcast deleted'];
			case PodcastService::STATUS_NOT_FOUND:
				throw new AmpacheException('Channel to be deleted not found', 404);
			default:
				throw new AmpacheException("Unexpected status code $status", 400);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function podcast_episodes(int $filter, int $limit, int $offset=0) : array {
		$episodes = $this->podcastEpisodeBusinessLayer->findAllByChannel($filter, $this->userId(), $limit, $offset);
		return $this->renderPodcastEpisodes($episodes);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function podcast_episode(int $filter) : array {
		$episode = $this->podcastEpisodeBusinessLayer->find($filter, $this->userId());
		return $this->renderPodcastEpisodes([$episode]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function update_podcast(int $id) : array {
		$result = $this->podcastService->updateChannel($id, $this->userId());

		switch ($result['status']) {
			case PodcastService::STATUS_OK:
				$message = $result['updated'] ? 'channel was updated from the source' : 'no changes found';
				return ['success' => $message];
			case PodcastService::STATUS_NOT_FOUND:
				throw new AmpacheException('Channel to be updated not found', 404);
			case PodcastService::STATUS_INVALID_URL:
				throw new AmpacheException('failed to read from the channel URL', 400);
			case PodcastService::STATUS_INVALID_RSS:
				throw new AmpacheException('the document at the channel URL is not a valid podcast RSS feed', 400);
			default:
				throw new AmpacheException("Unexpected status code {$result['status']}", 400);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function live_streams(?string $filter, int $limit, int $offset=0, bool $exact=false) : array {
		$stations = $this->findEntities($this->radioStationBusinessLayer, $filter, $exact, $limit, $offset);
		return $this->renderLiveStreams($stations);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function live_stream(int $filter) : array {
		$station = $this->radioStationBusinessLayer->find($filter, $this->userId());
		return $this->renderLiveStreams([$station]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function live_stream_create(string $name, string $url, ?string $site_url) : array {
		$station = $this->radioStationBusinessLayer->create($this->userId(), $name, $url, $site_url);
		return $this->renderLiveStreams([$station]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function live_stream_delete(int $filter) : array {
		$this->radioStationBusinessLayer->delete($filter, $this->userId());
		return ['success' => "Deleted live stream: $filter"];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function live_stream_edit(int $filter, ?string $name, ?string $url, ?string $site_url) : array {
		$station = $this->radioStationBusinessLayer->updateStation($filter, $this->userId(), $name, $url, $site_url);
		return $this->renderLiveStreams([$station]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function tags(?string $filter, int $limit, int $offset=0, bool $exact=false) : array {
		$genres = $this->findEntities($this->genreBusinessLayer, $filter, $exact, $limit, $offset);
		return $this->renderTags($genres);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function tag(int $filter) : array {
		$genre = $this->genreBusinessLayer->find($filter, $this->userId());
		return $this->renderTags([$genre]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function tag_artists(int $filter, int $limit, int $offset=0) : array {
		$artists = $this->artistBusinessLayer->findAllByGenre($filter, $this->userId(), $limit, $offset);
		return $this->renderArtists($artists);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function tag_albums(int $filter, int $limit, int $offset=0) : array {
		$albums = $this->albumBusinessLayer->findAllByGenre($filter, $this->userId(), $limit, $offset);
		return $this->renderAlbums($albums);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function tag_songs(int $filter, int $limit, int $offset=0) : array {
		$tracks = $this->trackBusinessLayer->findAllByGenre($filter, $this->userId(), $limit, $offset);
		return $this->renderSongs($tracks);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function genres(?string $filter, int $limit, int $offset=0, bool $exact=false) : array {
		$genres = $this->findEntities($this->genreBusinessLayer, $filter, $exact, $limit, $offset);
		return $this->renderGenres($genres);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function genre(int $filter) : array {
		$genre = $this->genreBusinessLayer->find($filter, $this->userId());
		return $this->renderGenres([$genre]);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function genre_artists(?int $filter, int $limit, int $offset=0) : array {
		if ($filter === null) {
			return $this->artists(null, null, null, $limit, $offset);
		} else {
			return $this->tag_artists($filter, $limit, $offset);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function genre_albums(?int $filter, int $limit, int $offset=0) : array {
		if ($filter === null) {
			return $this->albums(null, null, null, $limit, $offset);
		} else {
			return $this->tag_albums($filter, $limit, $offset);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function genre_songs(?int $filter, int $limit, int $offset=0) : array {
		if ($filter === null) {
			return $this->songs(null, null, null, $limit, $offset);
		} else {
			return $this->tag_songs($filter, $limit, $offset);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function bookmarks(int $include=0) : array {
		$bookmarks = $this->bookmarkBusinessLayer->findAll($this->userId());
		return $this->renderBookmarks($bookmarks, $include);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function bookmark(int $filter, int $include=0) : array {
		$bookmark = $this->bookmarkBusinessLayer->find($filter, $this->userId());
		return $this->renderBookmarks([$bookmark], $include);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function get_bookmark(int $filter, string $type, int $include=0, int $all=0) : array {
		// first check the validity of the entity identified by $type and $filter
		$this->getBusinessLayer($type)->find($filter, $this->userId()); // throws if entity doesn't exist

		$entryType = self::mapBookmarkType($type);
		try {
			// we currently support only one bookmark per song/episode but the Ampache API doesn't have this limitation
			$bookmarks = [$this->bookmarkBusinessLayer->findByEntry($entryType, $filter, $this->userId())];
		} catch (BusinessLayerException $ex) {
			$bookmarks = [];
		}

		if (!$all && empty($bookmarks)) {
			// It's a special case when a single bookmark is requested but there is none, in that case we
			// return a completely empty response. Most other actions return an error in similar cases.
			return [];
		} else {
			return $this->renderBookmarks($bookmarks, $include);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function bookmark_create(int $filter, string $type, int $position, ?string $client, int $include=0) : array {
		// Note: the optional argument 'date' is not supported and is disregarded
		$entryType = self::mapBookmarkType($type);
		$position *= 1000; // seconds to milliseconds
		$bookmark = $this->bookmarkBusinessLayer->addOrUpdate($this->userId(), $entryType, $filter, $position, $client);
		return $this->renderBookmarks([$bookmark], $include);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function bookmark_edit(int $filter, string $type, int $position, ?string $client, int $include=0) : array {
		// Note: the optional argument 'date' is not supported and is disregarded
		$entryType = self::mapBookmarkType($type);
		$position *= 1000; // seconds to milliseconds
		$bookmark = $this->bookmarkBusinessLayer->updateByEntry($this->userId(), $entryType, $filter, $position, $client);
		return $this->renderBookmarks([$bookmark], $include);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function bookmark_delete(int $filter, string $type) : array {
		$entryType = self::mapBookmarkType($type);
		$bookmark = $this->bookmarkBusinessLayer->findByEntry($entryType, $filter, $this->userId());
		$this->bookmarkBusinessLayer->delete($bookmark->getId(), $bookmark->getUserId());
		return ['success' => "Deleted Bookmark: $type $filter"];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function advanced_search(int $limit, int $offset=0, string $type='song', string $operator='and', bool $random=false) : array {
		// get all the rule parameters as passed on the HTTP call
		$rules = self::advSearchGetRuleParams($this->request->getParams());

		// apply some conversions on the rules
		foreach ($rules as &$rule) {
			$rule['rule'] = self::advSearchResolveRuleAlias($rule['rule']);
			$rule['operator'] = self::advSearchInterpretOperator($rule['operator'], $rule['rule']);
			$rule['input'] = self::advSearchConvertInput($rule['input'], $rule['rule']);
		}

		// types 'album_artist' and 'song_artist' are just 'artist' searches with some extra conditions
		if ($type == 'album_artist') {
			$rules[] = ['rule' => 'album_count', 'operator' => '>', 'input' => '0'];
			$type = 'artist';
		} elseif ($type == 'song_artist') {
			$rules[] = ['rule' => 'song_count', 'operator' => '>', 'input' => '0'];
			$type = 'artist';
		}

		try {
			$businessLayer = $this->getBusinessLayer($type);
			$userId = $this->userId();
			$entities = $businessLayer->findAllAdvanced($operator, $rules, $userId, SortBy::Name, $random ? $this->random : null, $limit, $offset);
		} catch (BusinessLayerException $e) {
			throw new AmpacheException($e->getMessage(), 400);
		}
		
		return $this->renderEntities($entities, $type);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function search(int $limit, int $offset=0, string $type='song', string $operator='and', bool $random=false) : array {
		// this is an alias
		return $this->advanced_search($limit, $offset, $type, $operator, $random);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function flag(string $type, int $id, bool $flag) : array {
		if (!\in_array($type, ['song', 'album', 'artist', 'podcast', 'podcast_episode', 'playlist'])) {
			throw new AmpacheException("Unsupported type $type", 400);
		}

		$userId = $this->userId();
		$businessLayer = $this->getBusinessLayer($type);
		if ($flag) {
			$modifiedCount = $businessLayer->setStarred([$id], $userId);
			$message = "flag ADDED to $type $id";
		} else {
			$modifiedCount = $businessLayer->unsetStarred([$id], $userId);
			$message = "flag REMOVED from $type $id";
		}

		if ($modifiedCount > 0) {
			return ['success' => $message];
		} else {
			throw new AmpacheException("The $type $id was not found", 404);
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function rate(string $type, int $id, int $rating) : array {
		$rating = (int)Util::limit($rating, 0, 5);
		$businessLayer = $this->getBusinessLayer($type);
		try {
			$businessLayer->setRating($id, $rating, $this->userId());
		} catch (\BadMethodCallException $ex) {
			throw new AmpacheException("Unsupported type $type", 400);
		}

		return ['success' => "rating set to $rating for $type $id"];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function record_play(int $id, ?int $date) : array {
		$timeOfPlay = ($date === null) ? null : new \DateTime('@' . $date);
		$this->scrobbler->recordTrackPlayed($id, $this->userId(), $timeOfPlay);
		return ['success' => 'play recorded'];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function scrobble(string $song, string $artist, string $album, ?int $date) : array {
		// arguments songmbid, artistmbid, and albummbid not supported for now
		$matching = $this->trackBusinessLayer->findAllByNameArtistOrAlbum($song, $artist, $album, $this->userId());
		if (\count($matching) === 0) {
			throw new AmpacheException('Song matching the criteria was not found', 404);
		} else if (\count($matching) > 1) {
			throw new AmpacheException('Multiple songs matched the criteria, nothing recorded', 400);
		}
		return $this->record_play($matching[0]->getId(), $date);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function user(?string $username) : array {
		$userId = $this->userId();
		if (!empty($username) && \mb_strtolower($username) !== \mb_strtolower($userId)) {
			throw new AmpacheException("Getting info of other users is forbidden", 403);
		}
		$user = $this->userManager->get($userId);

		return [
			'id' => $user->getUID(),
			'username' => $user->getUID(),
			'fullname' => $user->getDisplayName(),
			'auth' => '',
			'email' => $user->getEMailAddress(),
			'access' => 25,
			'streamtoken' => null,
			'fullname_public' => true,
			'validation' => null,
			'disabled' => !$user->isEnabled(),
			'create_date' => null,
			'last_seen' => null,
			'website' => null,
			'state' => null,
			'city' => null,
			'art' => $this->urlGenerator->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => $user->getUID(), 'size' => 64]),
			'has_art' => ($user->getAvatarImage(64) != null)
		];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function user_preferences() : array {
		return ['preference' => AmpachePreferences::getAll()];
	}

	/**
	 * @AmpacheAPI
	 */
	protected function user_preference(string $filter) : array {
		$pref = AmpachePreferences::get($filter);
		if ($pref === null) {
			throw new AmpacheException("Not Found: $filter", 400);
		} else {
			return ['preference' => [$pref]];
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function system_preferences() : array {
		return $this->user_preferences();
	}

	/**
	 * @AmpacheAPI
	 */
	protected function system_preference(string $filter) : array {
		return $this->user_preference($filter);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function download(int $id, string $type='song', bool $stats=false) : Response {
		// request params `format` and `bitrate` are ignored

		// On all errors, return HTTP error codes instead of Ampache errors. When client calls this action, it awaits a binary response
		// and is probably not prepared to parse any Ampache json/xml responses.
		$userId = $this->userId();

		try {
			if ($type === 'song') {
				$track = $this->trackBusinessLayer->find($id, $userId);
				$file = $this->librarySettings->getFolder($userId)->getById($track->getFileId())[0] ?? null;

				if ($file instanceof \OCP\Files\File) {
					if ($stats) {
						$this->record_play($id, null);
					}
					return new FileStreamResponse($file);
				} else {
					return new ErrorResponse(Http::STATUS_NOT_FOUND, "File for song $id does not exist");
				}
			} elseif ($type === 'podcast' || $type === 'podcast_episode') { // there's a difference between APIv4 and APIv5
				$episode = $this->podcastEpisodeBusinessLayer->find($id, $userId);
				$streamUrl = $episode->getStreamUrl();
				if ($streamUrl === null) {
					return new ErrorResponse(Http::STATUS_NOT_FOUND, "The podcast episode $id has no stream URL");
				} elseif ($this->isInternalSession() && $this->config->getSystemValue('music.relay_podcast_stream', true)) {
					return new RelayStreamResponse($streamUrl);
				} else {
					return new RedirectResponse($streamUrl);
				}
			} elseif ($type === 'playlist') {
				$songIds = ($id === self::ALL_TRACKS_PLAYLIST_ID)
					? $this->trackBusinessLayer->findAllIds($userId)
					: $this->playlistBusinessLayer->find($id, $userId)->getTrackIdsAsArray();
				$randomId = Random::pickItem($songIds);
				if ($randomId === null) {
					return new ErrorResponse(Http::STATUS_NOT_FOUND, "The playlist $id is empty");
				} else {
					return $this->download((int)$randomId, 'song', $stats);
				}
			} else {
				return new ErrorResponse(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, "Unsupported type '$type'");
			}
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $e->getMessage());
		}
	}

	/**
	 * @AmpacheAPI
	 */
	protected function stream(int $id, ?int $offset, string $type='song', bool $stats=true) : Response {
		// request params `bitrate`, `format`, and `length` are ignored

		// This is just a dummy implementation. We don't support transcoding or streaming
		// from a time offset.
		// All the other unsupported arguments are just ignored, but a request with an offset
		// is responded with an error. This is because the client would probably work in an
		// unexpected way if it thinks it's streaming from offset but actually it is streaming
		// from the beginning of the file. Returning an error gives the client a chance to fallback
		// to other methods of seeking.
		if ($offset !== null) {
			return new ErrorResponse(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, 'Streaming with time offset is not supported');
		}

		return $this->download($id, $type, $stats);
	}

	/**
	 * @AmpacheAPI
	 */
	protected function get_art(string $type, string $id, ?string $size) : Response {
		if (!\in_array($type, ['song', 'album', 'artist', 'podcast', 'playlist', 'live_stream', 'user'])) {
			return new ErrorResponse(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, "Unsupported type $type");
		}

		if ($size == 'original') {
			$size = CoverService::DO_NOT_CROP_OR_SCALE;
		} elseif ($size !== null) {
			// the format should be "<width>x<height>" but we only support scaling to square shaped art
			$size = (int)(\explode('x', $size)[0] ?? 0);
			if ($size <= 0) {
				$size = null;
			}
		}

		if ($type === 'user') {
			if ($size === null || $size == CoverService::DO_NOT_CROP_OR_SCALE) {
				$size = 640;
			}
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.avatar.getAvatar', ['userId' => $id, 'size' => $size]));
		} else {
			$id = (int)$id;
		}

		if ($type === 'song') {
			// map song to its parent album
			try {
				$id = $this->trackBusinessLayer->find($id, $this->userId())->getAlbumId();
				$type = 'album';
			} catch (BusinessLayerException $e) {
				return new ErrorResponse(Http::STATUS_NOT_FOUND, "song $id not found");
			}
		}

		return $this->getCover($id, $this->getBusinessLayer($type), $size);
	}

	/********************
	 * Helper functions *
	 ********************/

	private function userId() : string {
		// It would be an internal server logic error if the middleware would let
		// an action needing the user session to be called without a valid session.
		assert($this->session !== null);
		return $this->session->getUserId();
	}

	/** @phpstan-return BusinessLayer<covariant Entity> */
	private function getBusinessLayer(string $type) : BusinessLayer {
		switch ($type) {
			case 'song':			return $this->trackBusinessLayer;
			case 'album':			return $this->albumBusinessLayer;
			case 'artist':			return $this->artistBusinessLayer;
			case 'playlist':		return $this->playlistBusinessLayer;
			case 'podcast':			return $this->podcastChannelBusinessLayer;
			case 'podcast_episode':	return $this->podcastEpisodeBusinessLayer;
			case 'live_stream':		return $this->radioStationBusinessLayer;
			case 'tag':				return $this->genreBusinessLayer;
			case 'genre':			return $this->genreBusinessLayer;
			case 'bookmark':		return $this->bookmarkBusinessLayer;
			default:				throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private static function getChildEntityType(string $type) : ?string {
		switch ($type) {
			case 'album':			return 'song';
			case 'artist':			return 'album';
			case 'album_artist':	return 'album';
			case 'song_artist':		return 'album';
			case 'playlist':		return 'song';
			case 'podcast':			return 'podcast_episode';
			default:				return null;
		}
	}

	private function renderEntities(array $entities, string $type) : array {
		switch ($type) {
			case 'song':			return $this->renderSongs($entities);
			case 'album':			return $this->renderAlbums($entities);
			case 'artist':			return $this->renderArtists($entities);
			case 'playlist':		return $this->renderPlaylists($entities);
			case 'podcast':			return $this->renderPodcastChannels($entities);
			case 'podcast_episode':	return $this->renderPodcastEpisodes($entities);
			case 'live_stream':		return $this->renderLiveStreams($entities);
			case 'tag':				return $this->renderTags($entities);
			case 'genre':			return $this->renderGenres($entities);
			case 'bookmark':		return $this->renderBookmarks($entities);
			default:				throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private function renderEntitiesIndex(array $entities, string $type) : array {
		switch ($type) {
			case 'song':			return $this->renderSongsIndex($entities);
			case 'album':			return $this->renderAlbumsIndex($entities);
			case 'artist':			return $this->renderArtistsIndex($entities);
			case 'playlist':		return $this->renderPlaylistsIndex($entities);
			case 'podcast':			return $this->renderPodcastChannelsIndex($entities);
			case 'podcast_episode':	return $this->renderPodcastEpisodesIndex($entities);
			case 'live_stream':		return $this->renderLiveStreamsIndex($entities);
			case 'tag':				return $this->renderTags($entities); // not part of the API spec
			case 'genre':			return $this->renderGenres($entities); // not part of the API spec
			case 'bookmark':		return $this->renderBookmarks($entities); // not part of the API spec
			default:				throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private function trackIdsForEntity(int $id, string $type) : array {
		$userId = $this->userId();
		switch ($type) {
			case 'song':
				return [$id];
			case 'album':
				return ArrayUtil::extractIds($this->trackBusinessLayer->findAllByAlbum($id, $userId));
			case 'artist':
				return ArrayUtil::extractIds($this->trackBusinessLayer->findAllByArtist($id, $userId));
			case 'playlist':
				return $this->playlistBusinessLayer->find($id, $userId)->getTrackIdsAsArray();
			default:
				throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private static function mapBookmarkType(string $ampacheType) : int {
		switch ($ampacheType) {
			case 'song':			return Bookmark::TYPE_TRACK;
			case 'podcast_episode':	return Bookmark::TYPE_PODCAST_EPISODE;
			default:				throw new AmpacheException("Unsupported type $ampacheType", 400);
		}
	}

	private static function advSearchResolveRuleAlias(string $rule) : string {
		switch ($rule) {
			case 'name':					return 'title';
			case 'song_title':				return 'song';
			case 'album_title':				return 'album';
			case 'artist_title':			return 'artist';
			case 'podcast_title':			return 'podcast';
			case 'podcast_episode_title':	return 'podcast_episode';
			case 'album_artist_title':		return 'album_artist';
			case 'song_artist_title':		return 'song_artist';
			case 'tag':						return 'genre';
			case 'song_tag':				return 'song_genre';
			case 'album_tag':				return 'album_genre';
			case 'artist_tag':				return 'artist_genre';
			case 'no_tag':					return 'no_genre';
			default:						return $rule;
		}
	}

	private static function advSearchGetRuleParams(array $urlParams) : array {
		$rules = [];

		// read and organize the rule parameters
		foreach ($urlParams as $key => $value) {
			$parts = \explode('_', $key, 3);
			if ($parts[0] == 'rule' && \count($parts) > 1) {
				if (\count($parts) == 2) {
					$rules[$parts[1]]['rule'] = $value;
				} elseif ($parts[2] == 'operator') {
					$rules[$parts[1]]['operator'] = (int)$value;
				} elseif ($parts[2] == 'input') {
					$rules[$parts[1]]['input'] = $value;
				}
			}
		}

		// validate the rule parameters
		if (\count($rules) === 0) {
			throw new AmpacheException('At least one rule must be given', 400);
		}
		foreach ($rules as $rule) {
			if (\count($rule) != 3) {
				throw new AmpacheException('All rules must be given as triplet "rule_N", "rule_N_operator", "rule_N_input"', 400);
			}
		}

		return $rules;
	}

	// NOTE: alias rule names should be resolved to their base form before calling this
	private static function advSearchInterpretOperator(int $rule_operator, string $rule) : string {
		// Operator mapping is different for text, numeric, date, boolean, and day rules

		$textRules = [
			'anywhere', 'title', 'song', 'album', 'artist', 'podcast', 'podcast_episode', 'album_artist', 'song_artist',
			'favorite', 'favorite_album', 'favorite_artist', 'genre', 'song_genre', 'album_genre', 'artist_genre',
			'playlist_name', 'type', 'file', 'mbid', 'mbid_album', 'mbid_artist', 'mbid_song'
		];
		// text but no support planned: 'composer', 'summary', 'placeformed', 'release_type', 'release_status', 'barcode',
		// 'catalog_number', 'label', 'comment', 'lyrics', 'username', 'category'

		$numericRules = [
			'track', 'year', 'original_year', 'myrating', 'rating', 'songrating', 'albumrating', 'artistrating',
			'played_times', 'album_count', 'song_count', 'disk_count', 'time', 'bitrate'
		];
		// numeric but no support planned: 'yearformed', 'skipped_times', 'play_skip_ratio', 'image_height', 'image_width'

		$numericLimitRules = ['recent_played', 'recent_added', 'recent_updated'];

		$dateOrDayRules = ['added', 'updated', 'pubdate', 'last_play'];

		$booleanRules = [
			'played', 'myplayed', 'myplayedalbum', 'myplayedartist', 'has_image', 'no_genre',
			'my_flagged', 'my_flagged_album', 'my_flagged_artist'
		];
		// boolean but no support planned: 'smartplaylist', 'possible_duplicate', 'possible_duplicate_album'

		$booleanNumericRules = ['playlist', 'album_artist_id' /* own extension */];
		// boolean numeric but no support planned: 'license', 'state', 'catalog'

		if (\in_array($rule, $textRules)) {
			switch ($rule_operator) {
				case 0: return 'contain';		// contains
				case 1: return 'notcontain';	// does not contain;
				case 2: return 'start';			// starts with
				case 3: return 'end';			// ends with;
				case 4: return 'is';			// is
				case 5: return 'isnot';			// is not
				case 6: return 'sounds';		// sounds like
				case 7: return 'notsounds';		// does not sound like
				case 8: return 'regexp';		// matches regex
				case 9: return 'notregexp';		// does not match regex
				default: throw new AmpacheException("Search operator '$rule_operator' not supported for 'text' type rules", 400);
			}
		} elseif (\in_array($rule, $numericRules)) {
			switch ($rule_operator) {
				case 0: return '>=';
				case 1: return '<=';
				case 2: return '=';
				case 3: return '!=';
				case 4: return '>';
				case 5: return '<';
				default: throw new AmpacheException("Search operator '$rule_operator' not supported for 'numeric' type rules", 400);
			}
		} elseif (\in_array($rule, $numericLimitRules)) {
			return 'limit';
		} elseif (\in_array($rule, $dateOrDayRules)) {
			switch ($rule_operator) {
				case 0: return 'before';
				case 1: return 'after';
				default: throw new AmpacheException("Search operator '$rule_operator' not supported for 'date' or 'day' type rules", 400);
			}
		} elseif (\in_array($rule, $booleanRules)) {
			switch ($rule_operator) {
				case 0: return 'true';
				case 1: return 'false';
				default: throw new AmpacheException("Search operator '$rule_operator' not supported for 'boolean' type rules", 400);
			}
		} elseif (\in_array($rule, $booleanNumericRules)) {
			switch ($rule_operator) {
				case 0: return 'equal';
				case 1: return 'ne';
				default: throw new AmpacheException("Search operator '$rule_operator' not supported for 'boolean numeric' type rules", 400);
			}
		} else {
			throw new AmpacheException("Search rule '$rule' not supported", 400);
		}
	}

	private static function advSearchConvertInput(string $input, string $rule) : string {
		switch ($rule) {
			case 'last_play':
				// days diff to ISO date
				$date = new \DateTime("$input days ago");
				return $date->format(BaseMapper::SQL_DATE_FORMAT);
			case 'time':
				// minutes to seconds
				return (string)(int)((float)$input * 60);
			default:
				return $input;
		}
	}

	private function getAllTracksPlaylist() : Playlist {
		$pl = new class extends Playlist {
			public int $trackCount = 0;
			public function getTrackCount() : int {
				return $this->trackCount;
			}
		};
		$pl->id = self::ALL_TRACKS_PLAYLIST_ID;
		$pl->name = $this->l10n->t('All tracks');
		$pl->userId = $this->userId();
		$pl->updated = $this->library->latestUpdateTime($pl->userId)->format('c');
		$pl->trackCount = $this->trackBusinessLayer->count($pl->userId);
		$pl->setTrackIdsFromArray($this->trackBusinessLayer->findAllIds($pl->userId));
		$pl->setReadOnly(true);

		return $pl;
	}

	/** @phpstan-param BusinessLayer<covariant Entity> $businessLayer */
	private function getCover(int $entityId, BusinessLayer $businessLayer, ?int $size) : Response {
		$userId = $this->userId();
		$userFolder = $this->librarySettings->getFolder($userId);

		try {
			$entity = $businessLayer->find($entityId, $userId);
			$coverData = $this->coverService->getCover($entity, $userId, $userFolder, $size);
			if ($coverData !== null) {
				return new FileResponse($coverData);
			}
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'entity not found');
		}

		return new ErrorResponse(Http::STATUS_NOT_FOUND, 'entity has no cover');
	}

	private static function parseTimeParameters(?string $add=null, ?string $update=null) : array {
		// It's not documented, but Ampache supports also specifying date range on `add` and `update` parameters
		// by using '/' as separator. If there is no such separator, then the value is used as a lower limit.
		$add = Util::explode('/', $add);
		$update = Util::explode('/', $update);
		$addMin = $add[0] ?? null;
		$addMax = $add[1] ?? null;
		$updateMin = $update[0] ?? null;
		$updateMax = $update[1] ?? null;

		return [$addMin, $addMax, $updateMin, $updateMax];
	}

	/** @phpstan-param BusinessLayer<covariant Entity> $businessLayer */
	private function findEntities(
			BusinessLayer $businessLayer, ?string $filter, bool $exact, ?int $limit=null, ?int $offset=null, ?string $add=null, ?string $update=null) : array {

		$userId = $this->userId();

		list($addMin, $addMax, $updateMin, $updateMax) = self::parseTimeParameters($add, $update);

		if ($filter) {
			$matchMode = $exact ? MatchMode::Exact : MatchMode::Substring;
			return $businessLayer->findAllByName($filter, $userId, $matchMode, $limit, $offset, $addMin, $addMax, $updateMin, $updateMax);
		} else {
			return $businessLayer->findAll($userId, SortBy::Name, $limit, $offset, $addMin, $addMax, $updateMin, $updateMax);
		}
	}

	/**
	 * @param PodcastChannel[] $channels
	 */
	private function injectEpisodesToChannels(array $channels) : void {
		$userId = $this->userId();
		$allChannelsIncluded = (\count($channels) === $this->podcastChannelBusinessLayer->count($userId));
		$this->podcastService->injectEpisodes($channels, $userId, $allChannelsIncluded);
	}

	private function isInternalSession() : bool {
		return $this->session !== null && $this->session->getToken() === 'internal';
	}

	private function createAmpacheActionUrl(string $action, int $id, ?string $type=null) : string {
		\assert($this->session !== null);
		if ($this->isInternalSession()) {
			$route = 'music.ampache.internalApi';
			$authArg = '';
		} else {
			$route = $this->jsonMode ? 'music.ampache.jsonApi' : 'music.ampache.xmlApi';
			$authArg = '&auth=' . $this->session->getToken();
		}
		return $this->urlGenerator->linkToRouteAbsolute($route)
				. "?action=$action&id=$id" . $authArg
				. (!empty($type) ? "&type=$type" : '');
	}

	private function createCoverUrl(Entity $entity) : string {
		if ($entity instanceof Album) {
			$type = 'album';
		} elseif ($entity instanceof Artist) {
			$type = 'artist';
		} elseif ($entity instanceof Playlist) {
			$type = 'playlist';
		} else {
			throw new AmpacheException('unexpected entity type for cover image', 500);
		}

		\assert($this->session !== null);
		if ($this->isInternalSession()) {
			// For internal clients, we don't need to create URLs with permanent but API-key-specific tokens
			return $this->createAmpacheActionUrl('get_art', $entity->getId(), $type);
		} else {
			// Scrutinizer doesn't understand that the if-else above guarantees that getCoverFileId() may be called only on Album or Artist
			if ($type === 'playlist' || $entity->/** @scrutinizer ignore-call */getCoverFileId()) {
				$id = $entity->getId();
				$token = $this->imageService->getToken($type, $id, $this->session->getAmpacheUserId());
				return $this->urlGenerator->linkToRouteAbsolute('music.ampacheImage.image') . "?object_type=$type&object_id=$id&token=$token";
			} else {
				return '';
			}
		}
	}

	private static function indexIsWithinOffsetAndLimit(int $index, ?int $offset, ?int $limit) : bool {
		$offset = \intval($offset); // missing offset is interpreted as 0-offset
		return ($limit === null) || ($index >= $offset && $index < $offset + $limit);
	}

	private function prefixAndBaseName(?string $name) : array {
		return StringUtil::splitPrefixAndBasename($name, $this->namePrefixes);
	}

	private function renderAlbumOrArtistRef(int $id, string $name) : array {
		if ($this->apiMajorVersion() > 5) {
			return [
				'id' => (string)$id,
				'name' => $name,
			] + $this->prefixAndBaseName($name);
		} else {
			return [
				'id' => (string)$id,
				'text' => $name
			];
		}
	}

	/**
	 * @param Artist[] $artists
	 */
	private function renderArtists(array $artists) : array {
		$userId = $this->userId();
		$genreMap = ArrayUtil::createIdLookupTable($this->genreBusinessLayer->findAll($userId));
		$genreKey = $this->genreKey();
		// In APIv3-4, the properties 'albums' and 'songs' were used for the album/song count in case the inclusion of the relevant
		// child objects wasn't requested. APIv5+ has the dedicated properties 'albumcount' and 'songcount' for this purpose.
		$oldCountApi = ($this->apiMajorVersion() < 5);

		return [
			'artist' => \array_map(function (Artist $artist) use ($userId, $genreMap, $genreKey, $oldCountApi) {
				$name = $artist->getNameString($this->l10n);
				$nameParts = $this->prefixAndBaseName($name);
				$albumCount = $this->albumBusinessLayer->countByAlbumArtist($artist->getId());
				$songCount = $this->trackBusinessLayer->countByArtist($artist->getId());
				$albums = $artist->getAlbums();
				$songs = $artist->getTracks();

				$apiArtist = [
					'id' => (string)$artist->getId(),
					'name' => $name,
					'prefix' => $nameParts['prefix'],
					'basename' => $nameParts['basename'],
					'albums' => ($albums !== null) ? $this->renderAlbums($albums) : ($oldCountApi ? $albumCount : null),
					'albumcount' => $albumCount,
					'songs' => ($songs !== null) ? $this->renderSongs($songs) : ($oldCountApi ? $songCount : null),
					'songcount' => $songCount,
					'time' => $this->trackBusinessLayer->totalDurationByArtist($artist->getId()),
					'art' => $this->createCoverUrl($artist),
					'has_art' => $artist->getCoverFileId() !== null,
					'rating' => $artist->getRating(),
					'preciserating' => $artist->getRating(),
					'flag' => !empty($artist->getStarred()),
					$genreKey => \array_map(fn($genreId) => [
						'id' => (string)$genreId,
						'text' => $genreMap[$genreId]->getNameString($this->l10n),
						'count' => 1
					], $this->trackBusinessLayer->getGenresByArtistId($artist->getId(), $userId))
				];

				if ($this->jsonMode) {
					// Remove an unnecessary level on the JSON API
					if ($albums !== null) {
						$apiArtist['albums'] = $apiArtist['albums']['album'];
					}
					if ($songs !== null) {
						$apiArtist['songs'] = $apiArtist['songs']['song'];
					}
				}

				return $apiArtist;
			}, $artists)
		];
	}

	/**
	 * @param Album[] $albums
	 */
	private function renderAlbums(array $albums) : array {
		$genreKey = $this->genreKey();
		$apiMajor = $this->apiMajorVersion();
		// In APIv6 JSON format, there is a new property `artists` with an array value
		$includeArtists = ($this->jsonMode && $apiMajor > 5);
		// In APIv3-4, the property 'tracks' was used for the song count in case the inclusion of songs wasn't requested.
		// APIv5+ has the property 'songcount' for this and 'tracks' may only contain objects.
		$tracksMayDenoteCount = ($apiMajor < 5);

		return [
			'album' => \array_map(function (Album $album) use ($genreKey, $includeArtists, $tracksMayDenoteCount) {
				$name = $album->getNameString($this->l10n);
				$nameParts = $this->prefixAndBaseName($name);
				$songCount = $this->trackBusinessLayer->countByAlbum($album->getId());
				$songs = $album->getTracks();

				$apiAlbum = [
					'id' => (string)$album->getId(),
					'name' => $name,
					'prefix' => $nameParts['prefix'],
					'basename' => $nameParts['basename'],
					'artist' => $this->renderAlbumOrArtistRef(
						$album->getAlbumArtistId(),
						$album->getAlbumArtistNameString($this->l10n)
					),
					'tracks' => ($songs !== null) ? $this->renderSongs($songs, false) : ($tracksMayDenoteCount ? $songCount : null),
					'songcount' => $songCount,
					'diskcount' => $album->getNumberOfDisks(),
					'time' => $this->trackBusinessLayer->totalDurationOfAlbum($album->getId()),
					'rating' => $album->getRating(),
					'preciserating' => $album->getRating(),
					'year' => $album->yearToAPI(),
					'art' => $this->createCoverUrl($album),
					'has_art' => $album->getCoverFileId() !== null,
					'flag' => !empty($album->getStarred()),
					$genreKey => \array_map(fn($genre) => [
						'id' => (string)$genre->getId(),
						'text' => $genre->getNameString($this->l10n),
						'count' => 1
					], $album->getGenres() ?? [])
				];
				if ($includeArtists) {
					$apiAlbum['artists'] = [$apiAlbum['artist']];
				}
				if ($this->jsonMode && $songs !== null) {
					// Remove an unnecessary level on the JSON API
					$apiAlbum['tracks'] = $apiAlbum['tracks']['song'];
				}

				return $apiAlbum;
			}, $albums)
		];
	}

	/**
	 * @param Track[] $tracks
	 */
	private function renderSongs(array $tracks, bool $injectAlbums=true) : array {
		if ($injectAlbums) {
			$this->albumBusinessLayer->injectAlbumsToTracks($tracks, $this->userId());
		}

		$createPlayUrl = fn(Track $track) => $this->createAmpacheActionUrl('stream', $track->getId());
		$createImageUrl = function(Track $track) : string {
			$album = $track->getAlbum();
			return ($album !== null && $album->getId() !== null) ? $this->createCoverUrl($album) : '';
		};
		$renderRef = fn(int $id, string $name) => $this->renderAlbumOrArtistRef($id, $name);
		$genreKey = $this->genreKey();
		// In APIv6 JSON format, there is a new property `artists` with an array value
		$includeArtists = ($this->jsonMode && $this->apiMajorVersion() > 5);

		return [
			'song' => \array_map(
				fn($t) => $t->toAmpacheApi($this->l10n, $createPlayUrl, $createImageUrl, $renderRef, $genreKey, $includeArtists),
				$tracks
			)
		];
	}

	/**
	 * @param Playlist[] $playlists
	 */
	private function renderPlaylists(array $playlists, bool $includeTracks=false) : array {
		$createImageUrl = function(Playlist $playlist) : string {
			if ($playlist->getId() === self::ALL_TRACKS_PLAYLIST_ID) {
				return '';
			} else {
				return $this->createCoverUrl($playlist);
			}
		};

		$result = [
			'playlist' => \array_map(fn($p) => $p->toAmpacheApi($createImageUrl, $includeTracks), $playlists)
		];

		// annoyingly, the structure of the included tracks is quite different in JSON compared to XML
		if ($includeTracks && $this->jsonMode) {
			foreach ($result['playlist'] as &$apiPlaylist) {
				$apiPlaylist['items'] = ArrayUtil::convertKeys($apiPlaylist['items']['playlisttrack'], ['text' => 'playlisttrack']);
			}
		}

		return $result;
	}

	/**
	 * @param PodcastChannel[] $channels
	 */
	private function renderPodcastChannels(array $channels) : array {
		return [
			'podcast' => \array_map(fn($c) => $c->toAmpacheApi(), $channels)
		];
	}

	/**
	 * @param PodcastEpisode[] $episodes
	 */
	private function renderPodcastEpisodes(array $episodes) : array {
		return [
			'podcast_episode' => \array_map(fn($e) => $e->toAmpacheApi(
				fn($episode) => $this->createAmpacheActionUrl('get_art', $episode->getChannelId(), 'podcast'),
				fn($episode) => $this->createAmpacheActionUrl('stream', $episode->getId(), 'podcast_episode')
			), $episodes)
		];
	}

	/**
	 * @param RadioStation[] $stations
	 */
	private function renderLiveStreams(array $stations) : array {
		$createImageUrl = fn(RadioStation $station) => $this->createAmpacheActionUrl('get_art', $station->getId(), 'live_stream');

		return [
			'live_stream' => \array_map(fn($s) => $s->toAmpacheApi($createImageUrl), $stations)
		];
	}

	/**
	 * @param Genre[] $genres
	 */
	private function renderTags(array $genres) : array {
		return [
			'tag' => \array_map(fn($g) => $g->toAmpacheApi($this->l10n), $genres)
		];
	}

	/**
	 * @param Genre[] $genres
	 */
	private function renderGenres(array $genres) : array {
		return [
			'genre' => \array_map(fn($g) => $g->toAmpacheApi($this->l10n), $genres)
		];
	}

	/**
	 * @param Bookmark[] $bookmarks
	 */
	private function renderBookmarks(array $bookmarks, int $include=0) : array {
		$renderEntry = null;

		if ($include) {
			$renderEntry = function(string $type, int $id) {
				$businessLayer = $this->getBusinessLayer($type);
				$entity = $businessLayer->find($id, $this->userId());
				return $this->renderEntities([$entity], $type)[$type][0];
			};
		}

		return [
			'bookmark' => \array_map(fn($b) => $b->toAmpacheApi($renderEntry), $bookmarks)
		];
	}

	/**
	 * @param Track[] $tracks
	 */
	private function renderSongsIndex(array $tracks) : array {
		return [
			'song' => \array_map(fn($track) => [
				'id' => (string)$track->getId(),
				'title' => $track->getTitle(),
				'name' => $track->getTitle(),
				'artist' => $this->renderAlbumOrArtistRef($track->getArtistId(), $track->getArtistNameString($this->l10n)),
				'album' => $this->renderAlbumOrArtistRef($track->getAlbumId(), $track->getAlbumNameString($this->l10n))
			], $tracks)
		];
	}

	/**
	 * @param Album[] $albums
	 */
	private function renderAlbumsIndex(array $albums) : array {
		return [
			'album' => \array_map(function ($album) {
				$name = $album->getNameString($this->l10n);
				$nameParts = $this->prefixAndBaseName($name);

				return [
					'id' => (string)$album->getId(),
					'name' => $name,
					'prefix' => $nameParts['prefix'],
					'basename' => $nameParts['basename'],
					'artist' => $this->renderAlbumOrArtistRef($album->getAlbumArtistId(), $album->getAlbumArtistNameString($this->l10n))
				];
			}, $albums)
		];
	}

	/**
	 * @param Artist[] $artists
	 */
	private function renderArtistsIndex(array $artists) : array {
		return [
			'artist' => \array_map(function ($artist) {
				$userId = $this->userId();
				$albums = $this->albumBusinessLayer->findAllByArtist($artist->getId(), $userId);
				$name = $artist->getNameString($this->l10n);
				$nameParts = $this->prefixAndBaseName($name);

				return [
					'id' => (string)$artist->getId(),
					'name' => $name,
					'prefix' => $nameParts['prefix'],
					'basename' => $nameParts['basename'],
					'album' => \array_map(
						fn($album) => $this->renderAlbumOrArtistRef($album->getId(), $album->getNameString($this->l10n)),
						$albums
					)
				];
			}, $artists)
		];
	}

	/**
	 * @param Playlist[] $playlists
	 */
	private function renderPlaylistsIndex(array $playlists) : array {
		return [
			'playlist' => \array_map(fn($playlist) => [
				'id' => (string)$playlist->getId(),
				'name' => $playlist->getName(),
				'playlisttrack' => $playlist->getTrackIdsAsArray()
			], $playlists)
		];
	}

	/**
	 * @param PodcastChannel[] $channels
	 */
	private function renderPodcastChannelsIndex(array $channels) : array {
		// The v4 API spec does not give any examples of this, and the v5 example is almost identical to the v4 "normal" result
		return $this->renderPodcastChannels($channels);
	}

	/**
	 * @param PodcastEpisode[] $episodes
	 */
	private function renderPodcastEpisodesIndex(array $episodes) : array {
		// The v4 API spec does not give any examples of this, and the v5 example is almost identical to the v4 "normal" result
		return $this->renderPodcastEpisodes($episodes);
	}

	/**
	 * @param RadioStation[] $stations
	 */
	private function renderLiveStreamsIndex(array $stations) : array {
		// The API spec gives no examples of this, but testing with Ampache demo server revealed that the format is identical to the "full" format
		return $this->renderLiveStreams($stations);
	}

	/**
	 * @param Entity[] $entities
	 */
	private function renderEntityIds(array $entities, string $key = 'id') : array {
		return [$key => ArrayUtil::extractIds($entities)];
	}

	/**
	 * Render the way used by `action=index` when `include=0`
	 * @param Entity[] $entities
	 */
	private function renderEntityIdIndex(array $entities, string $type) : array {
		// the structure is quite different for JSON compared to XML
		if ($this->jsonMode) {
			return $this->renderEntityIds($entities, $type);
		} else {
			return [$type => \array_map(
				fn($entity) => ['id' => $entity->getId()],
				$entities
			)];
		}
	}

	/**
	 * Render the way used by `action=index` when `include=1`
	 * @param array<int, int[]> $idsWithChildren
	 */
	private function renderIdsWithChildren(array $idsWithChildren, string $type, string $childType) : array {
		// the structure is quite different for JSON compared to XML
		if ($this->jsonMode) {
			foreach ($idsWithChildren as &$children) {
				$children = \array_map(fn($childId) => ['id' => $childId, 'type' => $childType], $children);
			}
			return [$type => $idsWithChildren];
		} else {
			return [$type => \array_map(fn($id, $childIds) => [
				'id' => $id,
				$childType => \array_map(fn($id) => ['id' => $id], $childIds)
			], \array_keys($idsWithChildren), $idsWithChildren)];
		}
	}

	/**
	 * Array is considered to be "indexed" if its first element has numerical key.
	 * Empty array is considered to be "indexed".
	 */
	private static function arrayIsIndexed(array $array) : bool {
		\reset($array);
		return empty($array) || \is_int(\key($array));
	}

	/**
	 * The JSON API has some asymmetries with the XML API. This function makes the needed
	 * translations for the result content before it is converted into JSON.
	 */
	private function prepareResultForJsonApi(array $content) : array {
		$apiVer = $this->apiMajorVersion();

		// Special handling is needed for responses returning an array of library entities,
		// depending on the API version. In these cases, the outermost array is of associative
		// type with a single value which is a non-associative array.
		if (\count($content) === 1 && !self::arrayIsIndexed($content)
				&& \is_array(\current($content)) && self::arrayIsIndexed(\current($content))) {
			// In API versions < 5, the root node is an anonymous array. Unwrap the outermost array.
			if ($apiVer < 5) {
				$content = \array_pop($content);
			}
			// In later versions, the root object has a named array for plural actions (like "songs", "artists").
			// For singular actions (like "song", "artist"), the root object contains directly the entity properties.
			else {
				$action = $this->request->getParam('action');
				$plural = (\substr($action, -1) === 's' || \in_array($action, ['get_similar', 'advanced_search', 'search', 'list', 'index']));

				// In APIv5, the action "album" is an exception, it is formatted as if it was a plural action.
				// This outlier has been fixed in APIv6.
				$api5albumOddity = ($apiVer === 5 && $action === 'album');

				// The actions "user_preference" and "system_preference" are another kind of outliers in APIv5,
				// their responses are anonymous 1-item arrays. This got fixed in the APIv6.0.1
				$api5preferenceOddity = ($apiVer === 5 && StringUtil::endsWith($action, 'preference'));

				// The action "get_bookmark" works as plural in case the argument all=1 is given
				$allBookmarks = ($action === 'get_bookmark' && $this->request->getParam('all'));

				if ($api5preferenceOddity) {
					$content = \array_pop($content);
				} elseif (!($plural  || $api5albumOddity || $allBookmarks)) {
					$content = \array_pop($content);
					$content = \array_pop($content);
				}
			}
		}

		// In API versions < 6, all boolean valued properties should be converted to 0/1.
		if ($apiVer < 6) {
			ArrayUtil::intCastValues($content, 'is_bool');
		}

		// The key 'text' has a special meaning on XML responses, as it makes the corresponding value
		// to be treated as text content of the parent element. In the JSON API, these are mostly
		// substituted with property 'name', but error responses use the property 'message', instead.
		if (\array_key_exists('error', $content)) {
			$content = ArrayUtil::convertKeys($content, ['text' => 'message']);
		} else {
			$content = ArrayUtil::convertKeys($content, ['text' => 'name']);
		}
		return $content;
	}

	/**
	 * The XML API has some asymmetries with the JSON API. This function makes the needed
	 * translations for the result content before it is converted into XML.
	 */
	private function prepareResultForXmlApi(array $content) : array {
		\reset($content);
		$firstKey = \key($content);

		// all 'entity list' kind of responses shall have the (deprecated) total_count element
		if (\in_array($firstKey, ['song', 'album', 'artist', 'album_artist', 'song_artist',
				'playlist', 'tag', 'genre', 'podcast', 'podcast_episode', 'live_stream'])) {
			$content = ['total_count' => \count($content[$firstKey])] + $content;
		}

		// for some bizarre reason, the 'id' arrays have 'index' attributes in the XML format
		if ($firstKey == 'id') {
			$content['id'] = \array_map(
				fn($id, $index) => ['index' => $index, 'text' => $id],
				$content['id'], \array_keys($content['id'])
			);
		}

		return ['root' => $content];
	}

	private function genreKey() : string {
		return ($this->apiMajorVersion() > 4) ? 'genre' : 'tag';
	}

	private function requestedApiVersion() : ?string {
		// During the handshake, we don't yet have a session but the requested version may be in the request args
		return ($this->session !== null)
			? $this->session->getApiVersion()
			: $this->request->getParam('version');
	}

	private function apiMajorVersion() : int {
		$verString = $this->requestedApiVersion();
		
		if (\is_string($verString) && \strlen($verString)) {
			$ver = (int)$verString[0];
		} else {
			// Default version is 6 unless otherwise defined in config.php
			$ver = (int)$this->config->getSystemValue('music.ampache_api_default_ver', 6);
		}

		// For now, we have three supported major versions. Major version 3 can be sufficiently supported
		// with our "version 4" implementation.
		return (int)Util::limit($ver, 4, 6);
	}

	private function apiVersionString() : string {
		switch ($this->apiMajorVersion()) {
			case 4:		$ver = self::API4_VERSION; break;
			case 5:		$ver = self::API5_VERSION; break;
			case 6:		$ver = self::API6_VERSION; break;
			default:	throw new AmpacheException('Unexpected api major version', 500);
		}

		// Convert the version to the 6-digit legacy format if the client request used this format or there
		// was no version defined by the client but the default version is 4 (Ampache introduced the new
		// version number format in version 5).
		$reqVersion = $this->requestedApiVersion();
		if (($reqVersion !== null && \preg_match('/^\d\d\d\d\d\d$/', $reqVersion) === 1)
			|| ($reqVersion === null && $ver === self::API4_VERSION))
		{
			$ver = \str_replace('.', '', $ver) . '000';
		}
	
		return $ver;
	}

	private function mapApiV4ErrorToV5(int $code) : int {
		switch ($code) {
			case 400:	return 4710;	// bad request
			case 401:	return 4701;	// invalid handshake
			case 403:	return 4703;	// access denied
			case 404:	return 4704;	// not found
			case 405:	return 4705;	// missing
			case 412:	return 4742;	// failed access check
			case 501:	return 4700;	// access control not enabled
			default:	return 5000;	// unexpected (not part of the API spec)
		}
	}
}
