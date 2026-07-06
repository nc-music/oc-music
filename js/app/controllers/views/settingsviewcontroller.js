/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Gregory Baudet <gregory.baudet@gmail.com>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Gregory Baudet 2018
 * @copyright Pauli Järvinen 2018 - 2023
 */

angular.module('Music').controller('SettingsViewController', [
	'$scope', '$rootScope', 'Restangular', '$q', '$timeout', 'gettextCatalog',
	function ($scope, $rootScope, Restangular, $q, $timeout, gettextCatalog) {

		$rootScope.currentView = $scope.getViewIdFromUrl();

		$scope.issueTrackerUrl = 'https://github.com/nc-music/oc-music/issues';
		$scope.ampacheClientsUrl = 'https://github.com/nc-music/oc-music/wiki/Ampache';
		$scope.subsonicClientsUrl = 'https://github.com/nc-music/oc-music/wiki/Subsonic';

		$scope.desktopNotificationsSupported = (typeof Notification !== 'undefined');

		let savedExcludedPaths = [];

		// $rootScope listeneres must be unsubscribed manually when the control is destroyed
		let unsubFuncs = [];

		function subscribe(event, handler) {
			unsubFuncs.push( $rootScope.$on(event, handler) );
		}

		$scope.$on('$destroy', function () {
			_.each(unsubFuncs, function(func) { func(); });
		});

		$scope.selectPath = function() {
			OCA.Music.Dialogs.folderPicker(
				gettextCatalog.getString('Path to your music collection'),
				function (path) {
					if ($scope.settings.path !== path) {
						$scope.pathChangeOngoing = true;

						// Stop any ongoing scan if path got changed
						$scope.$parent.stopScanning();

						// Store the parent reference before posting the changed value to backend;
						// $scope.$parent may not be available any more in the callback in case
						// the user has navigated to another view in the meantime.
						let parent = $scope.$parent;
						Restangular.all('settings/user/path').post({value: path}).then(
							function (data) {
								if (data.success) {
									$scope.errorPath = false;
									$scope.settings.path = path;
									parent.update();
								} else {
									$scope.errorPath = true;
								}
								$scope.pathChangeOngoing = false;
							},
							function(_error) { // error handling
								$scope.pathChangeOngoing = false;
								$scope.errorPath = true;
							}
						);
					}
				}
			);
		};

		$scope.selectExcludedPath = function(index) {
			OCA.Music.Dialogs.folderPicker(
				gettextCatalog.getString('Path to exclude from your music collection'),
				function (path) {
					$scope.settings.excludedPaths[index] = path;
					$scope.commitExcludedPaths();
				},
				$scope.settings.path // initial folder, only on NC16+
			);
		};

		$scope.removeExcludedPath = function(index) {
			$scope.settings.excludedPaths.splice(index, 1);
			$scope.commitExcludedPaths();
		};

		$scope.addExcludedPath = function() {
			$scope.settings.excludedPaths.push('');
			// no commit here, as the empty path is meaningless
		};

		$scope.commitExcludedPaths = function() {
			// Get the entered paths, trimming excess white space and filtering out any empty paths
			let paths = $scope.settings.excludedPaths;
			paths = _.map(paths, function(path) { return path.trim(); });
			paths = _.filter(paths, function(path) { return path !== ''; });

			// Send the paths to the back-end if there are any changes
			if (!_.isEqual(paths, savedExcludedPaths)) {
				$scope.savingExcludedPaths = true;
				Restangular.all('settings/user/exclude_paths').post({value: paths}).then(
					function(_data) {
						// success
						$scope.savingExcludedPaths = false;
						$scope.errorIgnoredPaths = false;
						savedExcludedPaths = paths;
					},
					function(_error) {
						// error handling
						$scope.savingExcludedPaths = false;
						$scope.errorIgnoredPaths = true;
					}
				);
			}
		};

		let cancelSaveScanMetada = null;
		$scope.savingScanMetadata = 0;
		$scope.$watch('settings.scanMetadata', function(enabled, previouslyEnabled) {
			// send the new value to the server only when moving between valid states, not on first init
			if (enabled !== undefined && previouslyEnabled !== undefined) {
				// if there is already one save operation running, cancel that first
				if (cancelSaveScanMetada !== null) {
					cancelSaveScanMetada.resolve();
				}

				$scope.savingScanMetadata++;
				cancelSaveScanMetada = $q.defer();
				Restangular.all('settings/user/enable_scan_metadata').withHttpConfig({timeout: cancelSaveScanMetada.promise}).post({value: enabled}).then(
					function(_data) {
						// success
						$scope.savingScanMetadata--;
						$scope.errorScanMetadata = false;
						cancelSaveScanMetada = null;
					},
					function(error) {
						// error handling
						$scope.savingScanMetadata--;
						$scope.errorScanMetadata = (error.xhrStatus != 'abort'); // aborting is not an error
						cancelSaveScanMetada = null;
					}
				);
			}
		});

		$scope.resetCollection = function() {
			OC.dialogs.confirm(
				gettextCatalog.getString('Are you sure to reset the music collection? This removes all scanned tracks and user-created playlists!'),
				gettextCatalog.getString('Reset music collection'),
				function(confirmed) {
					if (confirmed) {
						$scope.collectionResetOngoing = true;

						// stop any ongoing scan before posting the reset command
						$scope.$parent.stopScanning();

						// $scope.$parent may not be available any more in the callback in case
						// the user has navigated to another view in the meantime
						let parent = $scope.$parent;
						let executeReset = function() {
							Restangular.all('resetscanned').post().then(
									function(data) {
										if (data.success) {
											parent.resetScanned();
											parent.update();
										}
										$scope.collectionResetOngoing = false;
									},
									function(error) { // error handling
										$scope.collectionResetOngoing = false;
										const errMsg = gettextCatalog.getString('Failed to reset the collection:');
										OC.Notification.showTemporary(errMsg + ' ' + error.status);
									}
								);
						};

						// Trigger the reset with a small delay. This is to tackle a small issue when
						// reset button is pressed during scanning: if the POST /api/scan call fires
						// just before POST /api/resetscanned, the server may receive these two messages
						// in undeterministic order. This is because modern browsers typically hold several
						// TCP connections and successive messages are often sent through different TCP pipes.
						$timeout(executeReset, 100);
					}
				},
				true
			);
		};

		$scope.resetRadio = function() {
			OC.dialogs.confirm(
				gettextCatalog.getString('Are you sure to permanently erase all the configured internet radio stations?'),
				gettextCatalog.getString('Reset internet radio stations'),
				function(confirmed) {
					if (confirmed) {
						$scope.radioResetOngoing = true;

						// $scope.$parent may not be available any more in the callback in case
						// the user has navigated to another view in the meantime
						let parent = $scope.$parent;
						Restangular.all('radio/reset').post().then(
								function(data) {
									if (data.success) {
										parent.updateRadio();
									}
									$scope.radioResetOngoing = false;
								},
								function(error) { // error handling
									$scope.radioResetOngoing = false;
									const errMsg = gettextCatalog.getString('Failed to reset the radio stations:');
									OC.Notification.showTemporary(errMsg + ' ' + error.status);
								}
							);
					}
				},
				true
			);
		};

		$scope.resetPodcasts = function() {
			OC.dialogs.confirm(
				gettextCatalog.getString('Are you sure to permanently erase all the configured podcast channels?'),
				gettextCatalog.getString('Reset podcast channels'),
				function(confirmed) {
					if (confirmed) {
						$scope.podcastsResetOngoing = true;

						// $scope.$parent may not be available any more in the callback in case
						// the user has navigated to another view in the meantime
						let parent = $scope.$parent;
						Restangular.all('podcasts/reset').post().then(
								function(data) {
									if (data.success) {
										parent.updatePodcasts();
									}
									$scope.podcastsResetOngoing = false;
								},
								function(error) { // error handling
									$scope.podcastsResetOngoing = false;
									const errMsg = gettextCatalog.getString('Failed to reset the podcast channels:');
									OC.Notification.showTemporary(errMsg + ' ' + error.status);
								}
							);
					}
				},
				true
			);
		};

		if ($scope.desktopNotificationsSupported) {
			$scope.songNotificationsEnabled = (OCA.Music.Storage.get('song_notifications') !== 'false');

			$scope.$watch('songNotificationsEnabled', function(enabled) {
				OCA.Music.Storage.set('song_notifications', enabled.toString());
	
				if (enabled && Notification.permission !== 'granted') {
					Notification.requestPermission().then(function(permission) {
						if (permission !== 'granted') {
							$timeout(() => $scope.songNotificationsEnabled = false);
						}
					});
				}
			});
		}

		$scope.commitIgnoredArticles = function() {
			// Get the entered articles, trimming excess white space and filtering out any empty ones
			let articles = $scope.ignoredArticles.split(/\s+/);

			// Send the articles to the back-end if there are any changes
			if (!_.isEqual(articles, $scope.settings.ignoredArticles)) {
				$scope.savingIgnoredArticles = true;
				Restangular.all('settings/user/ignored_articles').post({value: articles}).then(
					function(_data) {
						// success
						$scope.savingIgnoredArticles = false;
						$scope.errorIgnoredArticles = false;
						$scope.settings.ignoredArticles = articles;
						$rootScope.$emit('updateIgnoredArticles', articles);
					},
					function(_error) {
						// error handling
						$scope.savingIgnoredArticles = false;
						$scope.errorIgnoredArticles = true;
					}
				);
			}
		};

		$scope.addAPIKey = function() {
			let newRow = {description: $scope.ampacheDescription, loading: true};
			$scope.settings.ampacheKeys.push(newRow);
			Restangular.all('settings/user/keys').post({ description: $scope.ampacheDescription, length: 12 }).then(
				function(data) {
					newRow.loading = false;
					newRow.id = data.id;
					$scope.ampacheDescription = '';
					$scope.ampachePassword = data.password;
					$scope.errorAmpache = false;
				},
				function (error) {
					_.remove($scope.settings.ampacheKeys, newRow);
					$scope.ampachePassword = '';
					$scope.errorAmpache = true;
					console.error(error.data.message || error);
				}
			);
		};

		$scope.removeAPIKey = function(key) {
			key.loading=true;
			Restangular.one('settings/user/keys', key.id).remove().then(function(data) {
				if (data.success) {
					// refresh remaining ampacheKeys
					Restangular.one('settings/user/keys').get().then(function (keys) {
						$scope.settings.ampacheKeys = keys;
					});
				} else {
					key.loading=false;
				}
			});
		};

		$rootScope.$on('viewActivated', function () {
			$scope.settings.scrobblers.forEach(function (scrobbler) {
				scrobbler.generateScrobbleSession = function() {
					window.open(scrobbler.tokenRequestUrl, '_blank', { popup: true });
					const bc = new BroadcastChannel(scrobbler.identifier + '-scrobble-session-result');
					bc.onmessage = function(e) {
						$timeout(() => scrobbler.hasSession = e.data);
					};
				};

				scrobbler.clearScrobbleSession = function() {
					const errHandler = function(error) {
						const errMsg = gettextCatalog.getString('Failed to clear scrobbling session.');
						OC.Notification.showTemporary(
							errMsg + ' ' + error.message
						);
					};
					Restangular.all('scrobbler/clearSession')
						.post({serviceIdentifier: scrobbler.identifier})
						.then(function(data) {
							if (data?.success === true) {
								scrobbler.hasSession = false;
								return;
							}
							errHandler(data.error);
						}, errHandler);
				};
			});
		});

		$scope.copyToClipboard = function(elementId) {
			let range = document.createRange();
			range.selectNode(document.getElementById(elementId));
			window.getSelection().removeAllRanges(); // clear current selection
			window.getSelection().addRange(range); // to select text
			let success = document.execCommand('copy');

			if (success) {
				OC.Notification.showTemporary(
						gettextCatalog.getString('Text copied to clipboard'));
			}
		};

		$scope.errorPath = false;
		$scope.errorAmpache = false;

		$timeout(function() {
			Restangular.one('settings').get().then(function (value) {
				$scope.settings = value;
				$rootScope.loading = false;
				savedExcludedPaths = _.clone(value.excludedPaths);
				$scope.ignoredArticles = value.ignoredArticles.join(' ');
				$rootScope.$emit('viewActivated');
			});
		});

		subscribe('deactivateView', function() {
			$rootScope.$emit('viewDeactivated');
		});

	}
]);
