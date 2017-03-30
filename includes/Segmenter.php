<?php

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0+
 */

class Segmenter {

	/**
	 * Divide a cleaned content array into segments, one for each sentence.
	 *
	 * A segment is an array with the keys "content", "startOffset"
	 * and "endOffset". "content" is an array of `CleanedText`s.
	 * "startOffset" is the offset of the first character of the
	 * segment, within the text node it appears. "endOffset" is the
	 * offset of the last character of the segment, within the text
	 * node it appears. These are used to determine start and end of a
	 * segment in the original HTML.
	 *
	 * A sentence is here defined as a number of tokens ending with a
	 * dot (full stop).
	 *
	 * @since 0.0.1
	 * @param array $cleanedContents An array of `CleanedText`s, as
	 *  returned by `Cleaner::cleanHtml()`.
	 * @return array An array of segments, each containing the
	 *  `CleanedText's in that segment.
	 */

	public static function segmentSentences( $cleanedContents ) {
		$segments = [];
		$currentSegment = [
			'content' => [],
			'startOffset' => null,
			'endOffset' => null
		];
		foreach ( $cleanedContents as $content ) {
			self::addSegments(
				$segments,
				$currentSegment,
				$content
			);
		}
		if ( $currentSegment['content'] ) {
			// Add the last segment, unless it's empty.
			array_push( $segments, $currentSegment );
		}
		return $segments;
	}

	/**
	 * Add segments for a string.
	 *
	 * Looks for sentence final strings (strings which a sentence ends
	 * with). When a sentence final string is found, it's sentence is
	 * added to the $currentSegment.
	 *
	 * @since 0.0.1
	 * @param array $segments The segment array to add new segments to.
	 * @param array $currentSegment The segment under construction.
	 * @param CleanedText $text The text to segment.
	 */

	private static function addSegments(
		&$segments,
		&$currentSegment,
		$text
	) {
		$nextStartOffset = 0;
		do {
			$endOffset = self::addSegment(
				$segments,
				$currentSegment,
				$text,
				$nextStartOffset
			);
			// The earliest the next segments can start is one after
			// the end of the current one.
			$nextStartOffset = $endOffset + 1;
		} while ( $nextStartOffset < mb_strlen( $text->string ) - 1 );
	}

	/**
	 * Add a sentence, or part thereof, to a segment.
	 *
	 * Finds the next sentence by sentence final characters and adds
	 * them to the segment under construction. If no sentence final
	 * character was found, all the remaining text is added. Stores
	 * start offset when the first text of a segment is added and end
	 * offset when the last is.
	 *
	 * @since 0.0.1
	 * @param array $segments The segment array to add new segments to.
	 * @param array $currentSegment The segment under construction.
	 * @param CleanedText $text The text to segment.
	 * @param int $startOffset The offset where the next sentence can
	 *  start, at the earliest. If the sentence has leading
	 *  whitespaces, this will be moved forward.
	 * @return int The offset of the last character in the
	 *   sentence. If the sentence didn't end yet, this is the last
	 *   character of $text.
	 */

	private static function addSegment(
		&$segments,
		&$currentSegment,
		$text,
		$startOffset=0
	) {
		if ( $currentSegment['startOffset'] === null ) {
			// Move the start offset ahead by the number of leading
			// whitespaces. This means that whitespaces before or
			// between segments aren't included.
			$leadingWhitespacesLength = self::getLeadingWhitespacesLength(
				mb_substr( $text->string, $startOffset )
			);
			$startOffset += $leadingWhitespacesLength;
		}
		// Get the offset for the next sentence final character.
		$endOffset = self::getSentenceFinalOffset(
			$text->string,
			$startOffset
		);
		// If no sentence final character is found, add the rest of
		// the text and remember that this segment isn't ended.
		$ended = true;
		if ( $endOffset === null ) {
			$endOffset = mb_strlen( $text->string ) - 1;
			$ended = false;
		}
		$sentence = mb_substr(
			$text->string,
			$startOffset,
			$endOffset - $startOffset + 1
		);
		if ( $sentence !== '' ) {
			// Don't add `CleanedText`s with the empty string.
			$sentenceText = new CleanedText(
				$sentence,
				$text->path
			);
			array_push( $currentSegment['content'], $sentenceText );
			if ( $currentSegment['startOffset'] === null ) {
				// Record the start offset if this is the first text
				// added to the segment.
				$currentSegment['startOffset'] = $startOffset;
			}
			$currentSegment['endOffset'] = $endOffset;
			if ( $ended ) {
				array_push( $segments, $currentSegment );
				// Create a fresh segment to add following text to.
				$currentSegment = [
					'content' => [],
					'startOffset' => null,
					'endOffset' => null
				];
			}
		}
		return $endOffset;
	}

	/**
	 * Get the number of whitespaces at the start of a string.
	 *
	 * @since 0.0.1
	 * @param string $string The string to count leading whitespaces
	 *  for.
	 * @return int The number of whitespaces at the start of $string.
	 */

	private static function getLeadingWhitespacesLength( $string ) {
		$trimmedString = ltrim( $string );
		return mb_strlen( $string ) - mb_strlen( $trimmedString );
	}

	/**
	 * Get the offset of the first sentence final character in a string.
	 *
	 * @since 0.0.1
	 * @param string $string The string to look in.
	 * @param int $offset The offset to start looking from.
	 * @return int The offset of the first sentence final character
	 *  that was found, if any, else null.
	 */

	private static function getSentenceFinalOffset( $string, $offset ) {
		// For every potentially sentence final character after the
		// first one, we want to start looking from the character
		// after the last one we found. For the first one however, we
		// want to start looking from the character at the offset, to
		// not miss if that is a sentence final character. To only
		// have one loop for both these cases, we need to go back one
		// for the first search.
		$offset --;
		do {
			// Find the next character that may be sentence final.
			$offset = mb_strpos( $string, '.', $offset + 1 );
			if ( $offset === false ) {
				// No character that can be sentence final was found.
				return null;
			}
		} while ( !self::isSentenceFinal( $string, $offset ) );
		return $offset;
	}

	/**
	 * Test if a character is at the end of a sentence.
	 *
	 * Dots in abbreviations should only be counted when they also are sentence
	 * final. For example:
	 * "Monkeys, penguins etc.", but not "Monkeys e.g. baboons".
	 *
	 * @since 0.0.1
	 * @param string $string The string to check in.
	 * @param int $index The the index in $string of the character to check.
	 * @return bool True if the character is sentence final, else false.
	 */

	private static function isSentenceFinal( $string, $index ) {
		$character = mb_substr( $string, $index, 1 );
		$nextCharacter = null;
		if ( mb_strlen( $string ) > $index + 1 ) {
			$nextCharacter = mb_substr( $string, $index + 1, 1 );
		}
		$characterAfterNext = null;
		if ( mb_strlen( $string ) > $index + 2 ) {
			$characterAfterNext = mb_substr( $string, $index + 2, 1 );
		}
		if (
			$character == '.' &&
			( $nextCharacter == ' ' && self::isUpper( $characterAfterNext ) ||
			$nextCharacter == '' ||
			$nextCharacter == "\n" )
		) {
			// A dot is sentence final if it's followed by a space and a
			// capital letter or at the end of string or line.
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Test if a string is upper case.
	 *
	 * @since 0.0.1
	 * @param string $string The string to test.
	 * @return bool true if the entire string is upper case, else false.
	 */

	private static function isUpper( $string ) {
		return mb_strtoupper( $string ) == $string;
	}
}
