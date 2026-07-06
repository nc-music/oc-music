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

angular.module('Music').controller('MainController', [
'$rootScope', '$scope', '$timeout', '$window', 'ArtistFactory', 
'playQueueService', 'libraryService', 'inViewService', 'gettextCatalog', 'Restangular',
function ($rootScope, $scope, $timeout, $window, ArtistFactory, 
		playQueueService, libraryService, inViewService, gettextCatalog, Restangular) {

	// retrieve language from backend - is set in ng-app HTML element
	gettextCatalog.currentLanguage = $rootScope.lang;

	// setup dark theme support for Nextcloud versions older than 25
	OCA.Music.DarkThemeLegacySupport.applyOnElement(document.getElementById('app'));

	// Create a global rule to use themed icons for folders everywhere, the default icon-folder is not themed on NC 25 and later.
	// It happens sometimes (at least on Chrome), that OC.MimeType is not yet present when we come here (see 
	// https://github.com/nc-music/oc-music/issues/1137). In those cases, we need to postpone registering the folder style.
	OCA.Music.Utils.executeOnceRefAvailable(() => OC.MimeType, (ocMimeType) => {
		const folderStyle = document.createElement('style');
		folderStyle.innerHTML = `#app-view .icon-folder { background-image: url(${ocMimeType.getIconUrl('dir')}) }`;
		document.head.appendChild(folderStyle);
	});

	$rootScope.playing = false;
	$rootScope.playingView = null;
	$scope.currentTrack = null;
	playQueueService.subscribe('trackChanged', function(listEntry) {
		$scope.currentTrack = listEntry.track;
		$scope.currentTrackIndex = playQueueService.getCurrentIndex();
	});

	playQueueService.subscribe('play', function(playingView) {
		// assume that the play started from current view if no other view given
		$rootScope.playingView = playingView || $rootScope.currentView;
	});

	playQueueService.subscribe('playlistEnded', function() {
		$rootScope.playingView = null;
		$scope.currentTrack = null;
		$scope.currentTrackIndex = -1;
	});

	$scope.getViewIdFromUrl = function() {
		return window.location.hash.split('?')[0];
	};

	$scope.trackCountText = function(playlist) {
		let trackCount = playlist ? playlist.tracks.length : libraryService.getTrackCount();
		return gettextCatalog.getPlural(trackCount, '{{ count }} track', '{{ count }} tracks', { count: trackCount });
	};

	$scope.smartListTrackCountText = function() {
		var trackCount = libraryService.getSmartListTrackCount();
		return gettextCatalog.getPlural(trackCount, '{{ count }} track', '{{ count }} tracks', { count: trackCount });
	};

	$scope.albumCountText = function() {
		let albumCount = libraryService.getAlbumCount();
		return gettextCatalog.getPlural(albumCount, '{{ count }} album', '{{ count }} albums', { count: albumCount });
	};

	$scope.folderCountText = function() {
		if (libraryService.foldersLoaded()) {
			let folderCount = libraryService.getAllFoldersWithTracks().length;
			return gettextCatalog.getPlural(folderCount, '{{ count }} folder', '{{ count }} folders', { count: folderCount });
		} else {
			return '';
		}
	};

	$scope.genresCountText = function() {
		if (libraryService.genresLoaded()) {
			let genreCount = libraryService.getAllGenres().length;
			return gettextCatalog.getPlural(genreCount, '{{ count }} genre', '{{ count }} genres', { count: genreCount });
		} else {
			return '';
		}
	};

	$scope.radioCountText = function() {
		if (libraryService.radioStationsLoaded()) {
			let stationCount = libraryService.getAllRadioStations().length;
			return gettextCatalog.getPlural(stationCount, '{{ count }} station', '{{ count }} stations', { count: stationCount });
		} else {
			return '';
		}
	};

	$scope.podcastsCountText = function() {
		if (libraryService.podcastsLoaded()) {
			let channelsCount = libraryService.getPodcastChannelsCount();
			return gettextCatalog.getPlural(channelsCount, '{{ count }} channel', '{{ count }} channels', { count: channelsCount });
		} else {
			return '';
		}
	};

	$scope.loadIndicatorVisible = function() {
		let contentNotReady = ($rootScope.loadingCollection || $rootScope.searchInProgress || $scope.checkingUnscanned);
		return $rootScope.loading
			|| (contentNotReady && $scope.viewingLibrary());
	};

	$scope.viewingLibrary = function() {
		return $rootScope.currentView != '#/settings'
			&& $rootScope.currentView != '#/radio'
			&& $rootScope.currentView != '#/podcasts';
	};

	$rootScope.$on('updateIgnoredArticles', function(_event, ignoredArticles) {
		libraryService.setIgnoredArticles(ignoredArticles);
	});

	$scope.update = function() {
		$scope.updateAvailable = false;
		$rootScope.loadingCollection = true;

		$scope.artists = null; // the null-value tells the views that data is not yet available
		libraryService.setFolders(null); // invalidate any out-dated folders
		$rootScope.$emit('collectionUpdating');

		// load the music collection
		ArtistFactory.getArtists().then(function(artists) {
			libraryService.setCollection(artists);
			$scope.artists = libraryService.getCollection();

			// Emit the event asynchronously so that the DOM tree has already been
			// manipulated and rendered by the browser when observers get the event.
			$timeout(function() {
				$rootScope.$emit('collectionLoaded');
			});

			// Load playlists once the collection has been loaded
			Restangular.all('playlists').getList({type: 'music-app'}).then(function(playlists) {
				libraryService.setPlaylists(playlists);
				$scope.playlists = libraryService.getAllPlaylists();
				$rootScope.$emit('playlistsLoaded');

				// fetch favorites once library, playlists, and podcasts are all loaded
				if (libraryService.podcastsLoaded()) {
					updateFavorites();
				}
			});

			// Load also the smart playlist once the collection is ready
			$scope.reloadSmartList();

			// Load also genres once the collection has been loaded
			Restangular.one('genres').get().then(function(genres) {
				libraryService.setGenres(genres.genres);
				$scope.filesWithUnscannedGenre = genres.unscanned;
				$rootScope.$emit('genresLoaded');
			});

			// The "no content"/"click to scan"/"scanning" banner uses "collapsed" layout
			// if there are any tracks already visible
			let collapsiblePopups = $('#app-content .emptycontent:not(.no-collapse)');
			if (libraryService.getTrackCount() > 0) {
				collapsiblePopups.addClass('collapsed');
			} else {
				collapsiblePopups.removeClass('collapsed');
			}

			$rootScope.loadingCollection = false;

			// check the availability of unscanned files after the collection has been loaded,
			// unless we are already in the middle of scanning (and intermediate results were just loaded)
			if (!$scope.scanning) {
				updateFilesToScan();
			}
		},
		function(response) { // error handling
			$rootScope.loadingCollection = false;

			let reason = null;
			switch (response.status) {
			case 500:
				reason = gettextCatalog.getString('Internal server error');
				break;
			case 504:
				reason = gettextCatalog.getString('Timeout');
				break;
			default:
				reason = response.status;
				break;
			}
			const errMsg = gettextCatalog.getString('Failed to load the collection:');
			OC.Notification.showTemporary(errMsg + ' ' + reason);
		});

	};

	$scope.updateRadio = function() {
		Restangular.one('radio').get().then(function(radioStations) {
			libraryService.setRadioStations(radioStations);
			$rootScope.$emit('radioStationsLoaded');
		});
	};

	$scope.updatePodcasts = function() {
		Restangular.one('podcasts').get().then(function(podcasts) {
			libraryService.setPodcasts(podcasts);
			$rootScope.$emit('podcastsLoaded');

			// fetch favorites once library, playlists, and podcasts are all loaded
			if (libraryService.collectionLoaded() && libraryService.playlistsLoaded()) {
				updateFavorites();
			}
		});
	};

	function updateFavorites() {
		Restangular.one('favorites').get().then((favorites) => libraryService.setFavorites(favorites));
	}

	// initial loading of artists and radio stations
	$scope.update();
	$scope.updateRadio();
	$scope.updatePodcasts();

	const FILES_TO_SCAN_PER_STEP = 20;
	let filesToScan = null;
	$scope.unscannedFiles = null;
	$scope.dirtyFiles = null;
	$scope.obsoleteFiles = null;

	function updateFilesToScan() {
		$scope.checkingUnscanned = true;
		Restangular.one('scanstate').get().then(function(state) {
			$scope.checkingUnscanned = false;
			$scope.unscannedFiles = state.unscannedFiles;
			$scope.dirtyFiles = state.dirtyFiles;
			$scope.obsoleteFiles = state.obsoleteFiles;
			$scope.noMusicAvailable = (state.scannedCount + state.unscannedFiles.length === 0);
		},
		function(error) {
			$scope.checkingUnscanned = false;
			OC.Notification.showTemporary(
					gettextCatalog.getString('Failed to check for new audio files (error {{ code }}); check the server logs for details', {code: error.status})
			);
		});
	}

	function processNextScanStep() {
		let sliceEnd = $scope.scanningScanned + FILES_TO_SCAN_PER_STEP;
		let filesForStep = filesToScan.slice($scope.scanningScanned, sliceEnd);
		let params = {
				files: filesForStep.join(','),
				finalize: sliceEnd >= filesToScan.length
		};
		Restangular.all('scan').post(params).then(function(result) {
			// Ignore the results if scanning has been cancelled while we
			// were waiting for the result.
			if ($scope.scanning) {
				$scope.scanningScanned = sliceEnd;

				if (result.filesScanned || result.albumCoversUpdated) {
					$scope.updateAvailable = true;
				}

				if ($scope.scanningScanned < filesToScan.length) {
					processNextScanStep();
				} else {
					$scope.scanning = false;
				}

				// Update the newly scanned tracks to UI automatically when
				// a) the first batch is ready
				// b) the scanning process is completed.
				// Otherwise the UI state is updated only when the user hits the 'update' button
				if ($scope.updateAvailable && $scope.artists && ($scope.artists.length === 0 || !$scope.scanning)) {
					$scope.update();
				}
			}
		});
	}

	$scope.startScanning = function(fileIds) {
		filesToScan = fileIds;
		$scope.scanningScanned = 0;
		$scope.scanningTotal = filesToScan.length;

		if (fileIds == $scope.unscannedFiles) {
			$scope.unscannedFiles = null;
		} else if (fileIds == $scope.dirtyFiles) {
			$scope.dirtyFiles = null;
		}
		$scope.scanning = true;
		processNextScanStep();
	};

	$scope.stopScanning = function() {
		$scope.scanning = false;
	};

	$scope.removeObsolete = function() {
		Restangular.all('removescanned').post({files: $scope.obsoleteFiles.join(',')}).then(_result => {
			$scope.obsoleteFiles = null;
			$scope.update();
		});
	};

	$scope.resetScanned = function() {
		$scope.unscannedFiles = null;
		$scope.dirtyFiles = null;
		$scope.obsoleteFiles = null;
		filesToScan = null;
		// Genre and artist IDs have got invalidated while resetting the library, drop any related filters
		if ($scope.smartListParams !== null) {
			$scope.smartListParams.genres = [];
			$scope.smartListParams.artists = [];
		}
	};

	$scope.loadFoldersAndThen = function(callback) {
		if (libraryService.foldersLoaded()) {
			$timeout(callback);
		} else {
			Restangular.one('folders').get().then(function (folders) {
				libraryService.setFolders(folders);
				callback();
			});
		}
	};

	$scope.smartListParams = null; // fetched from the server on the first list load
	$scope.reloadSmartList = function() {
		libraryService.setSmartList(null);

		const genArgs = $scope.smartListParams ?? { useLatestParams: true };

		Restangular.one('playlists/generate').get(genArgs).then((list) => {
			libraryService.setSmartList(list);
			$scope.smartListParams = list.params;
			$rootScope.$emit('smartListLoaded');
		});
	};

	function showDetails(entityType, id) {
		const capType = OCA.Music.Utils.capitalize(entityType);
		const showDetailsEvent = 'show' + capType + 'Details';
		$rootScope.$emit(showDetailsEvent, id);

		if (id) {
			$timeout(function() {
				const scrollEvent = 'scrollTo' + capType;
				const elemId = _.kebabCase(entityType) + '-' + id;
				let elem = document.getElementById(elemId);
				if (elem !== null && !isElementInViewPort(elem)) {
					$rootScope.$emit(scrollEvent, id, 0);
				}
			}, 300);
		}
	}

	$scope.showTrackDetails = function(trackOrId) {
		showDetails('track', trackOrId.id ?? trackOrId);
	};

	$scope.showArtistDetails = function(artistOrId) {
		showDetails('artist', artistOrId.id ?? artistOrId);
	};

	$scope.showAlbumDetails = function(albumOrId) {
		showDetails('album', albumOrId.id ?? albumOrId);
	};

	$scope.showPlaylistDetails = function(playlistOrId) {
		showDetails('playlist', playlistOrId.id ?? playlistOrId);
	};

	$scope.showSmartListFilters = function() {
		$rootScope.$emit('showSmartListFilters');
		$scope.collapseNavigationPaneOnMobile();
	};

	$scope.showRadioStationDetails = function(stationOrId) {
		showDetails('radioStation', stationOrId?.id ?? stationOrId);
		$scope.collapseNavigationPaneOnMobile();
	};

	$scope.showRadioHint = function() {
		$rootScope.$emit('showRadioHint');
		$scope.collapseNavigationPaneOnMobile();
	};

	$scope.showPodcastChannelDetails = function(channelOrId) {
		showDetails('podcastChannel', channelOrId.id ?? channelOrId);
	};

	$scope.showPodcastEpisodeDetails = function(episodeOrId) {
		showDetails('podcastEpisode', episodeOrId.id ?? episodeOrId);
	};

	$scope.hideSidebar = function() {
		$rootScope.$emit('hideDetails');
	};

	$scope.hideScanBar = function(event) {
		event.stopPropagation();
		// Acknowledge the scanning needs without taking any action. The page needs to be reloaded
		// to check them again.
		$scope.unscannedFiles = null;
		$scope.dirtyFiles = null;
		$scope.obsoleteFiles = null;
	};

	function scrollOffset() {
		let controls = document.getElementById('controls');
		let offset = controls?.offsetHeight ?? 0;
		if (OCA.Music.Utils.getScrollContainer()[0] !== document.getElementById('app-content')) {
			let header = document.getElementById('header');
			offset += header?.offsetHeight;
		}
		return offset;
	}

	$scope.scrollToItem = function(itemId, animationTime = 500) {
		if (itemId) {
			let container = OCA.Music.Utils.getScrollContainer();
			let element = $('#' + itemId);
			if (container && element) {
				container.scrollToElement(element, scrollOffset(), animationTime);
			}
		}
	};

	$scope.scrollToTop = function() {
		OCA.Music.Utils.getScrollContainer().scrollTo(0, 0);
	};

	// Navigate to a view selected from the navigation bar
	let navigationDestination = null;
	let afterNavigationCallback = null;
	$scope.navigateTo = function(destination, callback = null) {
		let curView = $rootScope.currentView;
		if (curView != destination) {
			$rootScope.currentView = null;
			navigationDestination = destination;
			afterNavigationCallback = callback;
			$rootScope.loading = true;
			// Deactivate the current view. The view emits 'viewDeactivated' once that is done.
			// In the abnormal special case of no active view, activate the new view immediately.
			if (_.isString(curView)) {
				$rootScope.$emit('deactivateView');
			} else {
				window.location.hash = navigationDestination;
			}
		}

		// Most of our navigation pane items are not <a> or <button> elements, meaning that the core
		// does not collapse the navigation pane automatically upon navigation. The Settings link is an
		// exception. Firing the collapsing twice also caused some severe issues.
		if (destination !== '#/settings') {
			$scope.collapseNavigationPaneOnMobile();
		}
	};

	// Compact/normal layout of the Albums view
	$scope.albumsCompactLayout = (OCA.Music.Storage.get('albums_compact') === 'true');
	$scope.toggleAlbumsCompactLayout = function(useCompact = !$scope.albumsCompactLayout) {
		$scope.albumsCompactLayout = useCompact;
		$('#albums').toggleClass('compact', useCompact);
		$rootScope.$emit('albumsLayoutChanged');

		OCA.Music.Storage.set('albums_compact', useCompact.toString());

		// also navigate to the Albums view if not already open
		$scope.navigateTo('#');
	};

	// Flat/tree layout of the Folders view
	$scope.foldersFlatLayout = (OCA.Music.Storage.get('folders_flat') === 'true');
	$scope.toggleFoldersFlatLayout = function(useFlat = !$scope.foldersFlatLayout) {
		$scope.foldersFlatLayout = useFlat;
		$rootScope.$emit('foldersLayoutChanged');

		OCA.Music.Storage.set('folders_flat', useFlat.toString());

		// also navigate to the Folders view if not already open
		$scope.navigateTo('#/folders');
	};

	$scope.mobileNavigationPaneExpanded = function() {
		return $('body').hasClass('snapjs-left') || $('body').hasClass('snapjs-right');
	};

	$scope.collapseNavigationPaneOnMobile = function() {
		if ($scope.mobileNavigationPaneExpanded()) {
			$timeout(() => {
				// There is a fake button within the navigation pane which can be "clicked" to make the core collapse the pane
				$('#hidden-close-app-navigation-button').trigger('click');
				// Remove any active input focus to ensure that the focus is not left to an input field within the collapsed pane
				$(document.activeElement).trigger('blur');
			});
		}
	};

	$rootScope.$on('viewDeactivated', function() {
		// carry on with the navigation once the previous view is deactivated
		window.location.hash = navigationDestination;
	});

	$rootScope.$on('viewActivated', function() {
		// execute the callback after view activation if any
		if (afterNavigationCallback !== null) {
			$timeout(afterNavigationCallback);
			afterNavigationCallback = null;
		}
	});

	// Test if element is at least partially within the view-port
	function isElementInViewPort(el) {
		return inViewService.isElementInViewPort(el, -scrollOffset());
	}

	function setMasterLayout(classes) {
		let missingClasses = _.difference(['tablet', 'mobile', 'portrait', 'extra-narrow', 'min-width'], classes);
		let appContent = $('#app-content');

		_.each(classes, function(cls) {
			appContent.addClass(cls);
		});
		_.each(missingClasses, function(cls) {
			appContent.removeClass(cls);
		});
	}

	$rootScope.$on('resize', function(_event, appView) {
		const appViewWidth = appView.outerWidth();

		// Adjust controls bar width to not overlap with the scroll bar.
		// Subtract one pixel from the width because outerWidth() seems to
		// return rounded integer value which may sometimes be slightly larger
		// than the actual width of the #app-view.
		const controlsWidth = appViewWidth - 1;
		$('#controls').css('width', controlsWidth);
		$('#controls').css('min-width', controlsWidth);

		// the "no content"/"click to scan"/"scanning" banner has the same width as controls,
		// subtracting the alphabet-navigation width
		const alphaNaviWidth = 50;
		$('#app-content .emptycontent').css('width', controlsWidth - alphaNaviWidth);
		$('#app-content .emptycontent').css('min-width', controlsWidth - alphaNaviWidth);

		// Set the app-content class according to window and view width. This has
		// impact on the overall layout of the app. See music-mobile.css and music-tablet.css.
		if (appViewWidth <= 280) {
			setMasterLayout(['mobile', 'portrait', 'extra-narrow', 'min-width']);
		}
		else if (appViewWidth <= 360) {
			setMasterLayout(['mobile', 'portrait', 'extra-narrow']);
		}
		else if (appViewWidth <= 400) {
			setMasterLayout(['mobile', 'portrait']);
		}
		else if (appViewWidth <= 500 && $window.innerWidth < 1024) {
			setMasterLayout(['mobile']);
		}
		else if (appViewWidth < 1025) {
			setMasterLayout(['tablet']);
		}
		else {
			setMasterLayout([]);
		}

		if (appViewWidth <= 768) {
			$('#controls').addClass('two-line');
		} else {
			$('#controls').removeClass('two-line');
		}

		// Work-around for NC14+: The sidebar width has been limited to 500px (normally 27%),
		// but it's not possible to make corresponding "max margin" definition for #app-content
		// in css. Hence, the margin width is limited here.
		const appContent = $('#app-content');
		if (appContent.hasClass('with-app-sidebar')) {
			let sidebarWidth = $('#app-sidebar').outerWidth();
			let viewWidth = $('#header').outerWidth();

			if (sidebarWidth < 0.27 * viewWidth) {
				appContent.css('margin-inline-end', sidebarWidth);
			} else {
				appContent.css('margin-inline-end', '');
			}
		}
		else {
			appContent.css('margin-inline-end', '');
		}
	});

	if (OCA.Music.Utils.isLegacyLayout()) {
		$('.app-music').addClass('legacy-layout');
	} else if (OCA.Music.Utils.getScrollContainer()[0] instanceof HTMLDocument) {
		// Nextcloud versions 14..24 need some special handling because of different container
		$('.app-music').addClass('nc14to24');
		// To be compatible with NC25, we have set the #app-content position as absolute. To fix problems
		// this causes on older platforms, we need to set the #content to use top-margin instead of top-padding,
		// just as it has been declared by the core on NC25.
		const headerHeight = $('#header').outerHeight();
		$('#content').css('padding-top', 0);
		$('#content').css('margin-top', headerHeight);
		$('#content').css('min-height', `var(--body-height, calc(100% - ${headerHeight}px))`);
	}

	$scope.scanning = false;
	$scope.scanningScanned = 0;
	$scope.scanningTotal = 0;

	$('#app').addClass('loaded');
}]);
