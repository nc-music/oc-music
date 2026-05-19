<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Moahmed-Ismail MEJRI <imejri@hotmail.com>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Moahmed-Ismail MEJRI 2022
 * @copyright Pauli Järvinen 2022 - 2025
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\HttpUtil;
use OCA\Music\Utility\StringUtil;
use OCA\Music\Utility\Util;
use OCP\IURLGenerator;

/**
 * MetaData radio utility functions
 */
class RadioService {

	private IURLGenerator $urlGenerator;
	private StreamTokenService $tokenService;
	private Logger $logger;

	public function __construct(IURLGenerator $urlGenerator, StreamTokenService $tokenService, Logger $logger) {
		$this->urlGenerator = $urlGenerator;
		$this->tokenService = $tokenService;
		$this->logger = $logger;
	}

	/**
	 * Loop through the array and try to find the given key. On match, return the
	 * text in the array cell following the key. Whitespace is trimmed from the result.
	 */
	private static function findStrFollowing(array $data, string $key) : ?string {
		foreach ($data as $value) {
			$find = \strstr($value, $key);
			if ($find !== false) {
				return \trim(\substr($find, \strlen($key)));
			}
		}
		return null;
	}

	private static function parseStreamUrl(string $url) : array {
		$ret = [];
		$parse_url = \parse_url($url);

		$ret['port'] = 80;
		if (isset($parse_url['port'])) {
			$ret['port'] = $parse_url['port'];
		} elseif ($parse_url['scheme'] == "https") {
			$ret['port'] = 443;
		}

		$ret['scheme'] = $parse_url['scheme'];
		$ret['hostname'] = $parse_url['host'];
		$ret['pathname'] = $parse_url['path'] ?? '/';

		if (isset($parse_url['query'])) {
			$ret['pathname'] .= "?" . $parse_url['query'];
		}

		if ($parse_url['scheme'] == "https") {
			$ret['sockAddress'] = "ssl://" . $ret['hostname'];
		} else {
			$ret['sockAddress'] = $ret['hostname'];
		}

		return $ret;
	}

	/**
	 * @param resource $fp File handle
	 */
	private static function parseTitleFromStreamMetadata($fp) : ?string {
		$meta_length = \ord(\fread($fp, 1)) * 16;
		if ($meta_length) {
			$metadatas = \explode(';', \fread($fp, $meta_length));
			$title = self::findStrFollowing($metadatas, "StreamTitle=");
			if ($title) {
				return StringUtil::truncate(\trim($title, "'"), 256);
			}
		}
		return null;
	}

	private function readMetadata(string $metaUrl, callable $parseResult) : ?array {
		$maxLength = 32 * 1024;
		$timeout_s = 8;
		list('content' => $content, 'status_code' => $status_code, 'message' => $message)
			= HttpUtil::loadFromUrl($metaUrl, $maxLength, $timeout_s);

		if ($status_code == 200) {
			return $parseResult($content);
		} else {
			$this->logger->debug("Failed to read $metaUrl: $status_code $message");
			return null;
		}
	}

	public function readShoutcastV1Metadata(string $streamUrl) : ?array {
		// cut the URL from the last '/' and append 7.html
		$lastSlash = \strrpos($streamUrl, '/');
		$metaUrl = \substr($streamUrl, 0, $lastSlash) . '/7.html';

		return $this->readMetadata($metaUrl, function ($content) {
			// parsing logic borrowed from https://github.com/IntellexApps/shoutcast/blob/master/src/Info.php

			// get rid of the <html><body>...</html></body> decorations and extra spacing:
			$content = \preg_replace("[\n\t]", '', \trim(\strip_tags($content)));

			// parse fields, allowing only the expected format
			$match = [];
			if (!\preg_match('~^(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,(.*?)$~', $content, $match)) {
				return null;
			} else {
				return [
					'type' => 'shoutcast-v1',
					'title' => $match[7],
					'bitrate' => $match[6]
				];
			}
		});
	}

