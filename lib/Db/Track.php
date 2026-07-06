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
 * @copyright Pauli Järvinen 2016 - 2025
 */

namespace OCA\Music\Db;

use OCA\Music\Utility\StringUtil;
use OCA\Music\Utility\Util;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?int getNumber()
 * @method void setNumber(?int $number)
 * @method ?int getDisk()
 * @method void setDisk(?int $disk)
 * @method ?int getYear()
 * @method void setYear(?int $year)
 * @method int getArtistId()
 * @method void setArtistId(int $artistId)
 * @method int getAlbumId()
 * @method void setAlbumId(int $albumId)
 * @method ?int getLength()
 * @method void setLength(?int $length)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method ?int getBitrate()
 * @method void setBitrate(?int $bitrate)
 * @method string getMimetype()
 * @method void setMimetype(string $mimetype)
 * @method ?string getMbid()
 * @method void setMbid(?string $mbid)
 * @method ?string getStarred()
 * @method void setStarred(?string $timestamp)
 * @method int getRating()
 * @method void setRating(int $rating)
 * @method ?int getGenreId()
 * @method void setGenreId(?int $genreId)
 * @method int getPlayCount()
 * @method void setPlayCount(int $count)
 * @method ?string getLastPlayed()
 * @method void setLastPlayed(?string $timestamp)
 * @method int getDirty()
 * @method void setDirty(int $dirty)
 *
 * @method string getFilename()
 * @method int getSize()
 * @method int getFileModTime()
 * @method ?string getAlbumName()
 * @method ?string getArtistName()
 * @method ?string getGenreName()
 * @method int getFolderId()
 */
class Track extends Entity {
	public string $title = '';
	public ?int $number = null;
	public ?int $disk = null;
	public ?int $year = null;
	public ?int $artistId = null;
	public ?int $albumId = null;
	public ?int $length = null;
	public int $fileId = 0;
	public ?int $bitrate = null;
	public string $mimetype = '';
	public ?string $mbid = null;
	public ?string $starred = null;
	public int $rating = 0;
	public ?int $genreId = null;
	public int $playCount = 0;
	public ?string $lastPlayed = null;
	public int $dirty = 0;

	// not from the music_tracks table but still part of the standard content of this entity:
	public string $filename = '';
	public int $size = 0;
	public int $fileModTime = 0;
	public ?string $albumName = null;
	public ?string $artistName = null;
	public ?string $genreName = null;
	public int $folderId = 0;

	// the rest of the variables are injected separately when needed
	private ?Album $album = null;
	private ?int $numberOnPlaylist = null;
	private ?string $folderPath = null;
	private ?string $lyrics = null;

	public function __construct() {
		$this->addType('number', 'int');
		$this->addType('disk', 'int');
		$this->addType('year', 'int');
		$this->addType('artistId', 'int');
		$this->addType('albumId', 'int');
		$this->addType('length', 'int');
		$this->addType('bitrate', 'int');
		$this->addType('fileId', 'int');
		$this->addType('genreId', 'int');
		$this->addType('playCount', 'int');
		$this->addType('rating', 'int');
		$this->addType('dirty', 'int');
		$this->addType('size', 'int');
		$this->addType('fileModTime', 'int');
		$this->addType('folderId', 'int');
	}

	public function getAlbum() : ?Album {
		return $this->album;
	}

	public function setAlbum(?Album $album) : void {
		$this->album = $album;
	}

	public function getNumberOnPlaylist() : ?int {
		return $this->numberOnPlaylist;
	}

	public function setNumberOnPlaylist(int $number) : void {
		$this->numberOnPlaylist = $number;
	}

	public function setFolderPath(string $path) : void {
		$this->folderPath = $path;
	}

	public function setLyrics(?string $lyrics) : void {
		$this->lyrics = $lyrics;
	}

	public function getPath() : ?string {
		return ($this->folderPath ?? '') . '/' . $this->filename;
	}

	public function getUri(IURLGenerator $urlGenerator) : string {
		return $urlGenerator->linkToRoute(
			'music.shivaApi.track',
			['id' => $this->id]
		);
	}

	public function getArtistWithUri(IURLGenerator $urlGenerator) : array {
		return [
			'id' => $this->artistId,
			'uri' => $urlGenerator->linkToRoute(
				'music.shivaApi.artist',
				['id' => $this->artistId]
			)
		];
	}

