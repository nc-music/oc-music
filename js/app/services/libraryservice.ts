/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright 2017 - 2024 Pauli Järvinen
 *
 */

import * as ng from 'angular';
import * as _ from 'lodash';

export interface Artist {
	id : number;
	name : string;
	sortName : string;
	favorite : boolean;
	albums : Album[];
}

export interface Album {
	id : number;
	name : string;
	artist : Artist;
	disk : number;
	diskCount : number;
	favorite : boolean;
	tracks : Track[];
}

export interface BaseTrack {
	id : number;
	type : string;
}

export interface Track extends BaseTrack {
	title : string;
	album : Album;
	artistId : number;
	number : number|null;
	disk : number;
	artist : Artist;
	folder : Folder;
	genre : Genre;
	favorite : boolean;
	get formattedNumber() : string|null;
}

export interface RadioStation extends BaseTrack {
	name : string;
	stream_url : string;
}

export interface PlaylistEntry<T extends BaseTrack> {
	track : T;
}

export interface Playlist {
	id : number;
	name : string;
	favorite : boolean;
	tracks : PlaylistEntry<Track>[];
}

export interface AdvSearchResult {
	id : number;
	tracks? : Track[];
	albums? : Album[];
	artists? : Artist[];
	playlists? : Playlist[];
	podcastEpisodes? : PodcastEpisode[];
	podcastChannels? : PodcastChannel[];
	radioStations? : RadioStation[];
	date : string;
	criteria : string;
}

export interface Folder {
	id : number;
	name : string;
	path : string;
	parent : Folder|null;
	subfolders : Folder[];
	tracks : PlaylistEntry<Track>[]
}

export interface Genre extends Playlist {}

export interface PodcastChannel {
	id : number;
	title : string;
	hash : string;
	favorite : boolean;
	episodes : PodcastEpisode[];
}

export interface PodcastEpisode {
	id : number;
	title : string;
	channel : PodcastChannel;
	favorite : boolean;
	type : string;
}

export interface SearchResult<T> {
	result: T[];
	truncated : boolean;
}

const DIACRITIC_REG_EXP = /[\u0300-\u036f]/g;


export class LibraryService {
	#ignoredArticles : string[] = [];
	#collection : Artist[] = null;
	#artistsIndex : { [id: number] : Artist } = {};
	#albumsIndex : { [id: number] : Album } = {};
	#tracksIndex : { [id: number] : Track } = {};
	#tracksInAlbumOrder : PlaylistEntry<Track>[] = null;
	#tracksInAlphaOrder : PlaylistEntry<Track>[] = null;
	#tracksInGenreOrder : PlaylistEntry<Track>[] = null;
	#albumsCount : number = 0;
	#smartList : Playlist = null;
	#playlists : Playlist[] = null;
	#advSearchResult : AdvSearchResult = null;
	#folders : Folder[] = null;
	#genres : Genre[] = null;
	#radioStations : PlaylistEntry<RadioStation>[] = null;
	#podcastChannels : PodcastChannel[] = null;

	/** 
	 * Sort array according to a specified text field. The field may be specified as a dot-separated path.
	 * Note:  The exact ordering is browser-dependant and usually affected by the browser language.
	 * Note2: The array is sorted in-place instead of returning a new array.
	 */
	#sortByTextField<T>(items : T[], field : string) : void {
		const getSortProperty = _.property(field);
		const locale = OCA.Music.Utils.getLocale();

