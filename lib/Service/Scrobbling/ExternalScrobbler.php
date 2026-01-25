<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen <pauli.jarvine@gmail.co>
 * @copyright Matthew Wells 2025
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Scrobbling;

use DateTime;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Track;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

class ExternalScrobbler implements IScrobbler {
	private IConfig $config;
	private Logger $logger;
	private IURLGenerator $urlGenerator;
	private TrackBusinessLayer $trackBusinessLayer;
	private AlbumBusinessLayer $albumBusinessLayer;
	private ICrypto $crypto;
	private string $name;
	private string $identifier;
	private string $endpoint;
	private string $tokenRequestUrl;
	private ?string $appName;

	public function __construct(
		IConfig $config,
		Logger $logger,
		IURLGenerator $urlGenerator,
		TrackBusinessLayer $trackBusinessLayer,
		AlbumBusinessLayer $albumBusinessLayer,
		ICrypto $crypto,
		string $name,
		string $identifier,
		string $endpoint,
		string $tokenRequestUrl,
		?string $appName = 'music'
	) {
		$this->config = $config;
		$this->logger = $logger;
		$this->urlGenerator = $urlGenerator;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->crypto = $crypto;
		$this->name = $name;
		$this->identifier = $identifier;
		$this->endpoint = $endpoint;
		$this->tokenRequestUrl = $tokenRequestUrl;
		$this->appName = $appName;
	}

