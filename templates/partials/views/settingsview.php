<div class="view-container" id="music-user" ng-show="!loading">
	<h1 translate>Settings</h1>

	<h2 translate>Music library</h2>
	<div>
		<div class="label-container">
			<label for="music-path" translate>Path to your music collection</label>:
		</div>
		<div class="icon-loading-small operation-in-progress" ng-show="pathChangeOngoing"></div>
		<input type="text" id="music-path" ng-class="{ 'invisible': pathChangeOngoing }"
			ng-model="settings.path" ng-click="selectPath()"/>
		<span style="color:red" ng-show="errorPath" translate>Failed to save the music collection path</span>
		<p><em translate>This setting specifies the folder which will be scanned for music.</em></p>
		<p><em translate>Note: When the path is changed, any previously scanned files outside the new path are removed from the collection and any playlists.</em></p>
	</div>
	<div>
		<div class="label-container">
			<label translate>Paths to exclude from your music collection</label>:
		</div>
		<em>
			<p>
				<span translate>Specify folders within your music collection path which shall be excluded from the scanning.</span>
				<strong class="clickable" ng-click="showExcludeHint=true" ng-hide="showExcludeHint" translate>Show more…</strong>
			</p>
			<div ng-show="showExcludeHint">
				<p translate>You can use the wild cards '?', '*', and '**':</p>
				<ul class="info-list">
					<li translate><strong>?</strong> matches any one character within a path segment</li>
					<li translate><strong>*</strong> matches zero or more arbitrary characters within a path segment</li>
					<li translate><strong>**</strong> matches zero or more arbitrary characters including path segment separators '/'</li>
				</ul>
				<p translate>Paths with a leading '/' character are resolved relative to the user home directory and others relative to the music library base path.</p>
				<p translate>Changes to the excluded paths will only take effect upon rescan.</p>
			</div>
		</em>
		<table id="excluded-paths" class="grid">
			<tr class="excluded-path-row" ng-repeat="path in settings.excludedPaths track by $index">
				<td><input type="text" ng-model="settings.excludedPaths[$index]" on-enter="$event.target.blur()" ng-blur="commitExcludedPaths()"/></td>
				<td class="key-action"><a class="icon icon-folder" ng-click="selectExcludedPath($index)" title="{{ 'Select folder' | translate }}"></a></td>
				<td class="key-action"><a class="icon icon-delete" ng-click="removeExcludedPath($index)" title="{{ 'Remove' | translate }}"></a></td>
			</tr>
			<tr class="add-row" ng-click="addExcludedPath()">
				<td><a class="icon" ng-class="savingExcludedPaths ? 'icon-loading-small' : 'icon-add'"></a></td>
				<td class="key-action"></td>
				<td class="key-action"></td>
			</tr>
		</table>
		<span style="color:red" ng-show="errorIgnoredPaths" translate>Failed to save the ignored paths</span>
	</div>
	<div>
		<div class="label-container">
			<label for="scan-metadata-toggle" translate>Enable metadata scanning</label>
		</div>
		<input type="checkbox" id="scan-metadata-toggle" ng-model="settings.scanMetadata"/>
		<div class="icon-loading-small operation-in-progress" ng-show="savingScanMetadata"></div>
		<span style="color:red" ng-show="errorScanMetadata" translate>Failed to save the setting</span>
		<p><em translate>Many features of the Music app are based on the metadata stored in the audio files. However, scanning this data may consume a lot of time on some systems using external storage. When disabled, the library structure is built based on the file and folder names only.</em></p>
		<p><em translate>Changes on this setting take effect only upon rescan of the library.</em></p>
	</div>

	<h2 translate>Reset</h2>
	<div>
		<div class="label-container">
			<label for="reset-collection" translate>Reset music collection</label>
		</div>
		<div class="icon-loading-small operation-in-progress" ng-show="collectionResetOngoing"></div>
		<input type="button" ng-class="{ 'invisible': collectionResetOngoing }"
			class="icon-delete reset-button" id="reset-collection" ng-click="resetCollection()"/>
		<p><em translate>This action resets all the scanned tracks and all the user-created playlists. After this, the collection can be scanned again from scratch.</em></p>
		<p><em translate>This may be desirable after changing the excluded paths, or if the database would somehow get corrupted. If the latter happens, please report a bug to the <a href="{{issueTrackerUrl}}" target="_blank">issue tracker</a>.</em></p>
	</div>
	<div>
		<div class="label-container">
			<label for="reset-radio" translate>Reset internet radio stations</label>
		</div>
		<div class="icon-loading-small operation-in-progress" ng-show="radioResetOngoing"></div>
		<input type="button" ng-class="{ 'invisible': radioResetOngoing }"
			class="icon-delete reset-button" id="reset-radio" ng-click="resetRadio()"/>
		<p><em translate>This action erases all the stations shown in the "Internet radio" view.</em></p>
	</div>
	<div>
		<div class="label-container">
			<label for="reset-podcasts" translate>Reset podcast channels</label>
		</div>
		<div class="icon-loading-small operation-in-progress" ng-show="podcastsResetOngoing"></div>
		<input type="button" ng-class="{ 'invisible': podcastsResetOngoing }"
			class="icon-delete reset-button" id="reset-podcasts" ng-click="resetPodcasts()"/>
		<p><em translate>This action erases all the channels shown in the "Podcasts" view.</em></p>
	</div>

	<h2 translate>User interface</h2>
	<div ng-show="desktopNotificationsSupported">
		<div class="label-container">
			<label for="song-notifications-toggle" translate>Song change notifications</label>
		</div>
		<input type="checkbox" id="song-notifications-toggle" ng-model="songNotificationsEnabled"/>
		<p><em translate>Show desktop notification when the playing song changes. You also need to have the desktop notifications allowed in your browser for this site.</em></p>
		<p><em translate>Unlike the other settings, this switch is stored per browser and not per user account.</em></p>
	</div>
	<div>
		<div class="label-container">
			<label for="ignored-articles" translate>Articles to ignore on artist names</label>:
		</div>
		<input type="text" id="ignored-articles" ng-model="ignoredArticles" on-enter="$event.target.blur()" ng-blur="commitIgnoredArticles()"/>
		<div class="icon-loading-small operation-in-progress" ng-show="savingIgnoredArticles"></div>
		<span style="color:red" ng-show="errorIgnoredArticles" translate>Failed to save the setting</span>
		<p><em translate>Specify space-delimited list of articles which should be ignored when ordering the artists alphabetically. The articles are case-insensitive.</em></p>
		<p><em translate>In addition to the web interface, this setting is respected in the Subsonic interface although not necessarily by all clients.</em></p>
	</div>
	<div>
		<div class="label-container">
			<label translate>Keyboard shortcuts</label>
		</div>
		<em>
			<p>
				<span translate>Many functionalities of the Music app web UI can be controlled with keyboard shortcuts.</span>
				<strong class="clickable" ng-click="showKeyboardShortcuts=true" ng-hide="showKeyboardShortcuts" translate>Show all…</strong>
			</p>
			<div ng-show="showKeyboardShortcuts">
				<table class="grid">
					<tr><td translate><strong>SPACE</strong> or <strong>K</strong></td><td translate>Play / Pause</td></tr>
					<tr><td translate><strong>SHIFT+SPACE</strong> or <strong>SHIFT+K</strong></td><td translate>Stop</td></tr>
					<tr><td translate><strong>LEFT</strong> or <strong>J</strong></td><td translate>Seek backwards. Seek faster with <strong>SHIFT</strong> or slower with <strong>ALT</strong>.</td></tr>
					<tr><td translate><strong>RIGHT</strong> or <strong>L</strong></td><td translate>Seek forward. Seek faster with <strong>SHIFT</strong> or slower with <strong>ALT</strong>.</td></tr>
					<tr><td translate><strong>CTRL+LEFT</strong></td><td translate>Jump to the previous track</td></tr>
					<tr><td translate><strong>CTRL+RIGHT</strong></td><td translate>Jump to the next track</td></tr>
					<tr><td translate><strong>M</strong></td><td translate>Mute / Unmute</td></tr>
					<tr><td translate><strong>NUMPAD MINUS</strong></td><td translate>Decrease volume. Adjust more with <strong>SHIFT</strong> or less with <strong>ALT</strong>.</td></tr>
					<tr><td translate><strong>NUMPAD PLUS</strong></td><td translate>Increase volume. Adjust more with <strong>SHIFT</strong> or less with <strong>ALT</strong>.</td></tr>
					<tr><td translate><strong>SHIFT+COMMA</strong></td><td translate>Decrease playback speed</td></tr>
					<tr><td translate><strong>SHIFT+PERIOD</strong></td><td translate>Increase playback speed</td></tr>
					<tr><td translate><strong>CTRL+F</strong></td><td translate>Search</td></tr>
				</table>
			</div>
		</em>
	</div>

	<h2 translate>Ampache and Subsonic</h2>
	<div translate>You can browse and play your music collection from external applications which support either Ampache or Subsonic API.</div>
	<div class="warning" translate>
		Note that Music may not be compatible with all Ampache/Subsonic clients. Check the verified <a href="{{ampacheClientsUrl}}" target="_blank">Ampache clients</a> and <a href="{{subsonicClientsUrl}}" target="_blank">Subsonic clients</a>.
	</div>
	<div>
		<code id="ampache-url" ng-bind="settings.ampacheUrl"></code>
		<a class="clipboardButton icon icon-clippy" ng-click="copyToClipboard('ampache-url')"></a><br />
		<em translate>Use this address to browse your music collection from an Ampache compatible player.</em> <em translate>If this URL doesn't work try to append '/server/xml.server.php'.</em>
	</div>
	<div>
		<code id="subsonic-url" ng-bind="settings.subsonicUrl"></code>
		<a class="clipboardButton icon icon-clippy" ng-click="copyToClipboard('subsonic-url')"></a><br />
		<em translate>Use this address to browse your music collection from a Subsonic compatible player.</em>
	</div>
	<div translate>
		Here you can generate passwords to use with the Ampache or Subsonic API. Separate passwords are used because they can't be stored in a really secure way due to the design of the APIs. You can generate as many passwords as you want and revoke them at anytime.
	</div>
	<table id="music-ampache-keys" class="grid" ng-show="settings.ampacheKeys.length">
		<tr class="head">
			<th translate>Description</th>
			<th class="key-action" translate>Revoke API password</th>
		</tr>
		<tr ng-repeat="key in settings.ampacheKeys">
			<td>{{key.description}}</td>
			<td class="key-action"><a class="icon" ng-class="key.loading ? 'icon-loading-small' : 'icon-delete'" ng-click="removeAPIKey(key)"></a></td>
		</tr>
	</table>
	<div id="music-ampache-form">
		<input type="text" id="music-ampache-description" ng-model="ampacheDescription"
			placeholder="{{ 'Description (e.g. App name)' | translate }}" on-enter="addAPIKey()"/>
		<button translate ng-click="addAPIKey()">Generate API password</button>
		<span style="color:red" ng-show="errorAmpache" translate>Failed to generate new Ampache/Subsonic password</span>
		<div id="music-password-info" class="info" ng-show="ampachePassword">
			<span translate>Use the following credentials to connect to this Ampache/Subsonic instance.</span>
			<dl>
				<dt translate>Username:</dt>
				<dd>{{ settings.user }}</dd>
				<dt translate>Password:</dt>
				<dd><span id="pw-label">{{ ampachePassword }}</span><a class="clipboardButton icon icon-clippy" ng-click="copyToClipboard('pw-label')"></a></dd>
			</dl>
		</div>
	</div>
	<div ng-repeat="scrobbler in settings.scrobblers">
		<h2 translate-params-service="scrobbler.service" translate>{{service}} Integration</h2>
		<div>
			<p ng-hide="scrobbler.configured" translate>The server administrator can configure your server for scrobbling, see the <strong>Admin</strong> section below.</p>
			<p ng-show="scrobbler.configured && !scrobbler.hasSession" translate translate-params-service="scrobbler.service">Click <strong>Connect</strong> to scrobble your streams to {{service}}.</p>
			<p ng-show="scrobbler.configured && scrobbler.hasSession" translate translate-params-service="scrobbler.service">Your streams will be scrobbled to {{service}}. Click <strong>Disconnect</strong> to stop scrobbling.</p>
		</div>
		<button ng-show="scrobbler.configured" ng-disabled="scrobbler.hasSession" ng-click="scrobbler.generateScrobbleSession()" translate>Connect</button>
		<button ng-show="scrobbler.configured" ng-disabled="!scrobbler.hasSession" ng-click="scrobbler.clearScrobbleSession()" translate>Disconnect</button>
	</div>

	<h2 translate>Admin</h2>
	<div>
		<p translate translate-params-filename="'<cloud root>/config/config.php'" translate-params-url="'https://github.com/nc-music/oc-music/wiki/Admin-settings'">
			There is no settings UI for the server-wide settings of the Music app but some settings are available by adding specific key-value pairs to the file <samp>{{filename}}</samp>. The available keys are documented <a href="{{url}}" target="_blank">here</a>.
		</p>
	</div>

	<h2 translate>About</h2>
	<div>
		<p>
			<img class="logotype" src="<?php \OCA\Music\Utility\HtmlUtil::printSvgPath('music_logotype_horizontal') ?>" />
			<br/>
			<span translate>Music</span> <span>v{{ settings.appVersion }}</span>
			(<a href="https://github.com/nc-music/oc-music/releases" target="_blank" translate>version history</a>)
		</p>
		<a id="sponsor-link" href="https://github.com/sponsors/nc-music" target="_blank">
			<span class="icon icon-heart"></span>
			<span translate>Support the development of Music</span>
		</a>
		<p translate>
			Please report any bugs and issues to the <a href="{{issueTrackerUrl}}" target="_blank">issue tracker</a>
		</p>
	</div>

</div>
