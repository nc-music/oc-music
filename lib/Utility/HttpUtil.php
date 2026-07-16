<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2022 - 2026
 */

namespace OCA\Music\Utility;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * Static utility functions to work with HTTP requests
 */
class HttpUtil {

	private const ALLOWED_SCHEMES = ['http', 'https', 'feed', 'podcast', 'pcast', 'podcasts', 'itms-pcast', 'itms-pcasts', 'itms-podcast', 'itms-podcasts'];

	/**
	 * Use HTTP GET to load the requested URL
	 * @return array{content: string|false, status_code: int, message: string, content_type: ?string}
	 */
	public static function loadFromUrl(string $url, ?int $maxLength=null, ?int $timeout_s=null) : array {
		$context = self::createContext($timeout_s);
		$resolved = self::resolveRedirections($url, $context); // handles also checking for allowed URL schemes and IP ranges

		$status_code = $resolved['status_code'];
		if ($status_code >= 200 && $status_code < 300) {
			// The length parameter of file_get_contents isn't nullable prior to PHP8.0
			if ($maxLength === null) {
				$content = @\file_get_contents($resolved['url'], false, $context);
			} else {
				$content = @\file_get_contents($resolved['url'], false, $context, 0, $maxLength);
			}
		} else {
			$content = false;
		}

		$message = $resolved['status_msg'];
		$content_type = ArrayUtil::getCaseInsensitive($resolved['headers'], 'content-type');

		return \compact('content', 'status_code', 'message', 'content_type');
	}

	/**
	 * @param array<string, mixed> $extraHeaders
	 * @return resource
	 */
	public static function createContext(?int $timeout_s = null, array $extraHeaders = []) {
		$opts = self::contextOptions($extraHeaders);
		if ($timeout_s !== null) {
			$opts['http']['timeout'] = $timeout_s;
		}
		return \stream_context_create($opts);
	}

	/**
	 * Resolve redirections with a custom logic. The platform solution doesn't always work correctly, especially with
	 * unusually long header lines, see https://github.com/nc-music/oc-music/issues/1209.
	 * @param resource $context
	 * @return array{url: string, status_code: int, status_msg: string, headers: array<string, string>}
	 * 					The final URL and the headers from the URL, after any redirections. @see HttpUtil::parseHeaders
	 */
	public static function resolveRedirections(string $url, $context, int $maxRedirects=20) : array {
		do {
			$headers = self::getUrlHeaders($url, $context);
			$status = $headers['status_code'];
			$location = ArrayUtil::getCaseInsensitive($headers['headers'], 'location');
			$redirect = ($status >= 300 && $status < 400 && $location !== null);
			if ($redirect) {
				if ($maxRedirects-- > 0) {
					$url = Util::urlToAbsolute($location, $url);
				} else {
					$redirect = false;
					$headers['status_code'] = Http::STATUS_LOOP_DETECTED;
					$headers['status_msg'] = 'Max number of redirections exceeded';
				}
			}
		} while ($redirect);

		$headers['url'] = $url;
		return $headers;
	}

	/**
	 * @param resource $context
	 * @return array{status_code: int, status_msg: string, headers: array<string, string>}
	 * 					The headers from the URL, after any redirections. @see HttpUtil::parseHeaders
	 */
	private static function getUrlHeaders(string $url, $context) : array {
		$result = null;
		if (self::isUrlAllowed($url)) {
			// the type of the second parameter of get_header has changed in PHP 8.0
			$associative = \version_compare(\phpversion(), '8.0', '<') ? 0 : false;
			$rawHeaders = @\get_headers($url, /** @scrutinizer ignore-type */ $associative, $context);

			if ($rawHeaders !== false) {
				$result = self::parseHeaders($rawHeaders);
			} else {
				$result = ['status_code' => Http::STATUS_SERVICE_UNAVAILABLE, 'status_msg' => 'Error connecting the URL', 'headers' => ['Content-Length' => '0']];
			}
		} else {
			$result = ['status_code' => Http::STATUS_FORBIDDEN, 'status_msg' => 'URL scheme or host not allowed', 'headers' => ['Content-Length' => '0']];
		}
		return $result;
	}

