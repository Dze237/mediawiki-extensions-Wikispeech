<?php

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0+
 */

class HtmlGenerator {

	/**
	 * Create an HTML string for a sequence of utterances.
	 *
	 * @since 0.0.1
	 * @param array $segments Array of segments to generate utterances
	 *  from.
	 * @return string An HTML string containing the <utterance> tags,
	 *  wrapped in an <utterances> tag.
	 */

	public static function createUtterancesHtml( $segments ) {
		$dom = new DOMDocument();
		$utterancesElement = self::createUtterancesElement( $dom, $segments );
		$utterancesHtml = $dom->saveHTML( $utterancesElement );
		return $utterancesHtml;
	}

	/**
	 * Create an utterances element.
	 *
	 * The element is populated with utterance elements.
	 *
	 * @since 0.0.1
	 * @param DOMDocument $dom The DOM Document used to create the element.
	 * @param array $segments Array of segments to generate utterances
	 *  from.
	 * @return DOMElement The utterances element.
	 */

	private static function createUtterancesElement( $dom, $segments ) {
		if ( count( $segments ) ) {
			$utterancesElement = $dom->createElement( 'utterances' );
			// Hide the content of the utterance elements.
			$utterancesElement->setAttribute( 'hidden', '' );
			$index = 0;
			foreach ( $segments as $segment ) {
				$utteranceElement = self::createUtteranceElement(
					$dom,
					$segment,
					$index
				);
				$utterancesElement->appendChild( $utteranceElement );
				$index += 1;
			}
			return $utterancesElement;
		}
	}

	/**
	 * Create an utterance element.
	 *
	 * The element looks like this in HTML:
	 * <utterance id="utterance-0>
	 *   <content>
	 *     Utterance string with <cleaned-tag>not removed tag</cleaned-tag>.
	 *   </content>
	 * </utterance>
	 *
	 * The id is a zero based index, used to find the adjacent
	 * utterances, when next or previous utterance should be played.
	 *
	 * The content element contains a representation of the HTML that
	 * was used to generate this utterance. Text nodes are the same as
	 * in the original HTML. Elements are represented by cleaned-tag
	 * elements, whose contents are the tags from the original HTML,
	 * excluding < and >.
	 *
	 * @since 0.0.1
	 * @param DOMDocument $dom The DOMDocument to use for creating the
	 *  element.
	 * @param array $segment An array with position and content as an
	 *  array of CleanedTags and strings.
	 * @param int $index The index of the element, used for giving it
	 *  an id. Later used for playing the utterances in the correct
	 *  order.
	 * @return DOMElement The resulting utterance element.
	 */

	private static function createUtteranceElement( $dom, $segment, $index ) {
		$utteranceElement = $dom->createElement( 'utterance' );
		$utteranceElement->setAttribute( 'id', "utterance-$index" );
		$utteranceElement->setAttribute(
			'start-offset',
			$segment['startOffset']
		);
		$utteranceElement->setAttribute(
			'end-offset',
			$segment['endOffset']
		);
		$contentElement = self::createContentElement(
			$dom,
			$segment['content']
		);
		$utteranceElement->appendChild( $contentElement );
		return $utteranceElement;
	}

	/**
	 * Create a content element from a content array.
	 *
	 * CleanedTags are represented as cleaned-tag elements, strings as
	 * text nodes.
	 *
	 * @since 0.0.1
	 * @param array $content An array of CleanedTags and strings.
	 * @return DOMNode A content element.
	 */

	private static function createContentElement( $dom, $content ) {
		$contentElement = $dom->createElement( 'content' );
		foreach ( $content as $part ) {
			if ( $part instanceof CleanedTag ) {
				// Remove the < and > from the tag string to not have to
				// decode them later.
				$text = substr( $part->string, 1, -1 );
				$cleanedTagElement = $dom->createElement( 'cleaned-tag', $text );
				$contentElement->appendChild( $cleanedTagElement );
			} else {
				$contentElement->appendChild( $part->toElement( $dom ) );
			}
		}
		return $contentElement;
	}
}
