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
 * @copyright Pauli Järvinen 2017 - 2021
 */

namespace OCA\Music\Db;

use Doctrine\DBAL\Connection;

class MaintenanceTest extends \PHPUnit\Framework\TestCase {

	/** @var Connection */
	private $db;

	private $logger;

	protected function setUp() : void {
		/** @var Connection db */
		$this->db = \OC::$server->getDatabaseConnection();
		$this->logger = $this->getMockBuilder('\OCA\Music\AppFramework\Core\Logger')
			->disableOriginalConstructor()
			->getMock();
	}

	protected function loadData($filename) {
		$filename = \join(DIRECTORY_SEPARATOR, [\dirname(__DIR__), 'data', $filename]);
		if (!\file_exists($filename)) {
			throw new \Exception("Can't find file $filename to load data.");
		}

		$data = \json_decode(\file_get_contents($filename));

		foreach ($data as $table => $dataSets) {
			foreach ($dataSets as $dataSet) {
				$qb = $this->db->getQueryBuilder();
				$q = $qb->insert($table);

				foreach ($dataSet as $column => $value) {
					$q->setValue($column, $qb->createNamedParameter($value));
				}

				$q->execute();
			}
		}
	}

	protected function checkForEmptyTables($user) {
		$tables = [
			'music_artists',
			'music_albums',
			'music_tracks',
		];

		foreach ($tables as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
				->from($table)
				->where('user_id = :user_id')
				->setParameter('user_id', $user);
			$stmt = $qb->execute();
			$row = $stmt->fetch();
			$stmt->closeCursor();
			$count = $row['count'];

			$this->assertEquals(0, $count);
		}
	}

	public function testCleanup() {
		$user = 'integration';
		$this->checkForEmptyTables($user);
		$this->loadData('MaintenanceCleanupData.json');

		$maintenance = new Maintenance($this->db, $this->logger);
		$maintenance->cleanUp();
		$this->checkForEmptyTables($user);
	}
}
