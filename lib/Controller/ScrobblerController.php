<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen
 * @copyright Matthew Wells 2025
 * @copyright Pauli Järvinen 2025
 */

namespace OCA\Music\Controller;

use OCA\Music\Service\Scrobbling\ExternalScrobbler;
use OCA\Music\Service\Scrobbling\ScrobbleServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;

class ScrobblerController extends Controller {
	private IL10N $l10n;

	private ?string $userId;

	/** @var ExternalScrobbler[] $externalScrobblers */
	private array $externalScrobblers;

	public function __construct(
			string $appName,
			IRequest $request,
			IL10N $l10n,
			?string $userId,
			array $externalScrobblers
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->userId = $userId;
		$this->appName = $appName;
		$this->externalScrobblers = $externalScrobblers;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @NoSameSiteCookieRequired
	 */
	public function handleToken(?string $serviceIdentifier, ?string $token) : TemplateResponse {
		$params = [
			'lang' => $this->l10n->getLanguageCode(),
			'success' => false,
			'headline' => $this->l10n->t('Unexpected error'),
			'identifier' => $serviceIdentifier,
			'getsession_response' => '',
			'instructions' => $this->l10n->t('Please contact your server administrator for assistance.')
		];

		// The class StandaloneTemplateResponse is NC-specific. However, TemplateResponse mostly acts the same.
		if (\class_exists('\OCP\AppFramework\Http\StandaloneTemplateResponse')) {
			$response = new \OCP\AppFramework\Http\StandaloneTemplateResponse($this->appName, 'scrobble-getsession-result', [], 'base');
		} else {
			$response = new TemplateResponse($this->appName, 'scrobble-getsession-result', [], 'base');
		}

		if (!$this->userId) {
			$params['getsession_response'] = $this->l10n->t('Not logged in');
			$params['instructions'] = $this->l10n->t('Please log in before attempting to authorize a scrobbler');
			$response->setParams($params);
			return $response;
		}

		$scrobbler = $this->getExternalScrobbler($serviceIdentifier);

		if (!$scrobbler) {
			$params['headline'] = $this->l10n->t('Unknown Service');
			$params['getsession_response'] = $this->l10n->t('Unknown service %s', [$serviceIdentifier]);
			$response->setParams($params);
			return $response;
		}

		try {
			$scrobbler->generateSession($token ?? '', $this->userId);
			$params['success'] = true;
			$params['headline'] = $this->l10n->t('All Set!');
			$params['instructions'] = $this->l10n->t('Your streams will be scrobbled to %s.', [$scrobbler->getName()]);
			$params['getsession_response'] = '';
		} catch (ScrobbleServiceException $e) {
			$params['headline'] = $this->l10n->t('Authentication failure');
			$params['instructions'] = $this->l10n->t('Please review the error message prior to trying again.');
			$params['getsession_response'] = $e->getMessage();
		} catch (\Exception $t) {
			$params['getsession_response'] = $t->getMessage();
		} catch (\TypeError $t) {
			$params['getsession_response'] = $t->getMessage();
		} finally {
			$response->setParams($params);
			return $response;
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function clearSession(?string $serviceIdentifier): JSONResponse {
		$response = new JSONResponse(['error' => [
			'message' => 'Unknown error'
		]]);

		if (!$this->userId) {
			$response->setData(['error' => [
				'message' => $this->l10n->t('Not logged in')
			]]);
			return $response;
		}

		$scrobbler = $this->getExternalScrobbler($serviceIdentifier);
		if (!$scrobbler) {
			$response->setData(['error' => [
				'message' => $this->l10n->t('Unknown service %s', [$serviceIdentifier])
			]]);
			return $response;
		}

		try {
			$scrobbler->clearSession($this->userId);
			$response->setData(['success' => true]);
		} catch (\InvalidArgumentException $e) {
			$response->setData(['error' => [
				'message' => $this->l10n->t('Check the error log for details.')
			]]);
		} finally {
			return $response;
		}
	}

	private function getExternalScrobbler(?string $serviceIdentifier) : ?ExternalScrobbler {
		foreach ($this->externalScrobblers as $scrobbler) {
			if ($scrobbler->getIdentifier() === $serviceIdentifier) {
				return $scrobbler;
			}
		}
		return null;
	}
}
