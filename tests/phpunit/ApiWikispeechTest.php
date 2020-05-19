<?php

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

define( 'TITLE', 'Test_Page' );

/**
 * @group Database
 * @group medium
 * @covers ApiWikispeech
 */
class ApiWikispeechTest extends ApiTestCase {
	public function addDBDataOnce() {
		$content = "Text ''italic'' '''bold'''";
		$this->addPage( TITLE, $content );
		$talkContent = "Talking about ''italic'' '''bold'''";
		$this->addPage( TITLE, $talkContent, NS_TALK );
	}

	private function addPage( $titleString, $content, $namespace = NS_MAIN ) {
		$title = Title::newFromText( $titleString, $namespace );
		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent(
				$content,
				$title,
				CONTENT_MODEL_WIKITEXT
			),
			''
		);
		if ( !$status->isOk() ) {
			$this->fail( "Failed to create $title: " . $status->getWikiText( false, false, 'en' ) );
		}
	}

	public function testCleanText() {
		$res = $this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'cleanedtext'
		] );
		$this->assertEquals(
			"Test Page\nText italic bold",
			$res[0]['wikispeech']['cleanedtext']
		);
	}

	public function testCleanTextHandleSegmentBreaks() {
		$title = 'Break';
		$content = 'Text with<br/ >break.';
		$this->addPage( $title, $content );
		$res = $this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => $title,
			'output' => 'cleanedtext',
			'segmentbreakingtags' => 'br'
		] );
		$this->assertEquals(
			"Break\nText with\nbreak.",
			$res[0]['wikispeech']['cleanedtext']
		);
	}

	public function testOriginalContent() {
		$res = $this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'originalcontent'
		] );
		$this->assertEquals(
			"<div class=\"mw-parser-output\"><p>Text <i>italic</i> <b>bold</b>\n</p>\n<!--",
			mb_substr( $res[0]['wikispeech']['originalcontent'], 0, 73 )
		);
	}

	public function testSegmentText() {
		$res = $this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => 'Talk:' . TITLE,
			'output' => 'segments'
		] );
		$this->assertEquals( 2, count( $res[0]['wikispeech']['segments'] ) );
		$this->assertEquals(
			[
				'startOffset' => 0,
				'endOffset' => 13,
				'content' => [
					[
						'string' => 'Talk:Test Page',
						'path' => '//h1[@id="firstHeading"]//text()'
					]
				],
				'hash' => '50c0083861b4c8bc5e6c1402bbb18ab093cfdf930aa8f5ef9297764e01137a26'
			],
			$res[0]['wikispeech']['segments'][0]
		);
		$this->assertEquals(
			[
				'startOffset' => 0,
				'endOffset' => 3,
				'content' => [
					[
						'string' => 'Talking about ',
						'path' => './div/p/text()[1]'
					],
					[
						'string' => 'italic',
						'path' => './div/p/i/text()'
					],
					[
						'string' => ' ',
						'path' => './div/p/text()[2]'
					],
					[
						'string' => 'bold',
						'path' => './div/p/b/text()'
					]
				],
				'hash' => 'beeb949bc6c4193ad4903fcf93090dbd4a759b82b5d923cb0136421eb7eee3ca'
			],
			$res[0]['wikispeech']['segments'][1]
		);
	}

	public function testRemoveTagsInvalidJsonThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( '"removetags" is not a valid JSON string.' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'cleanedtext',
			'removetags' => 'not a JSON string'
		] );
	}

	public function testRemoveTagsNotAnObjectThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( '"removetags" is not of a valid format.' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'cleanedtext',
			'removetags' => '"not a JSON object"'
		] );
	}

	public function testRemoveTagsInvalidValueThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( '"removetags" is not of a valid format.' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'cleanedtext',
			'removetags' => '{"tag": null}'
		] );
	}

	public function testRemoveTagsJsonArrayThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( '"removetags" is not of a valid format.' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'cleanedtext',
			'removetags' => '[true]'
		] );
	}

	public function testRemoveTagsInvalidRuleThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( '"removetags" is not of a valid format.' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => 'cleanedtext',
			'removetags' => '{"tag": ["valid", false]}'
		] );
	}

	public function testInvalidPageThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( 'There is no revision with ID' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => 'Not a page',
			'output' => 'cleanedtext',
			'removetags' => '{}'
		] );
	}

	public function testNoOutputFormatThrowsException() {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( 'The parameter "output" may not be empty.' );
		$this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => TITLE,
			'output' => '',
			'removetags' => '{}'
		] );
	}

	public function testSegmentTextHandleDisplayTitle() {
		$title = 'Title';
		$content = '{{DISPLAYTITLE:title}}Some content text.';
		$this->addPage( $title, $content );
		$res = $this->doApiRequest( [
			'action' => 'wikispeech',
			'page' => $title,
			'output' => 'segments'
		] );
		$this->assertEquals( 2, count( $res[0]['wikispeech']['segments'] ) );
		$this->assertEquals(
			[
				'startOffset' => 0,
				'endOffset' => 4,
				'content' => [
					[
						'string' => 'title',
						'path' => '//h1[@id="firstHeading"]//text()'
					]
				],
				'hash' => '1ec72b6861fee9926d828a734ddbd533a1eb1a983d42acec571720deb2b92018'
			],
			$res[0]['wikispeech']['segments'][0]
		);
		$this->assertEquals(
			[
				'startOffset' => 0,
				'endOffset' => 17,
				'content' => [
					[
						'string' => 'Some content text.',
						'path' => './div/p/text()'
					]
				],
				'hash' => '3eb8e91dc31a98b63aebe35a1229364deced3f3abbc26eb09fe67394e5cd5c0f'
			],
			$res[0]['wikispeech']['segments'][1]
		);
	}
}