		items.sort((a : T, b : T) => {
			const aProp : any = getSortProperty(a);
			const bProp : any = getSortProperty(b);

			// sort missing properties after existing ones
			if (!aProp) {
				return 1;
			} else if (!bProp) {
				return -1;
			} else {
				return aProp.localeCompare(bProp, locale);
			}
		});
	}

	/**
	 * Like sortByTextField but to be used with arrays of playlist entries where
	 * field is within outer field "track".
	 */
	#sortByPlaylistEntryTextField(items : PlaylistEntry<any>[], field : string) : void {
		this.#sortByTextField(items, 'track.' + field);
	}

	#sortByNumericField<T>(items : T[], field : string) : void {
		let getSortProperty = _.property(field);
		items.sort((a : T, b : T) => {
			return Number(getSortProperty(a)) - Number(getSortProperty(b));
		});
	}

	#sortByYearAndName(aAlbums : Album[]) : Album[] {
		this.#sortByTextField(aAlbums, 'name');
		aAlbums = _.sortBy(aAlbums, 'year');
		return aAlbums;
	}

	#sortByDiskNumberAndTitle(tracks : Track[]) : Track[] {
		this.#sortByTextField(tracks, 'title');
		tracks = _.sortBy(tracks, 'number');
		tracks = _.sortBy(tracks, 'disk');
		return tracks;
	}

	#createArtistSortName(name : string) : string {
		for (let article of this.#ignoredArticles) {
			if (name.toLowerCase().startsWith(article.toLowerCase() + ' ')) {
				return name.substring(article.length + 1).trim();
			}
		}
		return name;
	}

	
	/**
	 * Formats a track number, possible including disk number, for displaying in tracklist directive
	 */
	#formatTrackNumber(track : Track) : string|null {
		if (track.album.diskCount <= 1) {
			return track.number ? String(track.number) : null;
		} else {
			// multi-disk album
			let number = track.disk + '-';
			number += track.number ?? '?';
			return number;
		}
	}

	/**
	 * Sort the passed in collection alphabetically, and set up parent references
	 */
	#transformCollection(collection : any[]) : Artist[] {
		// setup all the parent references and sort on each level
		_.forEach(collection, (artist) => {
			artist.sortName = this.#createArtistSortName(artist.name);
			artist.favorite = false;
			artist.albums = this.#sortByYearAndName(artist.albums);
			_.forEach(artist.albums, (album) => {
				album.artist = artist;
				album.favorite = false;
				album.tracks = this.#sortByDiskNumberAndTitle(album.tracks);
				_.forEach(album.tracks, (track) => {
					track.artist = this.getArtist(track.artistId);
					track.album = album;
					track.type = 'song';
					track.favorite = false;
					Object.defineProperty(track, 'formattedNumber', {
						get: () => this.#formatTrackNumber(track)
					});
				});
			});
		});
		this.#sortByTextField(collection, 'sortName');

		// filter out any artists with no own albums, after also those have got their `sortName` initialized
		_.remove(collection, (artist) => artist.albums.length === 0);

		return collection;
	}

	#moveArrayElement(array : any[], from : number, to : number) : void {
		array.splice(to, 0, array.splice(from, 1)[0]);
	}

	#errorTrack(trackId : number) : Track {
		return {
			id : trackId,
			type : 'error',
			title : 'ERROR: invalid track',
			album : null,
			artistId : null,
			artist : null,
			folder : null,
			genre : null,
			number : null,
			disk : null,
			favorite : false,
			formattedNumber : null
		};
	}

	#playlistEntry<T extends BaseTrack>(track : T) : PlaylistEntry<T> {
		return (track !== null) ? { track: track } : null;
	}

	#playlistEntryFromId(trackId : number) : PlaylistEntry<Track> {
		return this.#playlistEntry(this.#tracksIndex[trackId] ?? this.#errorTrack(trackId));
	}

	#wrapRadioStation(station : any) : PlaylistEntry<RadioStation> {
		station.type = 'radio';
		return this.#playlistEntry(station);
	}

	#wrapPlaylist(playlist : any) : Playlist {
		let wrapped = $.extend({}, playlist); // clone the playlist
		wrapped.tracks = _(playlist.trackIds).map((id) => this.#playlistEntryFromId(id)).value();
		delete wrapped.trackIds;
		return wrapped;
	}

	// Return values is a kind of "proto folder" as it still has the `parent` field as ID instead of a reference
	#wrapFolder(folder : any) : any {
		let wrapped = <any>this.#wrapPlaylist(folder);
		wrapped.path = null; // set up later
		wrapped.expanded = (folder.parent === null); // the root folder is expanded by default
		return wrapped;
	}

	#setUpFolderPath(folder : Folder) : void {
		// nothing to do if the path has been already set up
		if (folder.path === null) {
			if (folder.parent === null) {
				folder.path = '';
			} else {
				this.#setUpFolderPath(folder.parent);
				folder.path = folder.parent.path + '/' + folder.name;
			}
		}
	}

	#getFolderTracksRecursively(folder : Folder) : PlaylistEntry<Track>[] {
		let subFolderTracks = _(folder.subfolders).map((folder) => this.#getFolderTracksRecursively(folder)).flatten().value();
		return [...subFolderTracks, ...folder.tracks];
	}

	#initPodcastChannel(channel : PodcastChannel) : void {
		channel.favorite = false;
		_.forEach(channel.episodes, (episode) => {
			episode.channel = channel;
			episode.type = 'podcast';
			episode.favorite = false;
		});
	}

	/** Convert string to "folded" form suitable for fuzzy matching */
	#foldString(str : string) : string {
		if (str) {
			str = str.toLocaleLowerCase();

			// Skip the normalization if the browser is ancient and doesn't support it
			if ('normalize' in String.prototype) {
				str = str.normalize('NFD').replace(DIACRITIC_REG_EXP, '');
			}
		}

		return str;
	}

	/** Split search query to array by whitespace.
	 *  As an exception, quoted substrings are kept as one entity. The quotation marks are removed.
	 */
	#splitSearchQuery(query : string) : string[] {
		const regExQuoted = /".*?"/g;

		// Get any quoted substring. Also the quotation marks get extracted, and they are sliced off separately.
		let quoted = query.match(regExQuoted) || <string[]>[];
		quoted = _.map(quoted, (str) => str.slice(1, -1));

		// remove the quoted substrings and stray quotation marks, and extract the rest of the parts
		query = query.replace(regExQuoted, ' ');
		query = query.replace('"', ' ');
		let unquoted = query.match(/\S+/g) || [];

		return quoted.concat(unquoted);
	}

	#objectFieldsContainAll(object : any, getFieldValueFuncs : CallableFunction[], subStrings : string[]) : boolean {
		return _.every(subStrings, (subStr) => {
			return _.some(getFieldValueFuncs, (getter) => {
				let value = getter(object);
				return (value !== null && this.#foldString(value).indexOf(subStr) !== -1);
			});
		});
	}

	#search<T>(container : T[]|{[id: number] : T}, fields : string[]|string, query : string, maxResults : number) : SearchResult<T> {
		query = this.#foldString(query);
		// In case the query contains many words separated with whitespace, each part
		// has to be found but the whitespace is disregarded.
		let queryParts = this.#splitSearchQuery(query);

		// @a fields may be an array or an individual string
		if (!Array.isArray(fields)) {
			fields = [fields];
		}

		// Field may be given as a '.'-separated path;
		// convert the fields to corresponding getter functions.
		let fieldGetterFuncs = _.map(fields, _.property);

		let matchCount = 0;
		let maxLimitReached = false;
		let matches = _.filter(container, (item) => {
			let matched = !maxLimitReached && this.#objectFieldsContainAll(item, fieldGetterFuncs, queryParts);
			if (matched && matchCount++ == maxResults) {
				maxLimitReached = true;
				matched = false;
			}
			return matched;
		});

		return {
			result: matches,
			truncated: maxLimitReached
		};
	}

	// PUBLIC INTERFACE
	setIgnoredArticles(articles : string[]) : void {
		this.#ignoredArticles = articles;
		if (this.#collection) {
			// reorder the existing library if there is one
			_.forEach(this.#artistsIndex, (artist) => {
				artist.sortName = this.#createArtistSortName(artist.name);
			});
			this.#sortByTextField(this.#collection, 'sortName');
			this.#sortByPlaylistEntryTextField(this.#tracksInAlphaOrder, 'artist.sortName');

			_.forEach(this.#genres, (genre) => {
				this.#sortByPlaylistEntryTextField(genre.tracks, 'artist.sortName');
			});
			this.#tracksInGenreOrder = _(this.#genres).map('tracks').flatten().value();
		}
	}
	setCollection(collection : any[]) : void {
		// The artists index is needed to transform the collection
		this.#artistsIndex = _.keyBy(collection, 'id');
		this.#collection = this.#transformCollection(collection);

		// Temporary flat arrays from the transformed (and *sorted*) collection
		const albums = _(this.#collection).map('albums').flatten().value();
		const tracks = _(albums).map('tracks').flatten().value();

		// Indexes also for albums and tracks
		this.#albumsIndex = _.keyBy(albums, 'id');
		this.#tracksIndex = _.keyBy(tracks, 'id');

		// Album order "playlist"
		this.#tracksInAlbumOrder = _.map(tracks, this.#playlistEntry);

		// Alphabetic order "playlist"
		this.#sortByTextField(tracks, 'title');
		this.#sortByTextField(tracks, 'artist.sortName');
		this.#tracksInAlphaOrder = _.map(tracks, this.#playlistEntry);

		// Cache the albums count separately as it's an O(n) operation to get it from the #albumsIndex
		this.#albumsCount = albums.length;
	}
	setPlaylists(lists : any[]) : void {
		this.#playlists = _.map(lists, (list) => this.#wrapPlaylist(list));
		_(this.#playlists).forEach((list) => list.favorite = false);
		this.sortPlaylists();
	}
	setSmartList(list : any) : void {
		if (!list) {
			this.#smartList = null;
		} else {
			this.#smartList = this.#wrapPlaylist(list);
		}
	}
	setAdvancedSearchResult(list : any) : AdvSearchResult|null {
		if (!list) {
			this.#advSearchResult = null;
		} else {
			this.#advSearchResult = {
				id: list.id,
				tracks: _(list.trackIds).map(id => this.getTrack(id)).value(),
				albums: _(list.albumIds).map(id => this.getAlbum(id)).value(),
				artists: _(list.artistIds).map(id => this.getArtist(id)).value(),
				playlists: _(list.playlistIds).map(id => this.getPlaylist(id)).value(),
				podcastEpisodes: _(list.podcastEpisodeIds).map(id => this.getPodcastEpisode(id)).value(),
				podcastChannels: _(list.podcastChannelIds).map(id => this.getPodcastChannel(id)).value(),
				radioStations: _(list.radioStationIds).map(id => this.getRadioStation(id)).value(),
				date: list.date,
				criteria: list.criteria,
			};
		}
		return this.#advSearchResult;
	}
	setFolders(folderData : any[]|null) : void {
		if (!folderData) {
			this.#folders = null;
		} else {
			let protoFolders = _.map(folderData, (folder) => this.#wrapFolder(folder));
			this.#sortByTextField(protoFolders, 'name');
			// the tracks within each folder are sorted by the file name by the back-end

			const rootFolder = _.find(protoFolders, {parent: null});

			// create temporary look-up-table for the folders to speed up setting up the parent references
			let foldersLut : {[id: number] : any} = {};
			_.forEach(protoFolders, (folder) => {
				foldersLut[folder.id] = folder;
			});

			_.forEach(protoFolders, (folder) => {
				// substitute parent id with a reference to the parent folder
				if (folder.parent !== null) {
					// default to the root folder as NC28 returns bad parent IDs for externally mounted and shared folders
					folder.parent = foldersLut[folder.parent] ?? rootFolder;
				}
				// set parent folder references for the contained tracks
				_.forEach(folder.tracks, (entry) => entry.track.folder = folder);
				// init subfolder array
				folder.subfolders = [];
			});

			_.forEach(protoFolders, (folder) => {
				// compile the full path for each folder by following the parent references
				this.#setUpFolderPath(folder);
				// set the subfolder references
				if (folder.parent !== null) {
					folder.parent.subfolders.push(folder);
				}
			});

			this.#folders = protoFolders;
		}
	}
	setGenres(genreData : any[]|null) : void {
		if (!genreData) {
			this.#genres = null;
			this.#tracksInGenreOrder = null;
		} else {
			this.#genres = _.map(genreData, (genre) => this.#wrapPlaylist(genre));
			this.#sortByTextField(this.#genres, 'name');
			// if the first item after sorting is the unknown genre (empty string),
			// then move it to the end of the list
			if (this.#genres.length > 0 && this.#genres[0].name === '') {
				this.#genres.push(this.#genres.shift());
			}

			_.forEach(this.#genres, (genre) => {
				this.#sortByPlaylistEntryTextField(genre.tracks, 'title');
				this.#sortByPlaylistEntryTextField(genre.tracks, 'artist.sortName');

				_.forEach(genre.tracks, (trackEntry) => {
					trackEntry.track.genre = genre;
				});
			});

			this.#tracksInGenreOrder = _(this.#genres).map('tracks').flatten().value();
		}
	}
	setFavorites(favoriteData : any) : void {
		_.forEach(favoriteData.tracks, (id : number) => this.#tracksIndex[id].favorite = true);
		_.forEach(favoriteData.albums, (id : number) => this.#albumsIndex[id].favorite = true);
		_.forEach(favoriteData.artists, (id : number) => this.#artistsIndex[id].favorite = true);
		_.forEach(favoriteData.playlists, (id : number) => _(this.#playlists).find({id: id}).favorite = true);
		_.forEach(favoriteData.podcast_channels, (id : number) => _(this.#podcastChannels).find({id: id}).favorite = true);
		_.forEach(favoriteData.podcast_episodes, (id : number) => {
			_(this.#podcastChannels).map('episodes').flatten().find({id: id}).favorite = true;
		});
	}
	setRadioStations(radioStationsData : any[]) : void {
		this.#radioStations = _.map(radioStationsData, (station) => this.#wrapRadioStation(station));
		this.sortRadioStations();
	}
	sortRadioStations() : void {
		this.#sortByPlaylistEntryTextField(this.#radioStations, 'stream_url');
		this.#sortByPlaylistEntryTextField(this.#radioStations, 'name');
	}
	addRadioStation(radioStationData : any) : void {
		this.addRadioStations([radioStationData]);
	}
	addRadioStations(radioStationsData : any) : void {
		let newStations = _.map(radioStationsData, (station) => this.#wrapRadioStation(station))
		this.#radioStations = this.#radioStations.concat(newStations);
		this.sortRadioStations();
	}
	removeRadioStation(stationId : number) : number {
		let idx = _.findIndex(this.#radioStations, entry => entry.track.id == stationId);
		this.#radioStations.splice(idx, 1);
		return idx;
	}
	setPodcasts(podcastsData : any[]) : void {
		this.#sortByTextField(podcastsData, 'title');
		// set the parent references for each episode 
		_.forEach(podcastsData, this.#initPodcastChannel);
		this.#podcastChannels = podcastsData;
	}
	addPodcastChannel(channel : PodcastChannel) : void {
		this.#initPodcastChannel(channel);
		this.#podcastChannels.push(channel);
		this.#sortByTextField(this.#podcastChannels, 'title');
	}
	replacePodcastChannel(channel : PodcastChannel) {
		this.#initPodcastChannel(channel);
		let idx = _.findIndex(this.#podcastChannels, { id: channel.id });
		this.#podcastChannels[idx] = channel;
	}
	removePodcastChannel(channel : PodcastChannel) {
		let idx = _.findIndex(this.#podcastChannels, { id: channel.id });
		this.#podcastChannels.splice(idx, 1);
	}
	sortPlaylists() : void {
		this.#sortByTextField(this.#playlists, 'name');
	}
	addPlaylist(playlist : any) : void {
		this.#playlists.push(this.#wrapPlaylist(playlist));
		this.sortPlaylists();
	}
	removePlaylist(playlist : any) : void {
		this.#playlists.splice(this.#playlists.indexOf(playlist), 1);
	}
	replacePlaylist(playlist : any) : void {
		let idx = _.findIndex(this.#playlists, { id: playlist.id });
		this.#playlists[idx] = this.#wrapPlaylist(playlist);
	}
	addToPlaylist(playlistId : number, trackId : number) : void {
		let playlist = this.getPlaylist(playlistId);
		playlist.tracks.push(this.#playlistEntryFromId(trackId));
	}
	removeFromPlaylist(playlistId : number, indexToRemove : number) : void {
		let playlist = this.getPlaylist(playlistId);
		playlist.tracks.splice(indexToRemove, 1);
	}
	reorderPlaylist(playlistId : number, srcIndex : number, dstIndex : number) : void {
		let playlist = this.getPlaylist(playlistId);
		this.#moveArrayElement(playlist.tracks, srcIndex, dstIndex);
	}
	sortPlaylist(playlistId : number, byProperty : string) : void {
		let playlist = this.getPlaylist(playlistId);
		switch (byProperty) {
		case 'track':
			this.#sortByTextField(playlist.tracks, 'track.title');
			break;
		case 'album':
			this.#sortByTextField(playlist.tracks, 'track.title');
			this.#sortByNumericField(playlist.tracks, 'track.number');
			this.#sortByNumericField(playlist.tracks, 'track.disk');
			this.#sortByTextField(playlist.tracks, 'track.album.name');
			break;
		case 'artist':
			this.#sortByTextField(playlist.tracks, 'track.title');
			this.#sortByTextField(playlist.tracks, 'track.artist.sortName');
			break;
		default:
			console.error('Unexpected playlist sort property ' + byProperty);
			break;
		}
	}
	removeDuplicatesFromPlaylist(playlistId : number) : PlaylistEntry<Track>[] {
		let playlist = this.getPlaylist(playlistId);
		let foundIds : {[id: number] : boolean} = {};
		let indicesToRemove = [];

		// find the indices containing duplicates
		for (let i = 0; i < playlist.tracks.length; ++i) {
			let id = playlist.tracks[i].track.id;
			if (id in foundIds) {
				indicesToRemove.push(i);
			} else {
				foundIds[id] = true;
			}
		}

		// remove (and return) the duplicates
		return _.pullAt(playlist.tracks, indicesToRemove);
	}
	getCollection() : Artist[] {
		return this.#collection;
	}
	getArtist(id : number) : Artist {
		return this.#artistsIndex[id] ?? null;
	}
	/**
	 * Get all the artists, including the ones with no own albums
	 */
	getAllArtists() : Artist[] {
		const artists = _.toArray(this.#artistsIndex);
		this.#sortByTextField(artists, 'sortName');
		return artists;
	}
	getAlbum(id : number) : Album {
		return this.#albumsIndex[id] ?? null;
	}
	getAlbumCount() : number {
		return this.#albumsCount;
	}
	getTrack(id : number) : Track {
		return this.#tracksIndex[id];
	}
	getTracksInAlphaOrder() : PlaylistEntry<Track>[] {
		return this.#tracksInAlphaOrder;
	}
	getTracksInAlbumOrder() : PlaylistEntry<Track>[] {
		return this.#tracksInAlbumOrder;
	}
	getTracksInFolderOrder(treeMode : boolean) : PlaylistEntry<Track>[] {
		return treeMode
			? this.#getFolderTracksRecursively(this.getRootFolder())
			: _(this.#folders).map('tracks').flatten().value();
	}
	getTracksInGenreOrder() : PlaylistEntry<Track>[] {
		return this.#tracksInGenreOrder;
	}
	getTrackCount() : number {
		return this.#tracksInAlphaOrder?.length ?? 0;
	}
	getSmartListTrackCount() : number {
		return this.#smartList?.tracks?.length ?? 0;
	}
	getSmartList() : Playlist|null {
		return this.#smartList;
	}
	getAdvancedSearchResult() : AdvSearchResult|null {
		return this.#advSearchResult;
	}
	getPlaylist(id : number) : Playlist|undefined {
		return _.find(this.#playlists, { id: Number(id) });
	}
	getAllPlaylists() : Playlist[] {
		return this.#playlists;
	}
	getFolder(id : number) : Folder {
		return _.find(this.#folders, { id: Number(id) });
	}
	getFolderTracks(folder : Folder, recursively : boolean) : PlaylistEntry<Track>[] {
		return recursively ? this.#getFolderTracksRecursively(folder) : folder.tracks;
	}
	getAllFoldersWithTracks() : Folder[] {
		return _.filter(this.#folders, (folder) => folder.tracks.length > 0);
	}
	getRootFolder() : Folder {
		return _.find(this.#folders, { parent: null });
	}
	getGenre(id : number) : Genre {
		return _.find(this.#genres, { id: Number(id) });
	}
	getAllGenres() : Genre[] {
		return this.#genres;
	}
	getRadioStation(id : number) : RadioStation {
		return _.find(this.#radioStations, ['track.id', Number(id)])?.track;
	}
	getAllRadioStations() : PlaylistEntry<RadioStation>[] {
		return this.#radioStations;
	}
	getPodcastEpisode(id : number) : PodcastEpisode {
		return _(this.#podcastChannels).map('episodes').flatten().find({ id: Number(id) });
	}
	getAllPodcastEpisodes() : PodcastEpisode[] {
		return _(this.#podcastChannels).map('episodes').flatten().value();
	}
	getPodcastChannel(id : number) : PodcastChannel {
		return _.find(this.#podcastChannels, { id: Number(id) });
	}
	getAllPodcastChannels() : PodcastChannel[] {
		return this.#podcastChannels;
	}
	getPodcastChannelsCount() : number {
		return this.#podcastChannels?.length ?? 0;
	}
	findTracksByArtist(artistId : number) : Track[] {
		return _(this.#tracksInAlphaOrder).filter(e => e.track.artistId == artistId).map('track').value();
	}
	collectionLoaded() : boolean {
		return this.#collection !== null;
	}
	playlistsLoaded() : boolean {
		return this.#playlists !== null;
	}
	foldersLoaded() : boolean {
		return this.#folders !== null;
	}
	genresLoaded() : boolean {
		return this.#genres !== null;
	}
	radioStationsLoaded() : boolean {
		return this.#radioStations !== null;
	}
	podcastsLoaded() : boolean {
		return this.#podcastChannels !== null;
	}
	searchTracks(query : string, maxResults = Infinity) : SearchResult<Track> {
		return this.#search(this.#tracksIndex, ['title', 'artist.name'], query, maxResults);
	}
	searchTracksInAlbums(query : string, maxResults = Infinity) : SearchResult<Track> {
		return this.#search(
				this.#tracksIndex,
				['title', 'artist.name', 'album.name', 'album.year', 'album.artist.name'],
				query,
				maxResults);
	}
	searchTracksInFolders(query : string, maxResults = Infinity) : SearchResult<Track> {
		return this.#search(
				this.#tracksIndex,
				['title', 'artist.name', 'folder.path'],
				query,
				maxResults);
	}
	searchTracksInGenres(query : string, maxResults = Infinity) : SearchResult<Track> {
		return this.#search(
				this.#tracksIndex,
				['title', 'artist.name', 'genre.name'],
				query,
				maxResults);
	}
	searchTracksInSmartlist(query : string, maxResults = Infinity) : SearchResult<Track> {
		let tracks = _.map(this.#smartList.tracks, 'track');
		return this.#search(tracks, ['title', 'artist.name'], query, maxResults);
	}
	searchTracksInPlaylist(playlistId : number, query : string, maxResults = Infinity) : SearchResult<Track> {
		let entries = this.getPlaylist(playlistId)?.tracks || [];
		let tracks = _.map(entries, 'track');
		tracks = _.uniq(tracks);
		return this.#search(tracks, ['title', 'artist.name'], query, maxResults);
	}
	searchRadioStations(query : string, maxResults = Infinity) : SearchResult<RadioStation> {
		let stations = _.map(this.#radioStations, 'track');
		return this.#search(stations, ['name', 'stream_url'], query, maxResults);
	}
	searchPodcasts(query : string, maxResults = Infinity) : SearchResult<PodcastEpisode> {
		let episodes = _(this.#podcastChannels).map('episodes').flatten().value();
		return this.#search(episodes, ['title', 'channel.title'], query, maxResults);
	}
}

ng.module('Music').service('libraryService', [LibraryService]);
