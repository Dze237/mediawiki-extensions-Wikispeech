<?php

namespace MediaWiki\Wikispeech\Tests\Unit;

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */
use HashConfig;
use InvalidArgumentException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Wikispeech\SpeechoidConnector;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Wikispeech\SpeechoidConnector
 */
class SpeechoidConnectorTest extends MediaWikiUnitTestCase {
	protected function setUp() : void {
		$this->requestFactory = $this->createMock( HttpRequestFactory::class );
		$config = new HashConfig();
		$config->set( 'WikispeechSpeechoidResponseTimeoutSeconds', null );
		$config->set( 'WikispeechSpeechoidUrl', 'url' );
		$this->speechoidConnector = new SpeechoidConnector(
			$config,
			$this->requestFactory
		);
	}

	public function testSynthesize_textGiven_sendRequestWithTextAsInput() {
		$this->requestFactory
			->method( 'post' )
			->willReturn( '{"speechoid": "response"}' );
		$this->requestFactory
			->expects( $this->once() )
			->method( 'post' )
			->with(
				$this->equalTo( 'url' ),
				$this->equalTo( [ 'postData' => [
					'lang' => 'en',
					'voice' => 'en-voice',
					'input' => 'say this'
				] ] )
			);
		$response = $this->speechoidConnector->synthesize(
			'en',
			'en-voice',
			[ 'text' => 'say this' ]
		);
		$this->assertSame( [ 'speechoid' => 'response' ], $response );
	}

	public function testSynthesize_ipaGiven_sendRequestWithIpaAsInputAndIpaAsType() {
		$this->requestFactory
			->method( 'post' )
			->willReturn( '{"speechoid": "response"}' );
		$this->requestFactory
			->expects( $this->once() )
			->method( 'post' )
			->with(
				$this->equalTo( 'url' ),
				$this->equalTo( [ 'postData' => [
					'lang' => 'en',
					'voice' => 'en-voice',
					'input' => 'seɪ.ðɪs',
					'input_type' => 'ipa'
				] ] )
			);
		$response = $this->speechoidConnector->synthesize(
			'en',
			'en-voice',
			[ 'ipa' => 'seɪ.ðɪs' ]
		);
		$this->assertSame( [ 'speechoid' => 'response' ], $response );
	}

	public function testSynthesize_textOrIpaNotInParameters_throwException() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage(
			'$parameters must contain one of "text" and "ipa".'
		);

		$this->speechoidConnector->synthesize(
			'en',
			'en-voice',
			[]
		);
	}
}
