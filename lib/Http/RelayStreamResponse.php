<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2024, 2025
 */

namespace OCA\Music\Http;

use OCA\Music\Utility\ArrayUtil;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

use OCA\Music\Utility\HttpUtil;

/**
 * Response which relays a radio stream or similar from an original source URL
 */
class RelayStreamResponse extends Response implements ICallbackResponse {
	private string $url;
	private ?int $contentLength;
	/** @var resource $context */
	private $context;

	public function __construct(string $url) {
		$reqHeaders = [];
		if (isset($_SERVER['HTTP_ACCEPT'])) {
			$reqHeaders['Accept'] = $_SERVER['HTTP_ACCEPT'];
		}
		if (isset($_SERVER['HTTP_RANGE'])) {
			$reqHeaders['Range'] = $_SERVER['HTTP_RANGE'];
		}

		$this->context = HttpUtil::createContext(null, $reqHeaders);
		$resolved = HttpUtil::resolveRedirections($url, $this->context);

		$this->url = $resolved['url'];
		$this->setStatus($resolved['status_code']);

		/**
		 * Copy response headers from the original response to our response. However, RFC 2616 defines some headers as "hop-by-hop"
		 * and these should never be forwarded by proxies so strip such headers. At least the header `Transfer-Encoding: chunked`
		 * seems to break the response totally (within Nextcloud or Apache?) if forwarded, see https://github.com/nc-music/oc-music/issues/1268.
		 *
		 * HTTP headers are case-insensitive; lower case all the headers for easier handling.
		 */
		$resHeaders = \array_change_key_case($resolved['headers'], CASE_LOWER);
		unset(
			$resHeaders['connection'],
			$resHeaders['keep-alive'],
			$resHeaders['proxy-authentication'],
			$resHeaders['proxy-authorization'],
			$resHeaders['te'],
			$resHeaders['trailers'],
			$resHeaders['transfer-encoding'],
			$resHeaders['upgrade']
		);

		$this->setHeaders($resHeaders);

		$this->contentLength = isset($resHeaders['content-length']) ? (int)$resHeaders['content-length'] : null;
	}

	/**
	 * @return void
	 */
	public function callback(IOutput $output) {
		// The content length is absent for stream-like sources. 0-length indicates that
		// there is no body to transfer.
		if ($this->contentLength === null || $this->contentLength > 0) {
			$inStream = \fopen($this->url, 'rb', false, $this->context);
			if ($inStream !== false) {
				$outStream = \fopen('php://output', 'wb');

				if ($outStream !== false) {
					\stream_copy_to_stream($inStream, $outStream);
					\fclose($outStream);
				}
				\fclose($inStream);
			}
		}
	}
}
