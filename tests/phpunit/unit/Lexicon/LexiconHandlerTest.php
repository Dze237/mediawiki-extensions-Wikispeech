<?php

namespace MediaWiki\Wikispeech\Tests\Unit\Lexicon;

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use HashBagOStuff;
use MediaWiki\Wikispeech\Lexicon\LexiconEntry;
use MediaWiki\Wikispeech\Lexicon\LexiconEntryItem;
use MediaWiki\Wikispeech\Lexicon\LexiconHandler;
use MediaWiki\Wikispeech\Lexicon\LexiconSpeechoidStorage;
use MediaWiki\Wikispeech\Lexicon\LexiconWanCacheStorage;
use MediaWikiUnitTestCase;

/**
 * @since 0.1.8
 * @covers \MediaWiki\Wikispeech\Lexicon\LexiconHandler
 */
class LexiconHandlerTest extends MediaWikiUnitTestCase {

	/** @var LexiconEntry */
	private $mockedLexiconEntry;

	/** @var HashBagOStuff */
	private $cache;

	protected function setUp(): void {
		parent::setUp();

		$this->cache = new HashBagOStuff();
		$cacheKey = $this->cache->makeKey( LexiconSpeechoidStorage::CACHE_CLASS, 'sv' );
		$this->cache->set( $cacheKey, 'sv_se_nst_lex:sv-se.nst' );

		$this->mockedLexiconEntry = new LexiconEntry();
		$this->mockedLexiconEntry->setLanguage( 'sv' );
		$this->mockedLexiconEntry->setKey( 'tomten' );

		$mockedEntryItem0 = new LexiconEntryItem();
		$mockedEntryItem0->setProperties( [ 'id' => 'item 0' ] );
		$this->mockedLexiconEntry->getItems()[] = $mockedEntryItem0;

		$mockedEntryItem1 = new LexiconEntryItem();
		$mockedEntryItem1->setProperties( [ 'id' => 'item 1' ] );
		$this->mockedLexiconEntry->getItems()[] = $mockedEntryItem1;
	}