	public function getAlbumWithUri(IURLGenerator $urlGenerator) : array {
		return [
			'id' => $this->albumId,
			'uri' => $urlGenerator->linkToRoute(
				'music.shivaApi.album',
				['id' => $this->albumId]
			)
		];
	}

	public function getArtistNameString(IL10N $l10n) : string {
		return $this->getArtistName() ?: Artist::unknownNameString($l10n);
	}

	public function getAlbumNameString(IL10N $l10n) : string {
		return $this->getAlbumName() ?: Album::unknownNameString($l10n);
	}

	public function getGenreNameString(IL10N $l10n) : string {
		return $this->getGenreName() ?: Genre::unknownNameString($l10n);
	}

	public function toCollection() : array {
		return [
			'title' => $this->getTitle(),
			'number' => $this->getNumber(),
			'disk' => $this->getDisk(),
			'artistId' => $this->getArtistId(),
			'length' => $this->getLength(),
			'files' => [$this->getMimetype() => $this->getFileId()],
			'id' => $this->getId(),
		];
	}

	public function toShivaApi(IURLGenerator $urlGenerator) : array {
		return [
			'title' => $this->getTitle(),
			'ordinal' => $this->getAdjustedTrackNumber(),
			'artist' => $this->getArtistWithUri($urlGenerator),
			'album' => $this->getAlbumWithUri($urlGenerator),
			'length' => $this->getLength(),
			'files' => [$this->getMimetype() => $urlGenerator->linkToRoute(
				'music.musicApi.download',
				['fileId' => $this->getFileId()]
			)],
			'bitrate' => $this->getBitrate(),
			'id' => $this->getId(),
			'slug' => $this->slugify('title'),
			'uri' => $this->getUri($urlGenerator)
		];
	}

	public function toAmpacheApi(
			IL10N $l10n,
			callable $createPlayUrl,
			callable $createImageUrl,
			callable $renderAlbumOrArtistRef,
			string $genreKey,
			bool $includeArtists) : array {
		$album = $this->getAlbum();

		$result = [
			'id' => (string)$this->getId(),
			'title' => $this->getTitle() ?: '',
			'name' => $this->getTitle() ?: '',
			'artist' => $renderAlbumOrArtistRef($this->getArtistId() ?: 0, $this->getArtistNameString($l10n)),
			'albumartist' => $renderAlbumOrArtistRef($album->getAlbumArtistId() ?: 0, $album->getAlbumArtistNameString($l10n)),
			'album' => $renderAlbumOrArtistRef($album->getId() ?: 0, $album->getNameString($l10n)),
			'url' => $createPlayUrl($this),
			'time' => $this->getLength(),
			'year' => $this->getYear(),
			'track' => $this->getAdjustedTrackNumber(), // TODO: maybe there should be a user setting to select plain or adjusted number
			'playlisttrack' => $this->getAdjustedTrackNumber(),
			'disk' => $this->getDisk(),
			'filename' => $this->getFilename(),
			'format' => $this->getFileExtension(),
			'stream_format' => $this->getFileExtension(),
			'bitrate' => $this->getBitrate(),
			'stream_bitrate' => $this->getBitrate(),
			'mime' => $this->getMimetype(),
			'stream_mime' => $this->getMimetype(),
			'size' => $this->getSize(),
			'art' => $createImageUrl($this),
			'rating' => $this->getRating(),
			'preciserating' => $this->getRating(),
			'playcount' => $this->getPlayCount(),
			'flag' => !empty($this->getStarred()),
			'language' => null,
			'lyrics' => $this->lyrics,
			'mode' => null, // cbr/vbr
			'rate' => null, // sample rate [Hz]
			'replaygain_album_gain' => null,
			'replaygain_album_peak' => null,
			'replaygain_track_gain' => null,
			'replaygain_track_peak' => null,
			'r128_album_gain' => null,
			'r128_track_gain' => null,
		];

		$result['has_art'] = !empty($result['art']);

		$genreId = $this->getGenreId();
		if ($genreId !== null) {
			$result[$genreKey] = [[
				'id' => (string)$genreId,
				'text' => $this->getGenreNameString($l10n),
				'count' => 1
			]];
		}

		if ($includeArtists) {
			// Add another property `artists`. Apparently, it exists to support multiple artists per song
			// but we don't have such possibility and this is always just a 1-item array.
			$result['artists'] = [$result['artist']];
		}
	
		return $result;
	}

