/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2024 - 2026
 */

import { PlayerWrapper } from "./playerwrapper";

interface BrowserMediaSessionInfo {
	title? : string|null;
	artist? : string|null;
	album? : string|null;
	cover? : string|null;
	coverMime? : string|null;
};

interface BrowserMediaSessionControls {
	play? : null | (() => void);
	pause? : null | (() => void);
	stop? : null | (() => void);
	seekBackward? : null | (() => void);
	seekForward? : null | (() => void);
	previousTrack? : null | (() => void);
	nextTrack? : null | (() => void);
}

/**
 * Wrapper for the media session API available on many modern browsers, including at least
 * Chrome, Edge, and Firefox. The API brings the bindings with the special multimedia keys
 * possibly present on the keyboard, as well as any OS multimedia controls available e.g. 
 * in status pane and/or lock screen.
 * 
 * This class can be safely used regardless of the capabilities of the browser used.
 */
export class BrowserMediaSession {

	constructor(player : PlayerWrapper) {
		if ('mediaSession' in navigator) {
			player.on('play', () => {
				navigator.mediaSession.playbackState = 'playing';
			});
			player.on('pause', () => {
				navigator.mediaSession.playbackState = 'paused';
			});
			player.on('stop', () => {
				navigator.mediaSession.playbackState = 'none';
			});
			if ('setPositionState' in navigator.mediaSession) {
				player.on('progress', (position : number) => {
					const duration = player.getDuration();
					position = Math.max(0, Math.min(position, duration)); // position is sometimes slightly more than duration, which would cause an exception in setPositionState
					if (Number.isFinite(duration)) {
						navigator.mediaSession.setPositionState({
							duration: duration / 1000,
							position: position / 1000
						});
					}
				})
			}
		}
	}

	registerControls(controls : BrowserMediaSessionControls) : void {
		if ('mediaSession' in navigator) {
			const registerHandler = (action : MediaSessionAction, handler : () => void) => {
				try {
					navigator.mediaSession.setActionHandler(action, handler);
				} catch (error) {
					console.log('The media control "' + action + '" is not supported by the browser');
				}
			};
	
			registerHandler('play', controls.play);
			registerHandler('pause', controls.pause);
			registerHandler('stop', controls.stop);
			registerHandler('seekbackward', controls.seekBackward);
			registerHandler('seekforward', controls.seekForward);
			registerHandler('previoustrack', controls.previousTrack);
			registerHandler('nexttrack', controls.nextTrack);
		}
	}

	showInfo(info : BrowserMediaSessionInfo) : void {
		if ('mediaSession' in navigator) {
			navigator.mediaSession.metadata = new MediaMetadata({
				title: info.title ?? undefined,
				artist: info.artist ?? undefined,
				album: info.album ?? undefined,
				artwork: info.cover ? [{
					src: info.cover,
					type: info.coverMime ?? undefined
				}] : undefined
			});
		}
	}

	clearInfo() : void {
		if ('mediaSession' in navigator) {
			navigator.mediaSession.metadata = null;
		}
	}
}