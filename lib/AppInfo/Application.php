<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2017 - 2025
 */

namespace OCA\Music\AppInfo;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Hooks\FileHooks;
use OCA\Music\Hooks\ShareHooks;
use OCA\Music\Hooks\UserHooks;

use OCA\Music\Middleware\AmpacheMiddleware;
use OCA\Music\Middleware\SubsonicMiddleware;

use OCA\Music\Service\AggregateScrobbler;
use OCA\Music\Service\ExternalScrobbler;
use OCA\Music\Service\IScrobbler;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\Files\IMimeTypeLoader;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\IContentSecurityPolicyManager;
use OCP\Security\ICrypto;

// The IBootstrap interface is not available on ownCloud. Create a thin base class to hide this difference
// from the actual Application class.
function useOwncloudBootstrapping() : bool {
	return (\OCA\Music\Utility\AppInfo::getVendor() == 'owncloud');
}

if (useOwncloudBootstrapping()) {
	class ApplicationBase extends App {}
} else {
	abstract class ApplicationBase extends App implements \OCP\AppFramework\Bootstrap\IBootstrap {}
}

class Application extends ApplicationBase {
	public function __construct(array $urlParams=[]) {
		parent::__construct('music', $urlParams);

		\mb_internal_encoding('UTF-8');

		// On ownCloud, the registrations must happen already within the constructor
		if (useOwncloudBootstrapping()) {
			$container = $this->getContainer();
			// this is not registered by the ownCloud core
			$container->registerService(IMimeTypeLoader::class, function (IAppContainer $c) {
				return $c->getServer()->getMimeTypeLoader();
			});

			// Unlike Nextcloud, ownCloud is not able to autoload the classes directly within registerMiddleWare.
			// We have to fetch each middleware once so that the instances are already cached when registerMiddleWare is called.
			$container->query(AmpacheMiddleware::class);
			$container->query(SubsonicMiddleware::class);

			$this->registerMiddleWares($container);
			$this->registerScrobblers($container);
		}
	}

	/**
	 * @param mixed $context On Nextcloud, this is \OCP\AppFramework\Bootstrap\IRegistrationContext.
	 *                       On ownCloud, this is \OCP\AppFramework\IAppContainer.
	 */
	private function registerMiddleWares($context) : void {
		$context->registerMiddleWare(AmpacheMiddleware::class);
		$context->registerMiddleWare(SubsonicMiddleware::class);
	}

	/**
	 * This gets called on Nextcloud but not on ownCloud
	 * @param \OCP\AppFramework\Bootstrap\IRegistrationContext $context
	 */
	public function register($context) : void {
		$this->registerMiddleWares($context);
		$this->registerScrobblers($context);
		$context->registerDashboardWidget(\OCA\Music\Dashboard\MusicWidget::class);
	}

	/**
	 * This gets called on Nextcloud but not on ownCloud
	 * @param \OCP\AppFramework\Bootstrap\IBootContext $context
	 */
	public function boot($context) : void {
		$this->init();
		$this->registerEmbeddedPlayer();
	}

	public function init() : void {
		$this->registerHooks();

		// Adjust the CSP if loading the Music app proper or the NC dashboard
		$url = $this->getRequestUrl();
		if (\preg_match('%/apps/music/?$%', $url) || \preg_match('%/apps/dashboard/?$%', $url)) {
			$this->adjustCsp();
		}
	}

	/**
	 * Load embedded music player for Files and Sharing apps
	 */
	public function loadEmbeddedMusicPlayer() : void {
		\OCA\Music\Utility\HtmlUtil::addWebpackScript('files_music_player');
		\OCA\Music\Utility\HtmlUtil::addWebpackStyle('files_music_player');
		$this->adjustCsp();
	}

