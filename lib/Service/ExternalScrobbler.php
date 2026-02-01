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

namespace OCA\Music\Service;

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

		$scrobbleData = [
			'sk' => $sessionKey
		];

		/** @var array<Track> $tracks */
		$tracks = $this->trackBusinessLayer->findById([$trackId], $userId);
		$this->albumBusinessLayer->injectAlbumsToTracks($tracks, $userId);
		foreach ($tracks as $i => $track) {
			if ($track->getAlbum()) {
				$albumArtist = $track->getAlbum()->getAlbumArtistName();
				if ($albumArtist !== $track->getArtistName()) {
					$scrobbleData["albumArtist[{$i}]"] = $albumArtist;
				}
			}
			$scrobbleData["artist[{$i}]"] = $track->getArtistName();
			$scrobbleData["track[{$i}]"] = $track->getTitle();
			$scrobbleData["timestamp[{$i}]"] = $timeOfPlay->getTimestamp();
			$scrobbleData["album[{$i}]"] = $track->getAlbumName();
			$scrobbleData["trackNumber[{$i}]"] = $track->getNumber();
		}
		$xml = $this->execRequest($this->generateMethodParams('track.scrobble', $scrobbleData));

		if ((string)$xml['status'] !== 'ok') {
			$this->logger->warning('Failed to scrobble to ' . $this->name);
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
}
