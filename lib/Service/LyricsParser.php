<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */

namespace OCA\Music\Service;

class LyricsParser {

	/**
	 * Take the timestamped lyrics as returned by `LyricsParser::parseSyncedLyrics` and
	 * return the corresponding plain text representation with no LRC tags.
	 * Input value null will give null result.
	 *
	 * @param array|null $parsedSyncedLyrics
	 * @return string|null
	 */
	public static function syncedToUnsynced(?array $parsedSyncedLyrics) {
		return ($parsedSyncedLyrics === null) ? null : \implode("\n", $parsedSyncedLyrics);
	}

	/**
	 * Parse timestamped lyrics from the given string, and return the parsed data.
	 * Return null if the string does not appear to be timestamped lyric in the LRC format.
	 *
	 * @return array|null The keys of the array are timestamps in milliseconds and values are
	 *                    corresponding lines of lyrics.
	 */
	public static function parseSyncedLyrics(?string $data) : ?array {
		$parsedLyrics = [];

		if (!empty($data)) {
			$offset = 0;

			$fp = \fopen("php://temp", 'r+');
			\assert($fp !== false, 'Unexpected error: opening temporary stream failed');

			\fputs($fp, $data);
			\rewind($fp);
			while ($line = \fgets($fp)) {
				$lineParseResult = self::parseTimestampedLrcLine($line, $offset);
				$parsedLyrics += $lineParseResult;
			}
			\fclose($fp);

			// sort the parsed lyric lines according the timestamps (which are keys of the array)
			\ksort($parsedLyrics);
		}

		return \count($parsedLyrics) > 0 ? $parsedLyrics : null;
	}

	/**
	 * Parse a single line of LRC formatted data. The result is array where keys are
	 * timestamps and values are corresponding lyrics. A single line of LRC may span out to
	 * a) 0 actual timestamp lines, if the line is empty, or contains just metadata, or contains no tags
	 * b) 1 actual timestamp line (the "normal" case)
	 * c) several actual timestamp lines, in case the line contains several timestamps,
	 *    meaning that the same line of text is repeated multiple times during the song
	 *
	 * If the line defines a time offset, this is returned in the reference parameter. If the offset
	 * parameter holds a non-zero value on call, the offset is applied on any extracted timestamps.
	 *
	 * @param string $line One line from the LRC data
	 * @param int $offset Input/output value for time offset in milliseconds
	 * @return array
	 */
	private static function parseTimestampedLrcLine(string $line, int &$offset) : array {
		$result = [];
		$line = \trim($line);

		$matches = [];
		if (\preg_match('/(\[.+\])(.*)/', $line, $matches)) {
			// 1st group captures tag(s), 2nd group anything after the tag(s).
			$tags = $matches[1];
			$text = $matches[2];

			// Extract timestamp tags and the offset tag and discard any other metadata tags.
			$timestampMatches = [];
			$offsetMatch = [];
			if (\preg_match('/\[offset:(-?\d+)\]/', $tags, $offsetMatch)) {
				$offset = \intval($offsetMatch[1]);
			} elseif (\preg_match_all('/\[(\d\d:\d\d(\.\d\d)?)\]/', $tags, $timestampMatches)) {
				// some timestamp(s) were found
				$timestamps = $timestampMatches[1];

				// add the line text to the result set on each found timestamp
				foreach ($timestamps as $timestamp) {
					$result[self::timestampToMs($timestamp) - $offset] = $text;
				}
			}
		}

		return $result;
	}

	/**
	 * Convert timestamp in "mm:ss.ff" format to milliseconds
	 */
	private static function timestampToMs(string $timestamp) : int {
		list($minutes, $seconds) = \sscanf($timestamp, "%d:%f");
		return \intval($seconds * 1000 + $minutes * 60 * 1000);
	}
}
