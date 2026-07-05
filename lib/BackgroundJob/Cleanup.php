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
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\BackgroundJob;

use OCA\Music\AppFramework\BackgroundJob\TimedJob;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppInfo\Application;
use OCA\Music\Db\AmpacheSessionMapper;
use OCA\Music\Db\Maintenance;
use OCA\Music\Service\Scanner;

class Cleanup extends TimedJob {

	/**
	 * Run background cleanup task
	 * @param mixed $arguments
	 * @return void
	 */
	public function run($arguments) {
		$app = \OC::$server->query(Application::class);

		$logger = $app->get(Logger::class);
		$logger->debug('Run ' . static::class);

		// remove orphaned entities
		$app->get(Maintenance::class)->cleanUp();

		// remove expired sessions
		$app->get(AmpacheSessionMapper::class)->cleanUp();

		// find covers - TODO performance stuff - maybe just call this once in an hour
		$app->get(Scanner::class)->findAlbumCovers();
	}
}
