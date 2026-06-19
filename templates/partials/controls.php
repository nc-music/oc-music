<?php use OCA\Music\Utility\HtmlUtil; ?>

<div id="controls" ng-controller="PlayerController" ng-class="{started: started}">
	<div id="play-controls" dir="ltr">
		<img id="skip-prev-button" ng-click="prev()" class="control small svg" alt="{{ 'Previous' | translate }}"
			title="{{ 'Previous' | translate }}&#013;[CTRL+LEFT]"
			src="<?php HtmlUtil::printSvgPath('skip-previous') ?>" />
		<div id="play-pause-container"
			ng-on-contextmenu="playbackBtnContextMenu($event)"
			ng-on-long-press="playbackBtnLongPress($event)"
			data-long-press-delay="500"
		>
			<div id="stop-button" ng-click="stop()" class="control icon-stop svg"
				title="{{ 'Stop' | translate }}&#013;[SHIFT+SPACE]"
				ng-show="shiftHeldDown" alt="{{ 'Stop' | translate }}">
			</div>
			<div id="play-pause-button" ng-click="togglePlayback()" class="control svg"
				ng-class="playing ? 'icon-pause-big' : 'icon-play-big'"
				title="{{ (playing ? 'Pause' : 'Play') | translate }} [SPACE]&#013;{{ playPauseContextMenuVisible ? null : ('(press and hold for more)' | translate) }}"
				ng-show="!shiftHeldDown" alt="{{ (playing ? 'Pause' : 'Play') | translate }}">
			</div>
			<div id="play-pause-menu" class="popovermenu bubble" ng-show="playPauseContextMenuVisible">
				<ul>
					<li ng-show="!shiftHeldDown" ng-click="stop()">
						<a>
							<span class="icon-stop icon svg"></span>
							<span translate>Stop</span>
						</a>
					</li>
					<li ng-show="shiftHeldDown" ng-click="togglePlayback()">
						<a>
							<span ng-class="playing ? 'icon-pause-big' : 'icon-play-big'" class="icon svg"></span>
							<span>{{ (playing ? 'Pause' : 'Play') | translate }}</span>
						</a>
					</li>
					<li id="context-menu-skip-prev" ng-click="prev()">
						<a><span class="icon-skip-prev icon svg"></span><span translate>Previous</span></a>
					</li>
					<li ng-click="$event.stopPropagation()" id="playback-rate-control">
						<a ng-click="stepPlaybackRate(null, false, true)"
							ng-on-contextmenu="stepPlaybackRate($event, true, true)"
							ng-on-long-press="stepPlaybackRate($event, true, true)"
							data-long-press-delay="500"
						>
							<span class="icon-time icon svg"></span>
							<span translate>Playback rate</span><span>: {{ playbackRate | number : 2 }}</span>
						</a>
						<input type="range" min="0.5" max="3.0" step="0.05" ng-model="playbackRate"/>
					</li>
				</ul>
			</div>
		</div>
		<img ng-click="next()" class="control small svg" alt="{{ 'Next' | translate }}"
			title="{{ 'Next' | translate }}&#013;[CTRL+RIGHT]"
			src="<?php HtmlUtil::printSvgPath('skip-next') ?>" />
	</div>

	<div ng-click="scrollToCurrentTrack()" class="albumart clickable" title="{{ coverArtTitle() }}"
		albumart="currentTrack.album || currentTrack.channel || currentTrack "></div>

	<div class="song-info clickable" ng-click="scrollToCurrentTrack()"
		draggable="{{ currentTrack.type === 'song' }}" ui-draggable="true" drag="getDraggable()"
	>
		<span class="title" title="{{ primaryTitle() }}">{{ primaryTitle() }}</span><br />
		<span class="artist" title="{{ secondaryTitle() }}">{{ secondaryTitle() }}</span>
	</div>

	<progress-info ng-show="currentTrack" player="player">
	</progress-info>

	<img id="shuffle" class="control toggle small svg" alt="{{ 'Shuffle' | translate }}" title="{{ shuffleTooltip() }}"
		src="<?php HtmlUtil::printSvgPath('shuffle') ?>" ng-class="{active: shuffle}" ng-click="toggleShuffle()" />
	<img id="repeat" class="control toggle small svg" alt="{{ 'Repeat' | translate }}" title="{{ repeatTooltip() }}"
		ng-src="{{ repeat === 'one' ? '<?php HtmlUtil::printSvgPath('repeat-1') ?>' : '<?php HtmlUtil::printSvgPath('repeat') ?>' }}"
		ng-class="{active: repeat != 'false' }" ng-click="toggleRepeat()" />

	<volume-control player="player">
	</volume-control>
</div>