	/**
	 * @param string[] $rawHeaders
	 * @return array{status_code: int, status_msg: string, headers: array<string, string>}
	 * 			The key 'status_code' will contain the status code number of the HTTP request (like 200, 302, 404).
	 * 			The key 'status_msg' will contain the textual status following the code (like 'OK' or 'Not Found').
	 * 			The key 'headers' will contain all the named HTTP headers as a dictionary.
	 */
	private static function parseHeaders(array $rawHeaders) : array {
		$result = ['status_code' => 0, 'status_msg' => 'invalid', 'headers' => []];

		foreach ($rawHeaders as $row) {
			// The response usually starts with a header like "HTTP/1.1 200 OK". However, some shoutcast streams
			// may instead use "ICY 200 OK".
			$ignoreCase = true;
			if (StringUtil::startsWith($row, 'HTTP/', $ignoreCase) || StringUtil::startsWith($row, 'ICY ', $ignoreCase)) {
				// Start of new response. If we have already parsed some headers, then those are from some
				// intermediate redirect response and those should be discarded.
				$parts = \explode(' ', $row, 3);
				if (\count($parts) == 3) {
					list(, $status_code, $status_msg) = $parts;
				} else {
					$status_code = Http::STATUS_INTERNAL_SERVER_ERROR;
					$status_msg = 'Bad response status header';
				}
				$result = ['status_code' => (int)$status_code, 'status_msg' => $status_msg, 'headers' => []];
			} else {
				// All other lines besides the initial status line should have the format "key: value"
				$parts = \explode(':', $row, 2);
				if (\count($parts) == 2) {
					list($key, $value) = $parts;
					$result['headers'][\trim($key)] = \trim($value);
				}
			}
		}

		return $result;
	}

	public static function userAgentHeader() : string {
		return 'User-Agent: OCMusic/' . AppInfo::getVersion();
	}

	/**
	 * @param array<string, mixed> $extraHeaders
	 * @return array{http: array<string, mixed>}
	 */
	private static function contextOptions(array $extraHeaders = []) : array {
		$opts = [
			'http' => [
				'header' => self::userAgentHeader(),	// some servers don't allow requests without a user agent header
				'ignore_errors' => true,				// don't emit warnings for bad/unavailable URL, we handle errors manually
				'max_redirects' => 0					// we use our custom logic to resolve redirections
			]
		];

		foreach ($extraHeaders as $key => $value) {
			$opts['http']['header'] .= "\r\n$key: $value";
		}

		return $opts;
	}

	private static function isUrlAllowed(string $url) : bool {
		$allowed = false;

		$parsedUrl = \parse_url($url);
		$scheme = $parsedUrl['scheme'] ?? '';
		$validScheme = \in_array(\mb_strtolower($scheme), self::ALLOWED_SCHEMES);

		if ($validScheme && !empty($parsedUrl['host'])) {
			// The host may be either a domain name or an IP address
			$ip = \filter_var($parsedUrl['host'], FILTER_VALIDATE_IP);
			if ($ip !== false) {
				// The host is an IP address, check that it is a public IP address
				$allowed = \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
			} else {
				// The host is a domain name, resolve it to IP addresses and check that all of them are public IP addresses.
				// Any of these IP addresses may be used when connecting to the host.
				$ipRecords = \dns_get_record($parsedUrl['host'], DNS_A + DNS_AAAA);
				if (!empty($ipRecords)) {
					$allowed = true;
					foreach ($ipRecords as $record) {
						$ip = $record['ip'] ?? $record['ipv6'] ?? '';
						$ip = \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
						if ($ip === false) {
							$allowed = false;
							break;
						}
					}
				}
			}
		}

		return $allowed;
	}

	public static function setClientCachingDays(Response $httpResponse, int $days) : void {
		$httpResponse->cacheFor($days * 24 * 60 * 60);
		$httpResponse->addHeader('Pragma', 'cache');
	}
}
