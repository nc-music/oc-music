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

namespace OCA\Music\BusinessLayer;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Cache;
use OCA\Music\Db\Track;
use OCA\Music\Db\TrackMapper;
use OCA\Music\Service\FileSystemService;

class TrackBusinessLayerTest extends \PHPUnit\Framework\TestCase {
	private $mapper;
	private $fileSystemService;
	private $logger;
	private $trackBusinessLayer;
	private $userId;
	private $artistId;
	private $albumId;
	private $fileId;
	private $cache;

	protected function setUp() : void {
		$this->mapper = $this->getMockBuilder(TrackMapper::class)
			->disableOriginalConstructor()
			->getMock();
		$this->fileSystemService = $this->getMockBuilder(FileSystemService::class)
			->disableOriginalConstructor()
			->getMock();
		$this->logger = $this->getMockBuilder(Logger::class)
			->disableOriginalConstructor()
			->getMock();
		$this->cache = $this->getMockBuilder(Cache::class)
			->disableOriginalConstructor()
			->getMock();
		$this->trackBusinessLayer = new TrackBusinessLayer($this->mapper, $this->fileSystemService, $this->logger, $this->cache);
		$this->userId = 'jack';
		$this->artistId = 3;
		$this->albumId = 3;
		$this->fileId = 2;
	}

	public function testFindAllByArtist() {
		$response = [new Track(), new Track()];
		$this->mapper->expects($this->once())
			->method('findAllByArtist')
			->with(
				$this->equalTo([$this->artistId]),
				$this->equalTo($this->userId)
			)
			->will($this->returnValue($response));

		$result = $this->trackBusinessLayer->findAllByArtist(
			$this->artistId,
			$this->userId
		);
		$this->assertEquals($response, $result);
	}

	public function testFindAllByAlbum() {
		$response = [new Track(), new Track()];
		$this->mapper->expects($this->once())
			->method('findAllByAlbum')
			->with(
				$this->equalTo([$this->albumId]),
				$this->equalTo($this->userId)
			)
			->will($this->returnValue($response));

		$result = $this->trackBusinessLayer->findAllByAlbum(
			$this->albumId,
			$this->userId
		);
		$this->assertEquals($response, $result);
	}

	public function testFindByFileId() {
		$response = new Track();
		$this->mapper->expects($this->once())
			->method('findByFileId')
			->with(
				$this->equalTo($this->fileId),
				$this->equalTo($this->userId)
			)
			->will($this->returnValue($response));

		$result = $this->trackBusinessLayer->findByFileId(
			$this->fileId,
			$this->userId
		);
		$this->assertEquals($response, $result);
	}

	public function testAddOrUpdateTrack() {
		$title = 'test';
		$fileId = 2;

		$track = new Track();
		$track->setTitle($title);
		$track->setId(1);

		$this->mapper->expects($this->once())
			->method('insertOrUpdate')
			->will($this->returnValue($track));

		$result = $this->trackBusinessLayer->addOrUpdateTrack('test', null, null, null, 1, 1, 1, $fileId, 'audio/mpeg', $this->userId);
		$this->assertEquals($track, $result);
	}

	public function testDeleteTracksEmpty() {
		$fileId = 2;

		$this->mapper->expects($this->once())
			->method('findAllByFileIds')
			->with($this->equalTo([$fileId]))
			->will($this->returnValue([]));

		$this->mapper->expects($this->never())
			->method('delete');

		$this->mapper->expects($this->never())
			->method('countByArtist');

		$this->mapper->expects($this->never())
			->method('countByAlbum');

		$result = $this->trackBusinessLayer->deleteTracks([$fileId]);
		$this->assertEquals(false, $result);
	}

	public function testDeleteTracksDeleteArtist() {
		$fileId = 2;

		$track = new Track();
		$track->setArtistId(2);
		$track->setAlbumId(3);
		$track->setId(1);
		$track->setUserId($this->userId);

		$this->mapper->expects($this->once())
			->method('findAllByFileIds')
			->with($this->equalTo([$fileId]))
			->will($this->returnValue([$track]));

		$this->mapper->expects($this->once())
			->method('deleteById')
			->with($this->equalTo([$track->getId()]));

		$this->mapper->expects($this->once())
			->method('countByArtist')
			->with($this->equalTo(2))
			->will($this->returnValue(0));

		$this->mapper->expects($this->once())
			->method('countByAlbum')
			->with($this->equalTo(3))
			->will($this->returnValue(1));

		$result = $this->trackBusinessLayer->deleteTracks([$fileId]);
		$this->assertEquals([], $result['obsoleteAlbums']);
		$this->assertEquals([2], $result['obsoleteArtists']);
		$this->assertEquals([3], $result['remainingAlbums']);
		$this->assertEquals([], $result['remainingArtists']);
		$this->assertEquals([$this->userId], $result['affectedUsers']);
	}

	public function testDeleteTracksDeleteAlbum() {
		$fileId = 2;

		$track = new Track();
		$track->setArtistId(2);
		$track->setAlbumId(3);
		$track->setId(1);
		$track->setUserId($this->userId);

		$this->mapper->expects($this->once())
			->method('findAllByFileIds')
			->with($this->equalTo([$fileId]))
			->will($this->returnValue([$track]));

		$this->mapper->expects($this->once())
			->method('deleteById')
			->with($this->equalTo([$track->getId()]));

		$this->mapper->expects($this->once())
			->method('countByArtist')
			->with($this->equalTo(2))
			->will($this->returnValue(1));

		$this->mapper->expects($this->once())
			->method('countByAlbum')
			->with($this->equalTo(3))
			->will($this->returnValue(0));

		$result = $this->trackBusinessLayer->deleteTracks([$fileId]);
		$this->assertEquals([3], $result['obsoleteAlbums']);
		$this->assertEquals([], $result['obsoleteArtists']);
		$this->assertEquals([], $result['remainingAlbums']);
		$this->assertEquals([2], $result['remainingArtists']);
		$this->assertEquals([$this->userId], $result['affectedUsers']);
	}
}