	/**
	 * @throws \Exception when curl initialization or session key save fails
	 * @throws ScrobbleServiceException when auth.getSession call fails
	 */
	public function generateSession(string $token, string $userId) : void {
		$xml = $this->execRequest($this->generateMethodParams('auth.getSession', ['token' => $token]));

		$status = (string)$xml['status'];
		if ($status !== 'ok') {
			if ($xml instanceof \SimpleXMLElement) {
				$error = (string)$xml->error;
				$code = (int)$xml->code;
			} else {
				$error = 'Empty response';
				$code = 0;
			}
			throw new ScrobbleServiceException($error, $code);
		}
		$sessionValue = (string)$xml->session->key;

		$this->saveApiSession($userId, $sessionValue);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function clearSession(string $userId) : void {
		try {
			$this->config->deleteUserValue($userId, $this->appName, $this->identifier . '.scrobbleSessionKey');
		} catch (\InvalidArgumentException $e) {
			$this->logger->error(
				'Could not delete user config "' . $this->identifier . '.scrobbleSessionKey". ' . $e->getMessage()
			);
			throw $e;
		}
	}

	public function getApiSession(string $userId) : ?string {
		$encryptedKey = $this->config->getUserValue($userId, $this->appName, $this->identifier . '.scrobbleSessionKey');
		if (!$encryptedKey) {
			return null;
		}
		$key = $this->crypto->decrypt($encryptedKey, $userId . $this->config->getSystemValue('secret'));
		return $key;
	}

	public function getName() : string {
		return $this->name;
	}

	public function getIdentifier() : string {
		return $this->identifier;
	}

	public function getApiKey() : ?string {
		return $this->config->getSystemValue('music.' . $this->identifier . '_api_key', null);
	}

	public function getApiSecret() : ?string {
		return $this->config->getSystemValue('music.' . $this->identifier . '_api_secret', null);
	}

	public function recordTrackPlayed(int $trackId, string $userId, ?\DateTime $timeOfPlay = null) : void {
		$timeOfPlay = $timeOfPlay ?? new \DateTime();
		$sessionKey = $this->getApiSession($userId);
		if (!$sessionKey) {
			return;
		}

		$timestamp = $timeOfPlay->getTimestamp();
		$track = $this->trackBusinessLayer->find($trackId, $userId);

		// Last.fm's docs say a track must be >30 seconds in order to scrobble
		// This scrobbler uses the Last.fm Scrobbler 2.0 spec, so we follow that rule
		// https://www.last.fm/api/scrobbling#when-is-a-scrobble-a-scrobble
		if ($track->getLength() <= 30) {
			return;
		}

		$scrobbleData = [
			'sk' => $sessionKey
		];

		$this->albumBusinessLayer->injectAlbumsToTracks([$track], $userId);

		$trackData = $this->generateTrackData($track);

		if (isset($trackData['albumArtist'])) {
			$scrobbleData["albumArtist[0]"] = $trackData['albumArtist'];
		}
		$scrobbleData["artist[0]"] = $trackData['artist'];
		$scrobbleData["track[0]"] = $trackData['track'];
		$scrobbleData["timestamp[0]"] = $timestamp;
		$scrobbleData["album[0]"] = $trackData['album'];
		$scrobbleData["trackNumber[0]"] = $trackData['trackNumber'];

		$xml = $this->execRequest($this->generateMethodParams('track.scrobble', $scrobbleData));

		if ((string)$xml['status'] !== 'ok') {
			$this->logger->warning("Failed to scrobble to {$this->name}");
		}
	}

	public function setNowPlaying(int $trackId, string $userId, ?DateTime $timeOfPlay = null): void
	{
		$timeOfPlay = $timeOfPlay ?? new \DateTime();
		$sessionKey = $this->getApiSession($userId);
		if (!$sessionKey) {
			return;
		}

		$track = $this->trackBusinessLayer->find($trackId, $userId);
		$this->albumBusinessLayer->injectAlbumsToTracks([$track], $userId);
		$scrobbleData = \array_merge([
			'sk' => $sessionKey,
			'timestamp' => $timeOfPlay->getTimestamp()
		], $this->generateTrackData($track));

		$xml = $this->execRequest($this->generateMethodParams('track.updateNowPlaying', $scrobbleData));

		if ((string)$xml['status'] !== 'ok') {
			$this->logger->warning("Failed to set now playing track on {$this->name}");
			$this->logger->error((string)$xml);
		}
	}

	public function getTokenRequestUrl(): ?string {
		$apiKey = $this->getApiKey();
		if (!$apiKey) {
			return null;
		}

		$tokenHandleUrl = $this->urlGenerator->linkToRouteAbsolute('music.scrobbler.handleToken', [
			'serviceIdentifier' => $this->identifier
		]);
		return "{$this->tokenRequestUrl}?api_key={$apiKey}&cb={$tokenHandleUrl}";
	}

	private function saveApiSession(string $userId, string $sessionValue) : void {
		try {
			$encryptedKey = $this->crypto->encrypt(
				$sessionValue,
				$userId . $this->config->getSystemValue('secret')
			);
			$this->config->setUserValue($userId, $this->appName, $this->identifier . '.scrobbleSessionKey', $encryptedKey);
		} catch (\Exception $e) {
			$this->logger->error('Encryption of scrobble session key failed');
			throw $e;
		}
	}

	/**
	 * @param array<string, string|array> $params
	 */
	private function generateSignature(array $params) : string {
		\ksort($params);
		$paramString = '';
		foreach ($params as $key => $value) {
			if (\is_array($value)) {
				foreach ($value as $valIdx => $valVal) {
					$paramString .= "{$key}[{$valIdx}]{$valVal}";
				}
			} else {
				$paramString .= $key . $value;
			}
		}

		$paramString .= $this->getApiSecret();
		return \md5($paramString);
	}

	/**
	 * @return array<string, string>
	 * @param array<string, mixed> $moreParams
	 * @param bool $sign
	 * @return array<string, mixed>
	 */
	private function generateMethodParams(string $method, array $moreParams = [], bool $sign = true) : array {
		$params = \array_merge($moreParams, [
			'method' => $method,
			'api_key' => $this->getApiKey()
		]);

		if ($sign) {
			$params['api_sig'] = $this->generateSignature($params);
		}

		return $params;
	}

	private function execRequest(array $params) : ?\SimpleXMLElement {
		$ch = \curl_init();
		if (!$ch) {
			$this->logger->error('Failed to initialize a curl handle, is the php curl extension installed?');
			throw new \RuntimeException('Unable to initialize a curl handle');
		}
		\curl_setopt($ch, \CURLOPT_URL, $this->endpoint);
		\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 10);
		\curl_setopt($ch, \CURLOPT_POST, true);
		\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, \CURLOPT_POSTFIELDS, \http_build_query($params));
		/** @var string $xmlString */
		$xmlString = \curl_exec($ch) ?: '';
		/** @var \SimpleXMLElement|false $xml */
		$xml = \simplexml_load_string($xmlString);
		return $xml ?: null;
	}

	/**
	 * @return array{
	 *	artist: string|null,
	 *	track: string,
	 *	album: string|null,
	 *	trackNumber: int|null,
	 *	albumArtist?: string		
	 * }		
	 */
	private function generateTrackData(Track $track) : array {
		$trackData = [
			'artist' => $track->getArtistName(),
			'track' => $track->getTitle(),
			'album' => $track->getAlbumName(),
			'trackNumber' => $track->getNumber()
		];

		if ($track->getAlbum()) {
			$albumArtist = $track->getAlbum()->getAlbumArtistName();
			if ($albumArtist !== $track->getArtistName()) {
				$trackData['albumArtist'] = $albumArtist;
			}
		}

		return $trackData;
	}
}