	/**
	 * Wrapper to get a service from the container, hiding the differences between the cloud versions.
	 * @param string $id A fully-qualified class name of an autoloadable class or other registered service ID
	 * @return mixed
	 */
	public function get(string $id) {
		$container = $this->getContainer();
		if (\method_exists($container, 'get')) { // @phpstan-ignore function.alreadyNarrowedType
			return $container->get($id); // IAppContainer::get exists on NC20+
		} else {
			return $container->query($id); // On ownCloud, we use IAppContainer::query which is deprecated on NC20+
		}
	}

	private function getRequestUrl() : string {
		$request = $this->get(IRequest::class);
		$url = $request->server['REQUEST_URI'] ?? '';
		$url = \explode('?', $url)[0]; // get rid of any query args
		$url = \explode('#', $url)[0]; // get rid of any hash part
		return $url;
	}

	private function registerHooks() : void {
		$this->get(FileHooks::class)->register();
		$this->get(ShareHooks::class)->register();
		$this->get(UserHooks::class)->register();
	}

	private function registerEmbeddedPlayer() : void {
		$dispatcher = $this->get(\OCP\EventDispatcher\IEventDispatcher::class);

		// Files app
		$dispatcher->addListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, function () {
			$this->loadEmbeddedMusicPlayer();
		});

		// Files_Sharing app
		$dispatcher->addListener(\OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent::class, function (\OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent $event) {
			// don't load the embedded player on the authentication page of password-protected share, and only load it for shared folders (not individual files)
			if ($event->getScope() != \OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent::SCOPE_PUBLIC_SHARE_AUTH
					&& $event->getShare()->getNodeType() == 'folder') {
				$this->loadEmbeddedMusicPlayer();
			}
		});
	}

	/**
	 * Set content security policy to allow streaming media from the configured external sources
	 */
	private function adjustCsp() : void {
		/** @var IConfig $config */
		$config = $this->get(IConfig::class);
		$radioSources = $config->getSystemValue('music.allowed_stream_src', []);

		if (\is_string($radioSources)) {
			$radioSources = [$radioSources];
		}

		$policy = new \OCP\AppFramework\Http\ContentSecurityPolicy();

		foreach ($radioSources as $source) {
			$policy->addAllowedMediaDomain($source);
		}

		// The media sources 'data:' and 'blob:' are needed for HLS streaming
		if (self::hlsEnabled($config, $this->get('userId'))) {
			$policy->addAllowedMediaDomain('data:');
			$policy->addAllowedMediaDomain('blob:');
		}

		$this->get(IContentSecurityPolicyManager::class)->addDefaultPolicy($policy);
	}

	private static function hlsEnabled(IConfig $config, ?string $userId) : bool {
		$enabled = $config->getSystemValue('music.enable_radio_hls', true);
		if (empty($userId)) {
			$enabled = (bool)$config->getSystemValue('music.enable_radio_hls_on_share', $enabled);
		}
		return $enabled;
	}

	/**
	 * @param mixed $context On Nextcloud, this is \OCP\AppFramework\Bootstrap\IRegistrationContext.
	 *                       On ownCloud, this is \OCP\AppFramework\IAppContainer.
	 */
	private function registerScrobblers($context) : void {
		$context->registerService('externalScrobblers', function () {
			return [
				new ExternalScrobbler(
					$this->get(IConfig::class),
					$this->get(Logger::class),
					$this->get(IURLGenerator::class),
					$this->get(TrackBusinessLayer::class),
					$this->get(AlbumBusinessLayer::class),
					$this->get(ICrypto::class),
					'Last.fm',
					'lastfm',
					'http://ws.audioscrobbler.com/2.0/',
					'http://www.last.fm/api/auth/',
					$this->get('appName')
				)
			];
		});

		$context->registerService(IScrobbler::class, function () {
			$scrobblers = $this->get('externalScrobblers');
			$scrobblers[] = $this->get(TrackBusinessLayer::class);
			return new AggregateScrobbler($scrobblers);
		});
	}
}
