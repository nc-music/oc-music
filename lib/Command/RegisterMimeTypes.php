<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2022 - 2025
 */

namespace OCA\Music\Command;

use OCP\Files\IMimeTypeLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RegisterMimeTypes extends Command {

	private IMimeTypeLoader $mimeTypeLoader;

	private array $mimeMappings = [
		'aac'	=> ['audio/aac'],
		'aif'	=> ['audio/aiff'],
		'aifc'	=> ['audio/aiff'],
		'aiff'	=> ['audio/aiff'],
		'au'	=> ['audio/basic'],
		'caf'	=> ['audio/x-caf'],
		'wpl'	=> ['application/vnd.ms-wpl'],
	];

	public function __construct(IMimeTypeLoader $mimeTypeLoader) {
		$this->mimeTypeLoader = $mimeTypeLoader;
		parent::__construct();
	}

	/**
	 * @return void
	 */
	protected function configure() : void {
		$this
			->setName('music:register-mime-types')
			->setDescription('map following file extensions to proper MIME types: ' . \json_encode(\array_keys($this->mimeMappings)));
		;
	}

	/**
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) : int {
		try {
			$output->writeln('Registering MIME types for existing files...');
			$this->registerForExistingFiles($output);
			$output->writeln('Registering MIME types for new files...');
			$this->registerForNewFiles($output);
			$output->writeln('Done');
		} catch (\Exception $e) {
			$output->writeln($e->getMessage());
		}
		return 0;
	}

	private function registerForExistingFiles(OutputInterface $output) : void {
		// The needed function is not part of the public API but we know it should exist
		if (\method_exists($this->mimeTypeLoader, 'updateFilecache')) {
			foreach ($this->mimeMappings as $ext => $mimetypes) {
				foreach ($mimetypes as $mimetype) {
					$mimeId = $this->mimeTypeLoader->getId($mimetype);
					$updatedCount = $this->mimeTypeLoader->/** @scrutinizer ignore-call */updateFilecache($ext, $mimeId);
					$output->writeln("  Updated MIME type $mimetype for $updatedCount files with the extension .$ext");
				}
			}
		} else {
			$output->writeln("  Could not update the filecache");
		}
	}

	private function registerForNewFiles(OutputInterface $output) : void {
		$mappingFile = \OC::$configDir . 'mimetypemapping.json';
		$mappings = $this->mimeMappings;
		$existingMappings = [];

		if (\file_exists($mappingFile)) {
			$existingMappings = \json_decode(\file_get_contents($mappingFile), true);
			if ($existingMappings === null) {
				throw new \Exception("  Could not read or parse the file <error>$mappingFile</error>. Fix or delete the file and then rerun the command.");
			}

			$mappings = \array_merge($existingMappings, $mappings);
		}

		$newMappingCount = \count($mappings) - \count($existingMappings);
		if ($newMappingCount > 0) {
			$result = \file_put_contents($mappingFile, \json_encode($mappings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
			if ($result === false) {
				throw new \Exception("  Failed to write to the file <error>$mappingFile</error>. Check file permissions and then rerun the command.");
			}
		}
		$output->writeln("  MIME mappings added for $newMappingCount new file extensions");
	}
}
