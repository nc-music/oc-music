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

angular.module('Music').controller('AlbumsViewController', [
	'$scope', '$rootScope', 'playQueueService', 'libraryService',
	'Restangular', '$document', '$route', '$location', '$timeout', 'gettextCatalog',
	function ($scope, $rootScope, playQueueService, libraryService,
			Restangular, $document, $route, $location, $timeout, gettextCatalog) {

		$rootScope.currentView = '#';

		// apply the layout mode stored by the MainController
		$('#albums').toggleClass('compact', $scope.albumsCompactLayout);

		// When making the view visible, the artists are added incrementally step-by-step.
		// The purpose of this is to keep the browser responsive even in case the view contains
		// an enormous amount of albums (like several thousands).
		const INCREMENTAL_LOAD_STEP = 20;
		$scope.incrementalLoadLimit = 0;

		// $rootScope listeners must be unsubscribed manually when the control is destroyed
		let unsubFuncs = [];

		function subscribe(event, handler) {
			unsubFuncs.push( $rootScope.$on(event, handler) );
		}

		$scope.$on('$destroy', () => {
			_.each(unsubFuncs, function(func) { func(); });
			playQueueService.unsubscribeAll(this);
		});

		// Prevent controller reload when the URL is updated with window.location.hash,
		// unless the new location actually requires another controller.
		// See http://stackoverflow.com/a/12429133/2104976
		let lastRoute = $route.current;
		$scope.$on('$locationChangeSuccess', function(_event) {
			if (lastRoute.$$route.controller === $route.current.$$route.controller) {
				$route.current = lastRoute;
			}
		});

		// Wrap the supplied tracks as a playlist and pass it to the service for playing
		function playTracks(listId, tracks, startIndex /*optional*/) {
			let playlist = _.map(tracks, function(track) {
				return { track: track };
			});
			playQueueService.setPlaylist(listId, playlist, startIndex);
			playQueueService.publish('play');
		}

		function playPlaylistFromTrack(listId, playlist, track) {
			// update URL hash
			window.location.hash = '#/track/' + track.id;

			let index = _.findIndex(playlist, function(i) {return i.track.id == track.id;});
			playQueueService.setPlaylist(listId, playlist, index);

			let startOffset = $location.search().offset || null;
			playQueueService.publish('play', null, startOffset);
			$location.search('offset', null); // the offset parameter has been used up
		}

		$scope.playTrack = function(trackId) {
			let track = libraryService.getTrack(trackId);
			let currentTrack = $scope.$parent.currentTrack;

			// play/pause if currently playing track clicked
			if (currentTrack && track.id === currentTrack.id && currentTrack.type == 'song') {
				playQueueService.publish('togglePlayback');
			}
			else {
				let currentListId = playQueueService.getCurrentPlaylistId();

				// start playing the album/artist from this track if the clicked track belongs
				// to album/artist which is the current play scope
				if (currentListId === 'album-' + track.album.id || currentListId === 'artist-' + track.album.artist.id) {
					playPlaylistFromTrack(currentListId, playQueueService.getCurrentPlaylist(), track);
				}
				// on any other track, start playing the collection from this track
				else {
					playPlaylistFromTrack('albums', libraryService.getTracksInAlbumOrder(), track);
				}
			}
		};

		$scope.playAlbum = function(album) {
			// update URL hash
			window.location.hash = '#/album/' + album.id;
			playTracks('album-' + album.id, album.tracks);
		};

		$scope.playArtist = function(artist) {
			// update URL hash
			window.location.hash = '#/artist/' + artist.id;
			let tracks = _.flatten(_.map(artist.albums, 'tracks'));
			playTracks('artist-' + artist.id, tracks);
		};

		$scope.playFile = function (fileid) {
			if (fileid) {
				Restangular.one('files', fileid).get().then(function(result) {
					$scope.playTrack(result.id);
					scrollToAlbumOfTrack(result.id);
				});
			}
		};

		/**
		 * Two functions for the alphabet-navigation directive integration
		 */
		$scope.getArtistSortName = function(index) {
			return $scope.artists[index].sortName;
		};
		$scope.getArtistElementId = function(index) {
			return 'artist-' + $scope.artists[index].id;
		};

		function getDraggable(type, draggedElement) {
			let draggable = {};
			draggable[type] = draggedElement.id;
			return draggable;
		}

		$scope.getTrackDraggable = function(trackId) {
			return getDraggable('track', libraryService.getTrack(trackId));
		};

		$scope.getAlbumDraggable = function(album) {
			return getDraggable('album', album);
		};

		$scope.getArtistDraggable = function(artist) {
			return getDraggable('artist', artist);
		};

		$scope.decoratedYear = function(album) {
			return album.year ? ' (' + album.year + ')' : '';
		};

		/**
		 * Gets track data to be displayed in the tracklist directive
		 */
		$scope.getTrackData = function(track, _index, scope) {
			return {
				preEscaped: true,
				title: getTitleString(track, scope.artist, false),
				tooltip: getTitleString(track, scope.artist, true),
				number: track.formattedNumber,
				id: track.id
			};
		};

		/**
		 * Formats a track title string for displaying in tracklist directive
		 */
		function getTitleString(track, artist, plaintext) {
			let att = _.escape(track.title);
			if (track.artistId !== artist.id) {
				let artistName = ' (' + _.escape(track.artist.name) + ') ';
				if (!plaintext) {
					artistName = ' <span class="muted">' + artistName + '</span>';
				}
				att += artistName;
			}
			return att;
		}

		playQueueService.subscribe('playlistEnded', function() {
			window.location.hash = '#/';
			updateHighlight(null);
		}, this);

		playQueueService.subscribe('playlistChanged', function(playlistId) {
			updateHighlight(playlistId);
		}, this);

		subscribe('scrollToTrack', function(_event, trackId, animationTime /* optional */) {
			scrollToAlbumOfTrack(trackId, animationTime);
		});

		subscribe('scrollToAlbum', function(_event, albumId, animationTime /* optional */) {
			$scope.$parent.scrollToItem('album-' + albumId, animationTime);
		});

		subscribe('scrollToArtist', function(_event, artistId, animationTime /* optional */) {
			const elemId = 'artist-' + artistId;
			if ($('#' + elemId).length) {
				$scope.$parent.scrollToItem(elemId, animationTime);
			} else {
				// No such artist element, this is probably just a performing artist on some track.
				// Find the first album with a track performed by this artist.
				const tracks = libraryService.findTracksByArtist(artistId);
				scrollToAlbumOfTrack(tracks[0].id);
			}
			
		});

		function scrollToAlbumOfTrack(trackId, animationTime /* optional */) {
			let track = libraryService.getTrack(trackId);
			if (track) {
				$scope.$parent.scrollToItem('album-' + track.album.id, animationTime);
			}
		}

		function isPlaying() {
			return $rootScope.playingView !== null;
		}

		function updateHighlight(playlistId) {
			// remove any previous highlight
			$('.highlight').removeClass('highlight');

			// add highlighting if album or artist is being played
			if (playlistId?.startsWith('album-') || playlistId?.startsWith('artist-')) {
				$('#' + playlistId).addClass('highlight');
			}
		}

		function updateColumnLayout() {
			let containerWidth = $('#albums').width();
			if (containerWidth === 0) {
				// During page load, the view container may not yet have a valid width. On Firefox on Ubuntu,
				// the resize event with the valid width doesn't fire at all after the page load. Retry until
				// a valid width is present. See https://github.com/nc-music/oc-music/issues/1029.
				$timeout(updateColumnLayout, 500);
			} else {
				// Use the single-column layout if there's not enough room for two columns or more
				let colWidth = $scope.albumsCompactLayout ? 383 : 480;
				$('#albums').toggleClass('single-col', containerWidth < 2 * colWidth);
			}
		}

		subscribe('resize', updateColumnLayout);
		subscribe('albumsLayoutChanged', updateColumnLayout);

		function initializePlayerStateFromURL() {
			let hashParts = window.location.hash.slice(1).split('/');
			if (!hashParts[0] && hashParts[1] && hashParts[2]) {
				let type = hashParts[1];
				let id = hashParts[2].split('?')[0]; // crop any query part

				try {
					if (type == 'file') {
						$scope.playFile(id);
					} else if (type == 'artist') {
						let artist = libraryService.getArtist(id);
						if (artist.albums.length > 0) {
							$scope.playArtist(artist);
							$scope.$parent.scrollToItem('artist-' + id);
						} else {
							// If the artist has no albums, then it can't be used as a play scope.
							// Find the first track performed by this artist.
							let tracks = libraryService.findTracksByArtist(id);
							$scope.playTrack(tracks[0].id);
							scrollToAlbumOfTrack(tracks[0].id);
						}
					} else if (type == 'album') {
						$scope.playAlbum(libraryService.getAlbum(id));
						$scope.$parent.scrollToItem('album-' + id);
					} else if (type == 'track') {
						$scope.playTrack(id);
						scrollToAlbumOfTrack(id);
					}
				}
				catch (exception) {
					OC.Notification.showTemporary(gettextCatalog.getString('Requested entry was not found'));
					window.location.hash = '#/';
				}
			}

			updateHighlight(playQueueService.getCurrentPlaylistId());
		}

		/**
		 * Increase number of shown artists asynchronously step-by-step until
		 * they are all visible. This is to avoid script hanging up for too
		 * long on huge collections.
		 */
		function showMore() {
			// show more entries only if the view is not already (being) deactivated
			if ($rootScope.currentView && $scope.$parent) {
				$scope.incrementalLoadLimit += INCREMENTAL_LOAD_STEP;
				if ($scope.incrementalLoadLimit < $scope.$parent.artists.length) {
					$timeout(showMore);
				} else {
					$rootScope.loading = false;

					// Do not reinitialize the player state if it is already playing.
					// This is the case when the user has started playing music while scanning is ongoing,
					// and then hits the 'update' button. Reinitializing would stop and restart the playback.
					if (!isPlaying()) {
						$timeout(initializePlayerStateFromURL);
					} else {
						updateHighlight(playQueueService.getCurrentPlaylistId());
					}

					$timeout(() => $rootScope.$emit('viewActivated'));
				}
			}
		}

		// Start making artists visible immediately if the artists are already loaded.
		// Otherwise it happens on the 'collectionLoaded' event handler.
		if ($scope.$parent.artists) {
			showMore();
		}

		subscribe('collectionLoaded', function() {
			// Start the asynchronous process of making artists visible
			$scope.incrementalLoadLimit = 0;
			showMore();
		});

		subscribe('deactivateView', function() {
			$timeout(() => $rootScope.$emit('viewDeactivated'));
		});
	}
]);
