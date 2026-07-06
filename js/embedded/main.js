/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2017 - 2025
 */

(function() {
	let mPlayer = new OCA.Music.EmbeddedPlayer();

	const mAudioMimes = _.filter([
		'audio/aac',
		'audio/aiff',
		'audio/basic',
		'audio/flac',
		'audio/mp4',
		'audio/m4b',
		'audio/mpeg',
		'audio/ogg',
		'audio/wav',
		'audio/x-aiff',
		'audio/x-caf',
	], (mime) => mPlayer.canPlayMime(mime));

	const mPlaylistMimes = [
		'audio/mpegurl',
		'audio/x-scpls',
		'application/vnd.ms-wpl'
	];

	function register() {
		OCA.Music.folderView = new OCA.Music.FolderView(mPlayer, mAudioMimes, mPlaylistMimes);

		// First, try to load the Nextcloud Files API. This works on NC28+ but not on NC27. On the other hand, 
		// the call succeeds on ownCloud but the registration just does nothing there. Note that we can't wait
		// for the page load to be finished before doing this because that would be too late for the registration
		// and cause the issue https://github.com/nc-music/oc-music/issues/1126.
		import('@nextcloud/files').then(ncFiles => {
			import('@nextcloud/sharing/public').then(ncSharingPublic => {
				const sharingToken = ncSharingPublic.isPublicShare() ? ncSharingPublic.getSharingToken() : null;
				OCA.Music.folderView.registerToNcFiles(ncFiles, sharingToken);
			});
		}).catch(_e => {/*ignore*/});

		// The older fileActions API is used on ownCloud and NC27 and older, but also in NC28..30 when operating
		// within a link-shared folder. It's also possible that we are operating within the share page of an
		// individual file; this situation can be identified only after the page has been completely loaded.
		window.addEventListener('DOMContentLoaded', () => {
			const sharingToken = $('#sharingToken').val(); // undefined if not on a public share or on NC31+

			if ($('#header').hasClass('share-file')) {
				// individual link shared file
				OCA.Music.fileShareView = new OCA.Music.FileShareView(mPlayer, mAudioMimes, sharingToken);
			} else {
				// Files app or a link shared folder
				if (OCA.Files?.fileActions) {
					OCA.Music.folderView.registerToFileActions(OCA.Files.fileActions, sharingToken);
				}
				OCA.Music.initPlaylistTabView(mPlaylistMimes);
				connectPlaylistTabViewEvents(OCA.Music.folderView);
			}
		});
	}

	function connectPlaylistTabViewEvents(folderView) {
		if (OCA.Music.playlistTabView) {
			OCA.Music.playlistTabView.on('playlistItemClick', (file, index) => folderView.onPlaylistItemClick(file, index));

			OCA.Music.playlistTabView.on('rendered', () => {
				const plState = folderView.playlistFileState();
				if (plState !== null) {
					OCA.Music.playlistTabView.setCurrentTrack(plState.fileId, plState.index);
				} else {
					OCA.Music.playlistTabView.setCurrentTrack(null);
				}
			});
		}
	}

	register();
})();