	public function readShoutcastV2Metadata(string $streamUrl) : ?array {
		// cut the URL from the last '/' and append 'stats'
		$lastSlash = \strrpos($streamUrl, '/');
		$metaUrl = \substr($streamUrl, 0, $lastSlash) . '/stats';

		return $this->readMetadata($metaUrl, function ($content) {
			$rootNode = \simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NOCDATA);
			if ($rootNode === false || $rootNode->getName() != 'SHOUTCASTSERVER') {
				return null;
			} else {
				return [
					'type' => 'shoutcast-v2',
					'title' => (string)$rootNode->SONGTITLE,
					'station' => (string)$rootNode->SERVERTITLE,
					'homepage' => (string)$rootNode->SERVERURL,
					'genre' => (string)$rootNode->SERVERGENRE,
					'bitrate' => (string)$rootNode->BITRATE
				];
			}
		});
	}

	public function readIcecastMetadata(string $streamUrl) : ?array {
		// cut the URL from the last '/' and append 'status-json.xsl'
		$lastSlash = \strrpos($streamUrl, '/');
		$metaUrl = \substr($streamUrl, 0, $lastSlash) . '/status-json.xsl';

		return $this->readMetadata($metaUrl, function ($content) use ($streamUrl) {
			\mb_substitute_character(0xFFFD); // Use the Unicode REPLACEMENT CHARACTER (U+FFFD)
			$content = \mb_convert_encoding($content, 'UTF-8', 'UTF-8');
			$parsed = \json_decode(/** @scrutinizer ignore-type */ $content, true);
			$source = $parsed['icestats']['source'] ?? null;

			if (!\is_array($source)) {
				return null;
			} else {
				// There may be one or multiple sources and the structure is slightly different in these two cases.
				// In case there are multiple, try to found the source with a matching stream URL.
				if (\is_int(\key($source))) {
					// multiple sources
					foreach ($source as $sourceItem) {
						if ($sourceItem['listenurl'] == $streamUrl) {
							$source = $sourceItem;
							break;
						}
					}
				}

				return [
					'type' => 'icecast',
					'title' => $source['title'] ?? $source['yp_currently_playing'] ?? null,
					'station' => $source['server_name'] ?? null,
					'description' => $source['server_description'] ?? null,
					'homepage' => $source['server_url'] ?? null,
					'genre' => $source['genre'] ?? null,
					'bitrate' => $source['bitrate'] ?? null
				];
			}
		});
	}

	public function readIcyMetadata(string $streamUrl, int $maxattempts, int $maxredirect) : ?array {
		$timeout = 10;
		$result = null;
		$pUrl = self::parseStreamUrl($streamUrl);
		if ($pUrl['sockAddress'] && $pUrl['port']) {
			$fp = \fsockopen($pUrl['sockAddress'], $pUrl['port'], $errno, $errstr, $timeout);
			if ($fp !== false) {
				$out = "GET " . $pUrl['pathname'] . " HTTP/1.1\r\n";
				$out .= "Host: ". $pUrl['hostname'] . "\r\n";
				$out .= "Accept: */*\r\n";
				$out .= HttpUtil::userAgentHeader() . "\r\n";
				$out .= "Icy-MetaData: 1\r\n";
				$out .= "Connection: Close\r\n\r\n";
				\fwrite($fp, $out);
				\stream_set_timeout($fp, $timeout);

				$header = \fread($fp, 1024);
				$headers = \explode("\n", $header);

				if (\strpos($headers[0], "200 OK") !== false) {
					$interval = self::findStrFollowing($headers, "icy-metaint:") ?? '0';
					$interval = (int)$interval;

					if ($interval > 0 && $interval <= 64*1024) {
						$result = [
							'type' => 'icy',
							'title' => null, // fetched below
							'station' => self::findStrFollowing($headers, 'icy-name:'),
							'description' => self::findStrFollowing($headers, 'icy-description:'),
							'homepage' => self::findStrFollowing($headers, 'icy-url:'),
							'genre' => self::findStrFollowing($headers, 'icy-genre:'),
							'bitrate' => self::findStrFollowing($headers, 'icy-br:')
						];

						$attempts = 0;
						while ($attempts < $maxattempts && empty($result['title'])) {
							$bytesToSkip = $interval;
							if ($attempts === 0) {
								// The first chunk containing the header may also already contain the beginning of the body,
								// but this depends on the case. Subtract the body bytes which we already got.
								$headerEndPos = \strpos($header, "\r\n\r\n") + 4;
								$bytesToSkip -= \strlen($header) - $headerEndPos;
							}

							\fseek($fp, $bytesToSkip, SEEK_CUR);

							$result['title'] = self::parseTitleFromStreamMetadata($fp);

							$attempts++;
						}
					}
					\fclose($fp);
				} else {
					\fclose($fp);
					if ($maxredirect > 0 && \strpos($headers[0], "302 Found") !== false) {
						$location = self::findStrFollowing($headers, "Location:");
						if ($location) {
							$result = $this->readIcyMetadata($location, $maxattempts, $maxredirect-1);
						}
					}
				}
			}
		}

		return $result;
	}

	private static function convertUrlOnPlaylistToAbsolute(string $containedUrl, string $playlistUrl) : string {
		if (!StringUtil::startsWith($containedUrl, 'http://', true) && !StringUtil::startsWith($containedUrl, 'https://', true)) {
			$urlParts = \parse_url($playlistUrl);

			if ($containedUrl[0] == '/') {
				// the contained URL is absolute to the server root => keep only the scheme and host from the playlist URL
				$urlParts['path'] = $containedUrl;
			} else {
				// the contained URL is relative to the playlist URL => keep the path up to the last '/' and append the contained URL
				$path = $urlParts['path'] ?? '/';
				$lastSlash = \strrpos($path, '/');
				$urlParts['path'] = \substr($path, 0, $lastSlash + 1) . $containedUrl;
			}
			unset($urlParts['query'], $urlParts['fragment']);

			$containedUrl = Util::buildUrl($urlParts);
		}
		return $containedUrl;
	}

	/**
	 * Sometimes the URL given as stream URL points to a playlist which in turn contains the actual
	 * URL to be streamed. This function resolves such indirections.
	 * @return array{url: ?string, hls: bool}
	 */
	public function resolveStreamUrl(string $url) : array {
		$isHls = false;

		// resolve redirections and get the headers to see the content type
		$context = HttpUtil::createContext();
		$resolved = HttpUtil::resolveRedirections($url, $context);

		if ($resolved['status_code'] >= 200 && $resolved['status_code'] < 300) {
			$url = $resolved['url'];
			$contentType = ArrayUtil::getCaseInsensitive($resolved['headers'], 'content-type');
			$contentType = \strtolower($contentType ?? '');
			$mime = \strtok($contentType, ';'); // the content-type may have a ;-delimited charset after the mime type

			$isM3u = \in_array($mime, ['audio/mpegurl', 'audio/x-mpegurl', 'application/mpegurl', 'application/x-mpegurl', 'application/vnd.apple.mpegurl']);
			$isPls = ($mime == 'audio/x-scpls');

			if ($isPls || $isM3u) {
				$maxLength = 8 * 1024;
				$content = @\file_get_contents($url, false, $context, 0, $maxLength);

				if ($content === false) {
					$this->logger->debug("No content from playlist URL $url");
					$url = null;
				} elseif ($isPls) {
					$entries = PlaylistFileService::parsePlsContent($content);
				} elseif (\strpos($content, '#EXT-X-MEDIA-SEQUENCE') !== false) {
					$isHls = true;
				} else {
					$entries = PlaylistFileService::parseM3uContent($content);
				}

				if (!empty($entries)) {
					// in case the playlist contains multiple entries, the first ones are probably advertisements and the actual stream is the last one
					$entryUrl = \end($entries)['path'];
					// the path in the playlist may be relative => convert to absolute
					$url = self::convertUrlOnPlaylistToAbsolute($entryUrl, $url);

					// make a recursive call, sometimes playlist may contain another playlist URL
					['url' => $url, 'hls' => $isHls] = $this->resolveStreamUrl($url);
				}
			}
		} else {
			$this->logger->debug("Could not read stream URL $url: {$resolved['status_code']} {$resolved['status_msg']}");
			$url = null;
		}

		return ['url' => $url, 'hls' => $isHls];
	}

	public function getHlsManifest(string $url) : array {
		$maxLength = 8 * 1024;
		$result = HttpUtil::loadFromUrl($url, $maxLength);

		if ($result['status_code'] == 200) {
			// read the manifest line-by-line, and create a modified copy where each fragment URL is relayed through this server
			$fp = \fopen("php://temp", 'r+');
			\assert($fp !== false, 'Unexpected error: opening temporary stream failed');

			\fputs($fp, /** @scrutinizer ignore-type */ $result['content']);
			\rewind($fp);

			$content = '';
			while ($line = \fgets($fp)) {
				$line = \trim($line);
				if (!empty($line) && !StringUtil::startsWith($line, '#')) {
					$segUrl = self::convertUrlOnPlaylistToAbsolute($line, $url);
					$segToken = $this->tokenService->tokenForUrl($segUrl);
					$line = $this->urlGenerator->linkToRoute(
						'music.radioApi.hlsSegment',
						['url' => \rawurlencode($segUrl), 'token' => \rawurlencode($segToken)]
					);
				} elseif (\preg_match('/^#EXT-X-.+:URI="([^"]*)"/', $line, $matches) === 1) {
					// also rewrite HLS headers containing URLs, such as the decryption key URL in #EXT-X-KEY
					$segUrl = self::convertUrlOnPlaylistToAbsolute($matches[1], $url);
					$segToken = $this->tokenService->tokenForUrl($segUrl);
					$line = \str_replace($matches[1], $this->urlGenerator->linkToRoute(
						'music.radioApi.hlsSegment',
						['url' => \rawurlencode($segUrl), 'token' => \rawurlencode($segToken)]
					), $line);
				}
				$content .= $line . "\n";
			}
			$result['content'] = $content;

			\fclose($fp);
		} else {
			$this->logger->warning("Failed to read manifest from $url: {$result['status_code']} {$result['message']}");
		}

		return $result;
	}

}
