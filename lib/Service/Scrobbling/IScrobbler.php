<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Matthew Wells 2025
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Scrobbling;

use OCA\Music\Db\Track;

interface IScrobbler {
	public function recordTrackPlayed(Track $track, ?\DateTime $timeOfPlay = null) : void;
	public function setNowPlaying(Track $track, ?\DateTime $timeOfPlay = null) : void;
}
