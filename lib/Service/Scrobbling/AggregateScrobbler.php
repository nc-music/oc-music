<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2025
 */

namespace OCA\Music\Service\Scrobbling;

use DateTime;

class AggregateScrobbler implements IScrobbler {
	/** @var array<IScrobbler> $scrobblers */
	private array $scrobblers;

	public function __construct(array $scrobblers) {
		$this->scrobblers = $scrobblers;
	}

	public function recordTrackPlayed(int $trackId, string $userId, ?\DateTime $timeOfPlay = null): void {
		foreach ($this->scrobblers as $scrobbler) {
			$scrobbler->recordTrackPlayed($trackId, $userId, $timeOfPlay);
		}
	}
}
