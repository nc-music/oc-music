<?php

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2017 - 2025
 */

namespace OCA\Music;

$routes = ['routes' => [
	// Page
	['name' => 'page#index', 'url' => '/',			'verb' => 'GET'],
	// also the Ampache and Subsonic base URLs are directed to the front page, as several clients provide such links
	['name' => 'page#index', 'url' => '/subsonic',	'verb' => 'GET',	'postfix' => '_subsonic'],
	['name' => 'page#index', 'url' => '/ampache',	'verb' => 'GET',	'postfix' => '_ampache'],

	// Scrobbler
	['name' => 'scrobbler#handleToken', 'url' => '/api/scrobbler/handleToken', 'verb' => 'GET'],
	['name' => 'scrobbler#clearSession', 'url' => '/api/scrobbler/clearSession', 'verb' => 'POST'],

	// Log
	['name' => 'log#log', 'url' => '/api/log', 'verb' => 'POST'],

	// Music app proprietary API
	['name' => 'musicApi#prepareCollection','url' => '/api/prepare_collection',			'verb' => 'POST'],
	['name' => 'musicApi#collection',		'url' => '/api/collection',					'verb' => 'GET'],
	['name' => 'musicApi#folders',			'url' => '/api/folders',					'verb' => 'GET'],
	['name' => 'musicApi#genres',			'url' => '/api/genres',						'verb' => 'GET'],
	['name' => 'musicApi#trackByFileId',	'url' => '/api/files/{fileId}',				'verb' => 'GET'],
	['name' => 'musicApi#download',			'url' => '/api/files/{fileId}/download',	'verb' => 'GET'],
	['name' => 'musicApi#filePath',			'url' => '/api/files/{fileId}/path',		'verb' => 'GET'],
	['name' => 'musicApi#fileInfo',			'url' => '/api/files/{fileId}/info',		'verb' => 'GET'],
	['name' => 'musicApi#fileDetails',		'url' => '/api/files/{fileId}/details',		'verb' => 'GET'],
	['name' => 'musicApi#fileLyrics',		'url' => '/api/files/{fileId}/lyrics',		'verb' => 'GET'],
	['name' => 'musicApi#findDetails',		'url' => '/api/details',					'verb' => 'GET'],
	['name' => 'musicApi#getScanState',		'url' => '/api/scanstate',					'verb' => 'GET'],
	['name' => 'musicApi#scan',				'url' => '/api/scan',						'verb' => 'POST'],
	['name' => 'musicApi#removeScanned',	'url' => '/api/removescanned',				'verb' => 'POST'],
	['name' => 'musicApi#resetScanned'	,	'url' => '/api/resetscanned',				'verb' => 'POST'],
	['name' => 'musicApi#artistDetails',	'url' => '/api/artists/{artistId}/details',	'verb' => 'GET'],
	['name' => 'musicApi#similarArtists',	'url' => '/api/artists/{artistId}/similar',	'verb' => 'GET'],
	['name' => 'musicApi#albumDetails',		'url' => '/api/albums/{albumId}/details',	'verb' => 'GET'],
	['name' => 'musicApi#setPlayingTrack',	'url' => '/api/tracks/{trackId}/playing',	'verb' => 'POST'],
	['name' => 'musicApi#scrobble',			'url' => '/api/tracks/{trackId}/scrobble',	'verb' => 'POST'],

	// Search API
	['name' => 'advSearch#search',		'url' => '/api/advanced_search',			'verb' => 'POST'],

	// Cover art API
	['name' => 'coverApi#externalCover','url' => '/api/cover/external',				'verb' => 'GET'],
	['name' => 'coverApi#cachedCover',	'url' => '/api/cover/{hash}',				'verb' => 'GET'],
	['name' => 'coverApi#artistCover',	'url' => '/api/artists/{artistId}/cover',	'verb' => 'GET'],
	['name' => 'coverApi#albumCover',	'url' => '/api/albums/{albumId}/cover',		'verb' => 'GET'],
	['name' => 'coverApi#podcastCover',	'url' => '/api/podcasts/{channelId}/cover',	'verb' => 'GET'],

	// Shiva API https://shiva.readthedocs.io/en/latest/index.html
	['name' => 'shivaApi#artists',		'url' => '/api/artists',					'verb' => 'GET'],
	['name' => 'shivaApi#artist',		'url' => '/api/artists/{id}',				'verb' => 'GET'],
	//['name' => 'shivaApi#artistShows','url' => '/api/artists/{id}/shows',			'verb' => 'GET'],
	['name' => 'shivaApi#albums',		'url' => '/api/albums',						'verb' => 'GET'],
	['name' => 'shivaApi#album',		'url' => '/api/albums/{id}',				'verb' => 'GET'],
	['name' => 'shivaApi#tracks',		'url' => '/api/tracks',						'verb' => 'GET'],
	['name' => 'shivaApi#track',		'url' => '/api/tracks/{id}',				'verb' => 'GET'],
	['name' => 'shivaApi#trackLyrics',	'url' => '/api/tracks/{id}/lyrics',			'verb' => 'GET'],
	['name' => 'shivaApi#randomArtist',	'url' => '/api/random/artist',				'verb' => 'GET'],
	['name' => 'shivaApi#randomAlbum',	'url' => '/api/random/album',				'verb' => 'GET'],
	['name' => 'shivaApi#randomTrack',	'url' => '/api/random/track',				'verb' => 'GET'],
	['name' => 'shivaApi#latestItems',	'url' => '/api/whatsnew',					'verb' => 'GET'],

	// Share API
	['name' => 'share#fileInfo',		'url' => '/api/share/{token}/{fileId}/info',	'verb' => 'GET'],
	['name' => 'share#download',		'url' => '/api/share/{token}/{fileId}/download','verb' => 'GET'],
	['name' => 'share#parsePlaylist',	'url' => '/api/share/{token}/{fileId}/parse',	'verb' => 'GET'],

	// Playlist API (Shiva-compatible but extended)
	['name' => 'playlistApi#getAll',		'url' => '/api/playlists',				'verb' => 'GET'],
	['name' => 'playlistApi#create',		'url' => '/api/playlists',				'verb' => 'POST'],
	['name' => 'playlistApi#generate',		'url' => '/api/playlists/generate',		'verb' => 'GET'],
	['name' => 'playlistApi#get',			'url' => '/api/playlists/{id}',			'verb' => 'GET'],
	['name' => 'playlistApi#delete',		'url' => '/api/playlists/{id}',			'verb' => 'DELETE'],
	['name' => 'playlistApi#update',		'url' => '/api/playlists/{id}',			'verb' => 'PUT'],
	['name' => 'playlistApi#addTracks',		'url' => '/api/playlists/{id}/add',		'verb' => 'POST'],
	['name' => 'playlistApi#removeTracks',	'url' => '/api/playlists/{id}/remove',	'verb' => 'POST'],
	['name' => 'playlistApi#reorder',		'url' => '/api/playlists/{id}/reorder',	'verb' => 'POST'],
	['name' => 'playlistApi#exportToFile',	'url' => '/api/playlists/{id}/export',	'verb' => 'POST'],
	['name' => 'playlistApi#importFromFile','url' => '/api/playlists/{id}/import',	'verb' => 'POST'],
	['name' => 'playlistApi#getCover',		'url' => '/api/playlists/{id}/cover',	'verb' => 'GET'],
	['name' => 'playlistApi#parseFile',		'url' => '/api/playlists/file/{fileId}','verb' => 'GET'],

	// Radio API
	['name' => 'radioApi#getAll',			'url' => '/api/radio',					'verb' => 'GET'],
	['name' => 'radioApi#create',			'url' => '/api/radio',					'verb' => 'POST'],
	['name' => 'radioApi#exportAllToFile',	'url' => '/api/radio/export',			'verb' => 'POST'],
	['name' => 'radioApi#importFromFile',	'url' => '/api/radio/import',			'verb' => 'POST'],
	['name' => 'radioApi#resetAll',			'url' => '/api/radio/reset',			'verb' => 'POST'],
	['name' => 'radioApi#resolveStreamUrl',	'url' => '/api/radio/streamurl',		'verb' => 'GET'],
	['name' => 'radioApi#streamFromUrl',	'url' => '/api/radio/stream',			'verb' => 'GET'],
	['name' => 'radioApi#hlsManifest',		'url' => '/api/radio/hls/manifest',		'verb' => 'GET'],
	['name' => 'radioApi#hlsSegment',		'url' => '/api/radio/hls/segment',		'verb' => 'GET'],
	['name' => 'radioApi#get',				'url' => '/api/radio/{id}',				'verb' => 'GET'],
	['name' => 'radioApi#delete',			'url' => '/api/radio/{id}',				'verb' => 'DELETE'],
	['name' => 'radioApi#update',			'url' => '/api/radio/{id}',				'verb' => 'PUT'],
	['name' => 'radioApi#getChannelInfo',	'url' => '/api/radio/{id}/info',		'verb' => 'GET'],
	['name' => 'radioApi#stationStreamUrl',	'url' => '/api/radio/{id}/streamurl',	'verb' => 'GET'],
	['name' => 'radioApi#stationStream',	'url' => '/api/radio/{id}/stream',		'verb' => 'GET'],

	// Podcast API
	['name' => 'podcastApi#getAll',			'url' => '/api/podcasts',						'verb' => 'GET'],
	['name' => 'podcastApi#subscribe',		'url' => '/api/podcasts',						'verb' => 'POST'],
	['name' => 'podcastApi#exportAllToFile','url' => '/api/podcasts/export',				'verb' => 'POST'],
	['name' => 'podcastApi#parseListFile',	'url' => '/api/podcasts/parse',					'verb' => 'GET'],
	['name' => 'podcastApi#resetAll',		'url' => '/api/podcasts/reset',					'verb' => 'POST'],
	['name' => 'podcastApi#episodeDetails',	'url' => '/api/podcasts/episodes/{id}/details',	'verb' => 'GET'],
	['name' => 'podcastApi#episodeStream',	'url' => '/api/podcasts/episodes/{id}/stream',	'verb' => 'GET'],
	['name' => 'podcastApi#get',			'url' => '/api/podcasts/{id}',					'verb' => 'GET'],
	['name' => 'podcastApi#unsubscribe',	'url' => '/api/podcasts/{id}',					'verb' => 'DELETE'],
	['name' => 'podcastApi#channelDetails',	'url' => '/api/podcasts/{id}/details',			'verb' => 'GET'],
	['name' => 'podcastApi#updateChannel',	'url' => '/api/podcasts/{id}/update',			'verb' => 'POST'],

	// Favorites API
	['name' => 'favorites#favorites',			'url' => '/api/favorites',						'verb' => 'GET'],
	['name' => 'favorites#setFavoriteTrack',	'url' => '/api/tracks/{id}/favorite',			'verb' => 'PUT'],
	['name' => 'favorites#setFavoriteAlbum',	'url' => '/api/albums/{id}/favorite',			'verb' => 'PUT'],
	['name' => 'favorites#setFavoriteArtist',	'url' => '/api/artists/{id}/favorite',			'verb' => 'PUT'],
	['name' => 'favorites#setFavoritePlaylist',	'url' => '/api/playlists/{id}/favorite',		'verb' => 'PUT'],
	['name' => 'favorites#setFavoriteChannel',	'url' => '/api/podcasts/{id}/favorite',			'verb' => 'PUT'],
	['name' => 'favorites#setFavoriteEpisode',	'url' => '/api/podcasts/episodes/{id}/favorite','verb' => 'PUT'],

	// Settings API
	['name' => 'setting#getAll',			'url' => '/api/settings',							'verb' => 'GET'],
	['name' => 'setting#userPath',			'url' => '/api/settings/user/path',					'verb' => 'POST'],
	['name' => 'setting#userExcludedPaths',	'url' => '/api/settings/user/exclude_paths',		'verb' => 'POST'],
	['name' => 'setting#enableScanMetadata','url' => '/api/settings/user/enable_scan_metadata',	'verb' => 'POST'],
	['name' => 'setting#ignoredArticles',	'url' => '/api/settings/user/ignored_articles',		'verb' => 'POST'],
	['name' => 'setting#getUserKeys',		'url' => '/api/settings/user/keys',					'verb' => 'GET'],
	['name' => 'setting#createUserKey',		'url' => '/api/settings/user/keys',					'verb' => 'POST'],
	['name' => 'setting#removeUserKey',		'url' => '/api/settings/user/keys/{id}',			'verb' => 'DELETE'],
	['name' => 'setting#createUserKeyCors',	'url' => '/api/settings/userkey/generate',			'verb' => 'POST'], # external API, keep inconsistent url to maintain compatibility

	// Ampache API https://ampache.org/api/
	['name' => 'ampache#xmlApi',			'url' => '/ampache/server/xml.server.php',	'verb' => 'GET'],
	['name' => 'ampache#jsonApi',			'url' => '/ampache/server/json.server.php',	'verb' => 'GET'],
	// Ampache API - POST version for JustPlayer. Defining 'postfix' allows binding two routes to the same handler.
	['name' => 'ampache#xmlApi',			'url' => '/ampache/server/xml.server.php',	'verb' => 'POST',		'postfix' => '_post'],
	['name' => 'ampache#jsonApi',			'url' => '/ampache/server/json.server.php',	'verb' => 'POST',		'postfix' => '_post'],
	// Ampache API - Workaround for AmpacheAlbumPlayer
	['name' => 'ampache#xmlApi',			'url' => '/ampache/server/xml.server.php/',	'verb' => 'GET',		'postfix' => '_aap'],
	// Ampache API - Allow CORS pre-flight for web clients from different domains
	['name' => 'ampache#preflightedCors',	'url' => '/ampache/server/xml.server.php',	'verb' => 'OPTIONS'],
	['name' => 'ampache#preflightedCors',	'url' => '/ampache/server/json.server.php',	'verb' => 'OPTIONS',	'postfix' => '_json'],
	// Ampache image API
	['name' => 'ampacheImage#image',		'url' => '/ampache/image.php',				'verb' => 'GET'],
	// Ampache API - Internal API for the dashboard widget
	['name' => 'ampache#internalApi',		'url' => '/ampache/internal',				'verb' => 'GET'],

	// Subsonic API https://opensubsonic.netlify.app/docs/
	// Some clients use POST while others use GET. Defining 'postfix' allows binding two routes to the same handler.
	['name' => 'subsonic#handleRequest',	'url' => '/subsonic/rest/{method}',	'verb' => 'GET',	'requirements' => ['method' => '[a-zA-Z0-9\.]+']],
	['name' => 'subsonic#handleRequest',	'url' => '/subsonic/rest/{method}',	'verb' => 'POST',	'requirements' => ['method' => '[a-zA-Z0-9\.]+'],	'postfix' => '_post'],
	// Subsonic API - Allow CORS pre-flight for web clients from different domains
	['name' => 'subsonic#preflightedCors',	'url' => '/subsonic/rest/{method}',	'verb' => 'OPTIONS','requirements' => ['method' => '[a-zA-Z0-9\.]+']],
]];

// The method registerRoutes is deprecated in NC20+ and removed in NC32 while we still need to use it on ownCloud.
if (Utility\AppInfo::getVendor() == 'owncloud') {
	$app = \OC::$server->query(AppInfo\Application::class);
	$app->registerRoutes($this, $routes);
} else {
	return $routes;
}
