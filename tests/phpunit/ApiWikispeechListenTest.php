<?php

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use Wikimedia\TestingAccessWrapper;

/**
 * @group medium
 * @covers ApiWikispeechListen
 */
class ApiWikispeechListenTest extends ApiTestCase {

	protected function setUp() : void {
		parent::setUp();
		// Should be implementable using
		// $wgConfigRegistry['wikispeech'] see T255497
		$this->setMwGlobals( [
			// Make sure we don't send requests to an actual server.
			'wgWikispeechSpeechoidUrl' => '',
			'wgWikispeechVoices' => [
				'ar' => [ 'ar-voice' ],
				'en' => [
					'en-voice1',
					'en-voice2'
				],
				'sv' => [ 'sv-voice' ]
			],
			'wgWikispeechListenMaximumInputCharacters' => 60
		] );
	}

	public function testInvalidLanguage() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'"xx" is not a valid language. Should be one of: "ar", "en", "sv".'
		);
		$this->doApiRequest( [
			'action' => 'wikispeechlisten',
			'text' => 'Text to listen to.',
			'lang' => 'xx'
		] );
	}

	public function testInvalidVoice() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'"invalid-voice" is not a valid voice. Should be one of: "en-voice1", "en-voice2".'
		);
		$this->doApiRequest( [
			'action' => 'wikispeechlisten',
			'text' => 'Text to listen to.',
			'lang' => 'en',
			'voice' => 'invalid-voice'
		] );
	}

	/**
	 * @since 0.1.5
	 */
	public function testValidInputLength() {
		$api = TestingAccessWrapper::newFromObject( new ApiWikispeechListen(
			new ApiMain(), null
		) );
		$api->validateParameters( [
			'action' => 'wikispeechlisten',
			'text' => 'This is a short sentence with less than 60 characters.',
			'lang' => 'en',
			'voice' => ''
		] );
		// What we really want to do here is to assert that
		// ApiUsageException is not thrown.
		$this->assertTrue( true );
	}

	/**
	 * @since 0.1.5
	 */
	public function testInvalidInputLength() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'Input text must not exceed 60 characters, but contained '
			. '64 characters.'
		);
		$api = TestingAccessWrapper::newFromObject( new ApiWikispeechListen(
			new ApiMain(), null
		) );
		$api->validateParameters( [
			'action' => 'wikispeechlisten',
			'text' => 'This is a tiny bit longer sentence with more than 60 characters.',
			'lang' => 'en',
			'voice' => ''
		] );
	}

	public function testRequest_revisionParameterAndNoSegmentParameter_exceptionRaised() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'The "revision" parameter may only be used with "segment".'
		);
		$this->doApiRequest( [
			'action' => 'wikispeechlisten',
			'revision' => 1,
			'lang' => 'en',
			'voice' => ''
		] );
	}

	public function testRequest_segmentParameterAndNoRevisionParameter_exceptionRaised() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'The "segment" parameter may only be used with "revision".'
		);
		$this->doApiRequest( [
			'action' => 'wikispeechlisten',
			'segment' => 'hash1234',
			'lang' => 'en',
			'voice' => ''
		] );
	}

	public function testRequest_revisionAndTextParametersGiven_exceptionRaised() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'The "text" parameter cannot be used with "revision".'
		);
		$this->doApiRequest( [
			'action' => 'wikispeechlisten',
			'revision' => 1,
			'segment' => 'hash1234',
			'text' => 'Text to listen to.',
			'lang' => 'en',
			'voice' => ''
		] );
	}

	/**
	 * @since 0.1.5
	 */
	public function testGetUtterance_requestNewUtterance_speechoidConnectorExecuted() {
		$synthesizeMetadataJson =
			'[' .
			'{"endtime": 0.295, "orth": "Word"}, ' .
			'{"endtime": 0.51, "expanded": "one", "orth": "1"}, ' .
			'{"endtime": 0.8, "orth": "Word"}, ' .
			'{"endtime": 0.93, "expanded": "two", "orth": "2"}, ' .
			'{"endtime": 1.215, "orth": "Word"}, ' .
			'{"endtime": 1.565, "expanded": "three", "orth": "3"}, ' .
			'{"endtime": 1.565, "orth": "."}, ' .
			'{"endtime": 1.975, "orth": ""}' .
			']';
		$synthesizeMetadataArray = FormatJson::parse(
			$synthesizeMetadataJson,
			FormatJson::FORCE_ASSOC
		)->getValue();
		// this is a solution to avoid floating point conversion errors
		$synthesizeMetadataJson =
			FormatJson::encode( $synthesizeMetadataArray );

		$api = TestingAccessWrapper::newFromObject(
			new ApiWikispeechListen(
				new ApiMain(),
				null
			)
		);

		$utteranceStoreMock = $this->createMock( UtteranceStore::class );
		$utteranceStoreMock
			->method( 'findUtterance' )
			->with(
				// $pageId, $language, $voice, $segmentHash, $omitAudio = false
				$this->equalTo( 2 ),
				$this->equalTo( 'sv' ),
				$this->equalTo( 'anna' ),
				$this->equalTo( '4466ca9fbdfc6c9cf9c53de4e5e373d6b60d023338e9a9f9ff8e6ddaef36a3e4' ),
				$this->equalTo( false )
			)
			->willReturn( null );
		$utteranceStoreMock
			->method( 'createUtterance' )
			->with(
				// $pageId, $language, $voice, $segmentHash, $audio, $synthesisMetadata
				$this->equalTo( 2 ),
				$this->equalTo( 'sv' ),
				$this->equalTo( 'anna' ),
				$this->equalTo( '4466ca9fbdfc6c9cf9c53de4e5e373d6b60d023338e9a9f9ff8e6ddaef36a3e4' ),
				$this->equalTo( 'DummyBase64==' ),
				$this->equalTo( $synthesizeMetadataJson )
			);
		$api->utteranceStore = $utteranceStoreMock;

		$speechoidConnectorMock = $this->createMock( SpeechoidConnector::class );
		$speechoidConnectorMock
			->expects( $this->once() )
			->method( 'synthesize' )
			->with(
				$this->equalTo( 'sv' ),
				$this->equalTo( 'anna' ),
				$this->equalTo( 'Word 1 Word 2 Word 3.' )
			)
			->willReturn( [
				"audio_data" => "DummyBase64==",
				"tokens" => $synthesizeMetadataArray
			] );
		$api->speechoidConnector = $speechoidConnectorMock;

		$utterance = $api->getUtterance(
			'anna',
			'sv',
			2,
			'4466ca9fbdfc6c9cf9c53de4e5e373d6b60d023338e9a9f9ff8e6ddaef36a3e4',
			'Word 1 Word 2 Word 3.'
		);

		$this->assertSame( 'DummyBase64==', $utterance['audio'] );
		$this->assertSame( $synthesizeMetadataArray, $utterance['tokens'] );
	}

	/**
	 * @since 0.1.5
	 */
	public function testGetUtterance_requestExistingUtterance_speechoidConnectorNotExecuted() {
		$synthesizeMetadataJson =
			'[' .
			'{"endtime": 0.295, "orth": "Word"}, ' .
			'{"endtime": 0.51, "expanded": "one", "orth": "1"}, ' .
			'{"endtime": 0.8, "orth": "Word"}, ' .
			'{"endtime": 0.93, "expanded": "two", "orth": "2"}, ' .
			'{"endtime": 1.215, "orth": "Word"}, ' .
			'{"endtime": 1.565, "expanded": "three", "orth": "3"}, ' .
			'{"endtime": 1.565, "orth": "."}, ' .
			'{"endtime": 1.975, "orth": ""}' .
			']';
		$synthesizeMetadataArray = FormatJson::parse(
			$synthesizeMetadataJson,
			FormatJson::FORCE_ASSOC
		)->getValue();
		// this is a solution to avoid floating point conversion errors
		$synthesizeMetadataJson =
			FormatJson::encode( $synthesizeMetadataArray );

		$api = TestingAccessWrapper::newFromObject(
			new ApiWikispeechListen(
				new ApiMain(),
				null
			)
		);

		$utteranceStoreMock = $this->createMock( UtteranceStore::class );
		$utteranceStoreMock
			->method( 'findUtterance' )
			->with(
				// $pageId, $language, $voice, $segmentHash, $omitAudio = false
				$this->equalTo( 2 ),
				$this->equalTo( 'sv' ),
				$this->equalTo( 'anna' ),
				$this->equalTo( '4466ca9fbdfc6c9cf9c53de4e5e373d6b60d023338e9a9f9ff8e6ddaef36a3e4' ),
				$this->equalTo( false )
			)
			->willReturn( [
				'utteranceId' => 1,
				'pageId' => 2,
				'language' => 'sv',
				'voice' => 'anna',
				'segmentHash' => '4466ca9fbdfc6c9cf9c53de4e5e373d6b60d023338e9a9f9ff8e6ddaef36a3e4',
				'dateStored' => MWTimestamp::getInstance( 20020101000000 ),
				'audio' => 'DummyBase64==',
				'synthesisMetadata' => $synthesizeMetadataJson,
			] );
		$api->utteranceStore = $utteranceStoreMock;

		$speechoidConnectorMock = $this->createMock( SpeechoidConnector::class );
		$speechoidConnectorMock
			->expects( $this->never() )
			->method( 'synthesize' );
		$api->speechoidConnector = $speechoidConnectorMock;

		$utterance = $api->getUtterance(
			'anna',
			'sv',
			2,
			'4466ca9fbdfc6c9cf9c53de4e5e373d6b60d023338e9a9f9ff8e6ddaef36a3e4',
			'Word 1 Word 2 Word 3.'
		);

		$this->assertSame( 'DummyBase64==', $utterance['audio'] );
		$this->assertSame( $synthesizeMetadataArray, $utterance['tokens'] );
	}

	public function testRequest_notCurrentRevision_throwsException() {
		$page = Util::addPage( 'Page', 'Old' );
		$oldId = $page->getRevisionRecord()->getId();
		// Making an edit causes the initial revision to be non-current.
		$page->doEditContent(
			ContentHandler::makeContent(
				'New',
				$page->getTitle(),
				CONTENT_MODEL_WIKITEXT
			),
			''
		);
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage(
			'Only latest revision of a page may be used.'
		);
		$this->doApiRequest( [
			'action' => 'wikispeechlisten',
			'revision' => $oldId,
			'segment' => 'hash',
			'lang' => 'en'
		] );
	}
}