	/**
	 * The same API format is used both on "old" and "new" API methods. The "new" API adds some
	 * new fields for the songs, but providing some extra fields shouldn't be a problem for the
	 * older clients. The $track entity must have the Album reference injected prior to calling this.
	 *
	 * @param string[] $ignoredArticles
	 */
	public function toSubsonicApi(IL10N $l10n, array $ignoredArticles) : array {
		$albumId = $this->getAlbumId();
		$album = $this->getAlbum();
		$hasCoverArt = ($album !== null && !empty($album->getCoverFileId()));

		return [
			'id' => 'track-' . $this->getId(),
			'parent' => 'album-' . $albumId,
			'discNumber' => $this->getDisk(),
			'title' => $this->getTitle(),
			'artist' => $this->getArtistNameString($l10n),
			'isDir' => false,
			'album' => $this->getAlbumNameString($l10n),
			'year' => $this->getYear(),
			'size' => $this->getSize(),
			'contentType' => $this->getMimetype(),
			'suffix' => $this->getFileExtension(),
			'duration' => $this->getLength() ?? 0,
			'bitRate' => empty($this->getBitrate()) ? null : (int)\round($this->getBitrate()/1000), // convert bps to kbps
			'path' => $this->getPath(),
			'isVideo' => false,
			'albumId' => 'album-' . $albumId,
			'artistId' => 'artist-' . $this->getArtistId(),
			'type' => 'music',
			'mediaType' => 'song', // OpenSubsonic
			'created' => Util::formatZuluDateTime($this->getCreated()),
			'track' => $this->getAdjustedTrackNumber(false), // DSub would get confused of playlist numbering, https://github.com/nc-music/oc-music/issues/994
			'starred' => Util::formatZuluDateTime($this->getStarred()),
			'userRating' => $this->getRating() ?: null,
			'averageRating' => $this->getRating() ?: null,
			'genre' => empty($this->getGenreId()) ? null : $this->getGenreNameString($l10n),
			'coverArt' => !$hasCoverArt ? null : 'album-' . $albumId,
			'playCount' => $this->getPlayCount(),
			'played' => Util::formatZuluDateTime($this->getLastPlayed()) ?? '', // OpenSubsonic
			'sortName' => StringUtil::splitPrefixAndBasename($this->getTitle(), $ignoredArticles)['basename'], // OpenSubsonic
		];
	}

	public function getAdjustedTrackNumber(bool $enablePlaylistNumbering=true) : ?int {
		// Unless disabled, the number on playlist overrides the track number if it is set.
		if ($enablePlaylistNumbering && $this->numberOnPlaylist !== null) {
			$trackNumber = $this->numberOnPlaylist;
		} else {
			// On single-disk albums, the track number is given as-is.
			// On multi-disk albums, the disk-number is applied to the track number.
			// In case we have no Album reference, the best we can do is to apply the
			// disk number if it is greater than 1. For disk 1, we don't know if this
			// is a multi-disk album or not.
			$numberOfDisks = ($this->album) ? $this->album->getNumberOfDisks() : null;
			$trackNumber = $this->getNumber();

			if ($this->disk > 1 || $numberOfDisks > 1) {
				$trackNumber = $trackNumber ?: 0;
				$trackNumber += (100 * $this->disk);
			}
		}

		return $trackNumber;
	}

	public function getFileExtension() : string {
		$parts = Util::explode('.', $this->getFilename());
		return empty($parts) ? '' : \end($parts);
	}

	/**
	 * Get an instance which has all the mandatory fields set to valid but empty values
	 */
	public static function emptyInstance() : Track {
		$track = new self();

		$track->id = -1;
		$track->title = '';
		$track->artistId = -1;
		$track->albumId = -1;
		$track->fileId = -1;
		$track->mimetype = '';
		$track->playCount = 0;
		$track->dirty = 0;

		$track->filename = '';
		$track->size = 0;
		$track->fileModTime = 0;
		$track->folderId = -1;

		return $track;
	}
}
