<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2021 - 2026
 */

namespace OCA\Music\BackgroundJob;

use OCA\Music\AppFramework\BackgroundJob\TimedJob;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppInfo\Application;
use OCA\Music\BusinessLayer\PodcastChannelBusinessLayer;
use OCA\Music\Service\PodcastService;
use OCP\IConfig;

class PodcastUpdateCheck extends TimedJob {

	/**
	 * Check podcast updates on the background
	 * @param mixed $arguments
	 * @return void
	 */
	public function run($arguments) {
		$app = \OC::$server->query(Application::class);

		$logger = $app->get(Logger::class);
		$logger->debug('Run ' . static::class);

		$minInterval = (float)$app->get(IConfig::class)->getSystemValue('music.podcast_auto_update_interval', 24); // hours
		// negative interval values can be used to disable the auto-update
		if ($minInterval >= 0) {
			$users = $app->get(PodcastChannelBusinessLayer::class)->findAllUsers();
			$podcastService = $app->get(PodcastService::class);
			$channelsChecked = 0;

			foreach ($users as $userId) {
				$podcastService->updateAllChannels($userId, $minInterval, false, function (array $channelResult) use ($logger, $userId, &$channelsChecked) {
					$id = (isset($channelResult['channel'])) ? $channelResult['channel']->getId() : -1;

					if ($channelResult['updated']) {
						$logger->debug("Channel $id of user $userId was updated");
					} elseif ($channelResult['status'] === PodcastService::STATUS_OK) {
						$logger->debug("Channel $id of user $userId had no changes");
					} else {
						$logger->debug("Channel $id of user $userId update failed");
					}

					$channelsChecked++;
				});
			}

			if ($channelsChecked === 0) {
				$logger->debug('No podcast channels were due to check for updates');
			} else {
				$logger->debug("$channelsChecked podcast channels in total were checked for updates");
			}
		} else {
			$logger->debug('Automatic podcast updating is disabled via config.php');
		}
	}
}
