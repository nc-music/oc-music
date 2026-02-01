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
 * @copyright Pauli Järvinen 2016 - 2026
 */

namespace OCA\Music\Controller;

use OCA\Music\Service\IScrobbler;
use OCA\Music\Tests\Utility\ControllerTestUtility;
use OCP\AppFramework\Http\JSONResponse;

use OCA\Music\DB\Track;

class MusicApiControllerTest extends ControllerTestUtility {
	private $trackBusinessLayer;
	private $genreBusinessLayer;
	private $collectionService;
	private $request;
	private $controller;
	private $userId = 'john';
	private $appname = 'music';
	private $scanner;
	private $coverService;
	private $fileSystemService;
	private $detailsService;
	private $lastfmService;
	private $maintenance;
	private $librarySettings;
	private $logger;

	protected function setUp() : void {
		$this->request = $this->getMockBuilder('\OCP\IRequest')
			->disableOriginalConstructor()
			->getMock();
		$this->trackBusinessLayer = $this->getMockBuilder('\OCA\Music\BusinessLayer\TrackBusinessLayer')
			->disableOriginalConstructor()
			->getMock();
		$this->genreBusinessLayer = $this->getMockBuilder('\OCA\Music\BusinessLayer\GenreBusinessLayer')
			->disableOriginalConstructor()
			->getMock();
		$this->scanner = $this->getMockBuilder('\OCA\Music\Service\Scanner')
			->disableOriginalConstructor()
			->getMock();
		$this->collectionService = $this->getMockBuilder('\OCA\Music\Service\CollectionService')
			->disableOriginalConstructor()
			->getMock();
		$this->coverService = $this->getMockBuilder('\OCA\Music\Service\CoverService')
			->disableOriginalConstructor()
			->getMock();
		$this->detailsService = $this->getMockBuilder('\OCA\Music\Service\DetailsService')
			->disableOriginalConstructor()
			->getMock();
		$this->fileSystemService = $this->getMockBuilder('\OCA\Music\Service\FileSystemService')
			->disableOriginalConstructor()
			->getMock();
		$this->lastfmService = $this->getMockBuilder('\OCA\Music\Service\LastfmService')
			->disableOriginalConstructor()
			->getMock();
		$this->maintenance = $this->getMockBuilder('\OCA\Music\Db\Maintenance')
			->disableOriginalConstructor()
			->getMock();
		$this->librarySettings = $this->getMockBuilder('\OCA\Music\Service\LibrarySettings')
			->disableOriginalConstructor()
			->getMock();
		$this->logger = $this->getMockBuilder('\OCA\Music\AppFramework\Core\Logger')
			->disableOriginalConstructor()
			->getMock();
		$this->controller = new MusicApiController(
			$this->appname,
			$this->request,
			$this->trackBusinessLayer,
			$this->genreBusinessLayer,
			$this->scanner,
			$this->collectionService,
			$this->coverService,
			$this->detailsService,
			$this->fileSystemService,
			$this->lastfmService,
			$this->maintenance,
			$this->librarySettings,
			$this->userId,
			$this->logger,
			$this->createMock(IScrobbler::class)
		);
	}

	public function testTrackByFileId() {
		$trackId = 1;
		$fileId = 3;

		$track = new Track();
		$track->setId($trackId);
		$track->setTitle('The title');
		$track->setArtistId(3);
		$track->setArtistName('The track artist');
		$track->setAlbumId(1);
		$track->setNumber(4);
		$track->setDisk(1);
		$track->setLength(123);
		$track->setFileId($fileId);
		$track->setMimetype('audio/mp3');
		$track->setBitrate(123);

		$this->trackBusinessLayer->expects($this->once())
			->method('findByFileId')
			->with($this->equalTo($fileId), $this->equalTo($this->userId))
			->will($this->returnValue($track));

		$result = [
			'title' => 'The title',
			'id' => 1,
			'number' => 4,
			'disk' => 1,
			'artistId' => 3,
			'length' => 123,
			'files' => [
				'audio/mp3' => $fileId
			]
		];

		$response = $this->controller->trackByFileId($fileId);

		$this->assertEquals($result, $response->getData());
		$this->assertTrue($response instanceof JSONResponse);
	}

}