	public function testGetEntry_existingInBoth_retrieved() {
		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $this->mockedLexiconEntry );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $this->mockedLexiconEntry );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$entry = $lexiconHandler->getEntry( 'sv', 'tomten' );
		$this->assertNotNull( $entry );
	}

	public function testGetEntry_nonExistingInLocal_retrieved() {
		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $this->mockedLexiconEntry );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( null );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$entry = $lexiconHandler->getEntry( 'sv', 'tomten' );
		$this->assertNotNull( $entry );
	}

	// tests for get with merge

	/**
	 * One item that exists only in local lexicon.
	 * One identical item that exists in both local and Speechoid lexicon.
	 */
	public function testGetEntry_localOnlyAndIntersecting_fails() {
		$intersectingItem = new LexiconEntryItem();
		$intersectingItem->setProperties( [
			'id' => 'intersecting item',
			'foo' => 'bar'
		] );

		$localOnlyItem = new LexiconEntryItem();
		$localOnlyItem->setProperties( [
			'id' => 'local only item',
			'foo' => 'bass'
		] );

		$localEntry = new LexiconEntry();
		$localEntry->setKey( 'tomten' );
		$localEntry->setLanguage( 'sv' );
		$localEntry->setItems( [ $localOnlyItem, $intersectingItem ] );

		$speechoidEntry = new LexiconEntry();
		$speechoidEntry->setKey( 'tomten' );
		$speechoidEntry->setLanguage( 'sv' );
		$speechoidEntry->setItems( [ $intersectingItem ] );

		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $speechoidEntry );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $localEntry );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$this->expectExceptionMessage(
			'Storages out of sync. 1 entry items from local and Speechoid lexicon failed to merge.'
		);
		$lexiconHandler->getEntry(
			'sv',
			'tomten'
		);
	}

	/**
	 * One item that exists only in Speechoid lexicon
	 * One identical item that exists in both local and Speechoid lexicon.
	 */
	public function testGetEntry_speechoidOnlyAndIntersecting_mergedAll() {
		$intersectingItem = new LexiconEntryItem();
		$intersectingItem->setProperties( [
			'id' => 'intersecting item',
			'foo' => 'bar'
		] );

		$speechoidOnlyItem = new LexiconEntryItem();
		$speechoidOnlyItem->setProperties( [
			'id' => 'speechoid only item',
			'foo' => 'bar'
		] );

		$localEntry = new LexiconEntry();
		$localEntry->setKey( 'tomten' );
		$localEntry->setLanguage( 'sv' );
		$localEntry->setItems( [ $intersectingItem ] );

		$speechoidEntry = new LexiconEntry();
		$speechoidEntry->setKey( 'tomten' );
		$speechoidEntry->setLanguage( 'sv' );
		$speechoidEntry->setItems( [ $speechoidOnlyItem, $intersectingItem ] );

		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $speechoidEntry );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $localEntry );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$mergedEntry = $lexiconHandler->getEntry(
			'sv',
			'tomten'
		);
		$this->assertCount( 2, $mergedEntry->getItems() );
		$this->assertContains( $speechoidOnlyItem, $mergedEntry->getItems() );
		$this->assertContains( $intersectingItem, $mergedEntry->getItems() );
	}

	/**
	 * The same identity on an item in both,
	 * local is said to be uploaded to Speechoid,
	 * but item contents differ.
	 *
	 * This simulates that Speechoid has been wiped clean.
	 *
	 * We should choose the local item, but we don't know how.
	 */
	public function testGetEntry_reinstalledSpeechoidLexicon_fails() {
		$localItem = new LexiconEntryItem();
		$localItem->setProperties( [
			'id' => '123',
			'foo' => 'locally changed before reinstall of Speechoid',
			'status' => [
				// notice that timestamp is older than the reinstalled speechoid item.
				'timestamp' => '2017-06-18T08:51:25Z'
			]
		] );

		$speechoidItem = new LexiconEntryItem();
		$speechoidItem->setProperties( [
			'id' => '123',
			'foo' => 'reinstalled',
			'status' => [
				// notice that timestamp is newer than the locally changed item.
				'timestamp' => '2018-06-18T08:51:25Z'
			]
		] );

		$localEntry = new LexiconEntry();
		$localEntry->setKey( 'tomten' );
		$localEntry->setLanguage( 'sv' );
		$localEntry->setItems( [ $localItem ] );

		$speechoidEntry = new LexiconEntry();
		$speechoidEntry->setKey( 'tomten' );
		$speechoidEntry->setLanguage( 'sv' );
		$speechoidEntry->setItems( [ $speechoidItem ] );

		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $speechoidEntry );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $localEntry );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );

		$this->expectExceptionMessage(
			'Storages out of sync. 1 entry items from local and Speechoid lexicon failed to merge.'
		);
		$lexiconHandler->getEntry(
			'sv',
			'tomten'
		);
	}

	// create and update is in fact the same function in LexiconHandler

	public function testCreateEntryItem_nonExisting_createdInLocalAndSpeechoid() {
		$item = new LexiconEntryItem();
		$item->setProperties( [ 'no identity' => 'none set' ] );

		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->never() )
			->method( 'getEntry' );
		$speechoidMock
			->expects( $this->once() )
			->method( 'createEntryItem' )
			->with( 'sv', 'tomten', $item );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->never() )
			->method( 'getEntry' );
		$localMock
			->expects( $this->never() )
			->method( 'entryItemExists' );
		$localMock
			->expects( $this->once() )
			->method( 'createEntryItem' )
			->with( 'sv', 'tomten', $item );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$lexiconHandler->createEntryItem(
			'sv',
			'tomten',
			$item
		);
	}

	// @todo test with failed add to speechoid, failed to add local, and failed to add both

	public function testUpdateEntryItem_existingInBoth_updatedInBoth() {
		$item = new LexiconEntryItem();
		$item->setProperties( [ 'id' => 'item' ] );

		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->never() )
			->method( 'getEntry' );
		$speechoidMock
			->expects( $this->never() )
			->method( 'createEntryItem' );
		$speechoidMock
			->expects( $this->once() )
			->method( 'updateEntryItem' )
			->with( 'sv', 'tomten', $item );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->never() )
			->method( 'getEntry' );
		$localMock
			->expects( $this->once() )
			->method( 'entryItemExists' )
			->with( 'sv', 'tomten', $item )
			->willReturn( true );
		$localMock
			->expects( $this->never() )
			->method( 'createEntryItem' );
		$localMock
			->expects( $this->once() )
			->method( 'updateEntryItem' )
			->with( 'sv', 'tomten', $item );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$lexiconHandler->updateEntryItem(
			'sv',
			'tomten',
			$item
		);
	}

	/**
	 * Updates as item that exists in Speechoid but not in local storage.
	 * Current revision should be retrieved from Speechoid and created in local,
	 * then new item updated in speechoid,
	 * and finally new item updated in local.
	 */
	public function testUpdateEntryItem_existingOnlyInSpeechoid_currentCreatedInLocalUpdatedInBoth() {
		$entryCurrent = new LexiconEntry();
		$entryCurrent->setKey( 'tomten' );
		$entryCurrent->setLanguage( 'sv' );
		$itemCurrent = new LexiconEntryItem();
		$itemCurrent->setProperties( [ 'id' => 'item', 'value' => 'initial' ] );
		$entryCurrent->setItems( [ $itemCurrent ] );

		$item = new LexiconEntryItem();
		$item->setProperties( [ 'id' => 'item', 'value' => 'updated' ] );

		$speechoidMock = $this->createMock( LexiconSpeechoidStorage::class );
		$speechoidMock
			->expects( $this->once() )
			->method( 'getEntry' )
			->with( 'sv', 'tomten' )
			->willReturn( $entryCurrent );
		$speechoidMock
			->expects( $this->never() )
			->method( 'createEntryItem' );
		$speechoidMock
			->expects( $this->once() )
			->method( 'updateEntryItem' )
			->with( 'sv', 'tomten', $item );

		$localMock = $this->createMock( LexiconWanCacheStorage::class );
		$localMock
			->expects( $this->never() )
			->method( 'getEntry' );
		$localMock
			->expects( $this->once() )
			->method( 'entryItemExists' )
			->with( 'sv', 'tomten', $item )
			->willReturn( false );
		$localMock
			->expects( $this->once() )
			->method( 'createEntryItem' )
			->with( 'sv', 'tomten', $itemCurrent );
		$localMock
			->expects( $this->once() )
			->method( 'updateEntryItem' )
			->with( 'sv', 'tomten', $item );

		$lexiconHandler = new LexiconHandler( $speechoidMock, $localMock );
		$lexiconHandler->updateEntryItem(
			'sv',
			'tomten',
			$item
		);
	}
}
