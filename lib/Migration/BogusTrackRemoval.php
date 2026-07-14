<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Gregory Baudet <gregory.baudet@gmail.com>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Gregory Baudet 2018
 * @copyright Pauli Järvinen 2019 - 2025
 */

namespace OCA\Music\Migration;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class BogusTrackRemoval implements IRepairStep {

	private IDBConnection $db;
	private IConfig $config;

	public function __construct(IDBConnection $connection, IConfig $config) {
		$this->db = $connection;
		$this->config = $config;
	}

	public function getName() {
		return 'Remove any playlist files mistakenly added to music_tracks table';
	}

	/**
	 * @inheritdoc
	 * @return void
	 */
	public function run(IOutput $output) {
		$installedVersion = $this->config->getAppValue('music', 'installed_version');

		// Music version 0.9.3 and older may have scanned playlist files as tracks,
		// depending on the MIME type configuration of the cloud.
		if (\version_compare($installedVersion, '0.9.4', '<')) {
			$n = $this->removePlaylistFiles();
			$output->info("$n files with audio/mpegurl or audio/x-scpls mime type removed from the music library");
			// Clean cache
			$this->db->executeUpdate("DELETE FROM `*PREFIX*music_cache`");
		}
	}

	private function removePlaylistFiles() : int {
		// Find and delete tracks with mime audio/mpegurl and audio/x-scpls.
		// This may leave some stray albums and artists in the DB but that is not a major problem
		// since the background cleanup task should get rid of those, eventually.
		$sql = "DELETE FROM `*PREFIX*music_tracks` WHERE `mimetype` = 'audio/mpegurl' OR `mimetype` = 'audio/x-scpls'";
		return $this->db->executeUpdate($sql);
	}
}
