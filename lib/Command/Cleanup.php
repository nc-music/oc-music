<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Command;

use OCA\Music\Db\Maintenance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Cleanup extends Command {

	private Maintenance $maintenance;

	public function __construct(Maintenance $maintenance) {
		$this->maintenance = $maintenance;
		parent::__construct();
	}

	protected function configure() : void {
		$this
			->setName('music:cleanup')
			->setDescription('clean up orphaned DB entries (this happens also periodically on the background)')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output) : int {
		$output->writeln('Running cleanup task...');
		$removedEntries = $this->maintenance->cleanUp();
		$output->writeln("Removed entries: " . \json_encode($removedEntries));
		return 0;
	}
}
