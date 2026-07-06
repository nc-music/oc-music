<?php

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2025
 */

/**
 * Bootstrapping for ownCloud. The release.sh script will remove this file from
 * the Nextcloud releases. See https://github.com/nc-music/oc-music/issues/1043.
 */

namespace OCA\Music\AppInfo;

use OCP\IL10N;
use OCP\IURLGenerator;

$app = \OC::$server->query(Application::class);
$app->init();

$c = $app->getContainer();
$server = $c->getServer();
$appName = $c->query('appName');

/**
 * add navigation
 */
$server->getNavigationManager()->add(function () use ($c, $appName) {
	$l10n = $c->query(IL10N::class);
	$urlGenerator = $c->query(IURLGenerator::class);
	return [
		'id' => $appName,
		'order' => 10,
		'name' => $l10n->t('Music'),
		'href' => $urlGenerator->linkToRoute('music.page.index'),
		'icon' => $urlGenerator->imagePath($appName, 'music.svg')
	];
});

/**
 * register search provider
 */
$server->getSearch()->registerProvider(
	\OCA\Music\Search\Provider::class,
	['app' => $appName, 'apps' => ['files']]
);

/**
 * register the embedded player for Files and Files_Sharing
 */
$loadEmbeddedMusicPlayer = function () use ($app) {
	$app->loadEmbeddedMusicPlayer();
};
$dispatcher = $server->getEventDispatcher();
$dispatcher->addListener('OCA\Files::loadAdditionalScripts', $loadEmbeddedMusicPlayer);
$dispatcher->addListener('OCA\Files_Sharing::loadAdditionalScripts', $loadEmbeddedMusicPlayer);
