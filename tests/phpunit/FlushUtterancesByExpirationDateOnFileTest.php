<?php

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

// files in maintenance/ are not autoloaded to avoid accidental usage, so load explicitly
require_once __DIR__ . '/../../maintenance/flushUtterancesByExpirationDateOnFile.php';

/**
 * @covers FlushUtterancesByExpirationDateOnFile
 * @since 0.1.7
 */
class FlushUtterancesByExpirationDateOnFileTest
	extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return FlushUtterancesByExpirationDateOnFile::class;
	}

	public function testFlush_force_executeSuccessful() {
		$utteranceStoreMock = $this->createMock( UtteranceStore::class );
		$utteranceStoreMock
			->expects( $this->once() )
			->method( 'flushUtterancesByExpirationDateOnFile' )
			->willReturn( 0 );
		$this->maintenance->utteranceStore = $utteranceStoreMock;
		$this->maintenance->loadWithArgv( [
			'--force'
		] );
		$this->assertTrue( $this->maintenance->execute() );
	}

	public function testFlush_queue_executeSuccessful() {
		$jobQueueMock = $this->createMock( FlushUtterancesByExpirationDateOnFileJobQueue::class );
		$jobQueueMock
			->expects( $this->once() )
			->method( 'queueJob' );
		$this->maintenance->jobQueue = $jobQueueMock;
		$this->assertTrue( $this->maintenance->execute() );
	}

}
