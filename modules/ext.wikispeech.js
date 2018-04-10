( function ( mw, $ ) {

	/**
	 * Main class for the Wikipseech extension.
	 *
	 * Handles setup of various components and general functionality.
	 *
	 * @class ext.wikispeech.Wikispeech
	 * @constructor
	 */

	function Wikispeech() {
		var self, currentUtterance;

		self = this;
		currentUtterance = null;
		self.utterances = [];
		self.playingSelection = false;

		/**
		 * Check if Wikispeech is enabled for the current namespace.
		 */

		this.enabledForNamespace = function () {
			var validNamespaces, namespace;
			validNamespaces = mw.config.get( 'wgWikispeechNamespaces' );
			namespace = mw.config.get( 'wgNamespaceNumber' );
			return validNamespaces.indexOf( namespace ) >= 0;
		};

		/**
		 * Load all utterances.
		 *
		 * Uses the API to get the segments of the text.
		 */

		this.loadUtterances = function () {
			var api, page;

			api = new mw.Api();
			page = mw.config.get( 'wgPageName' );
			api.post(
				{
					action: 'wikispeech',
					page: [ page ],
					output: 'segments'
				},
				{
					beforeSend: function ( jqXHR, settings ) {
						mw.log(
							'Requesting segments:', settings.url + '?' +
								settings.data
						);
					}
				}
			).done( function ( data ) {
				var utterance, i;

				mw.log( 'Segments received:', data );
				self.utterances = data.wikispeech.segments;
				for ( i = 0; i < self.utterances.length; i++ ) {
					utterance = self.utterances[ i ];
					utterance.audio = $( '<audio></audio>' ).get( 0 );
				}
				self.prepareUtterance( self.utterances[ 0 ] );
			} );
		};

		/**
		 * Add a panel with controls for for Wikispeech.
		 *
		 * The panel contains buttons for controlling playback and
		 * links to related pages.
		 */

		this.addControlPanel = function () {
			$( '<div></div>' )
				.attr( 'id', 'ext-wikispeech-control-panel' )
				.addClass( 'ext-wikispeech-control-panel' )
				.appendTo( '#content' );
			self.addButton(
				'ext-wikispeech-skip-back-sentence',
				self.skipBackUtterance
			);
			self.addButton(
				'ext-wikispeech-skip-back-word',
				self.skipBackToken
			);
			self.addButton(
				'ext-wikispeech-play-stop-button',
				self.playOrStop
			);
			self.addButton(
				'ext-wikispeech-skip-ahead-word',
				self.skipAheadToken
			);
			self.addButton(
				'ext-wikispeech-skip-ahead-sentence',
				self.skipAheadUtterance
			);
			self.addLinkButton(
				'ext-wikispeech-help',
				'wgWikispeechHelpPage'
			);
			self.addLinkButton(
				'ext-wikispeech-feedback',
				'wgWikispeechFeedbackPage'
			);
			if (
				$( '.ext-wikispeech-help, ext-wikispeech-feedback' ).length
			) {
				// Add divider if there are any non-control buttons.
				$( '<span></span>' )
					.addClass( 'ext-wikispeech-divider' )
					.insertBefore(
						$( '.ext-wikispeech-help, ext-wikispeech-feedback' )
							.first()
					);
			}
		};

		/**
		* Add a control button.
		*
		* @param {string} cssClass The name of the CSS class to add to
		*  the button.
		* @param {string} onClickFunction The name of the function to
		*  call when the button is clicked.
		*/

		this.addButton = function ( cssClass, onClickFunction ) {
			var $button = $( '<button></button>' )
				.addClass( cssClass )
				.appendTo( '#ext-wikispeech-control-panel' );
			$button.click( onClickFunction );
			return $button;
		};

		/**
		 * Add a stack which contains the buffering icon to `playStopButton`s.
		 */

		this.addStackToPlayStopButton = function () {
			this.addSpanToPlayStopButton();
			this.addElementToPlayStopButtonStack(
				'ext-wikispeech-play-stop ext-wikispeech-play fa-stack-2x'
			);
			this.addElementToPlayStopButtonStack(
				'ext-wikispeech-buffering-icon fa-stack-2x fa-spin'
			);
			$( '.ext-wikispeech-play-stop-stack' ).css( 'font-size', '50%' );
			$( '.ext-wikispeech-buffering-icon' ).css( 'visibility', 'hidden' );
		};

		/**
		 * Add a Font Awesome stack to the play button.
		 */

		this.addSpanToPlayStopButton = function () {
			$( '<span></span>' )
				.addClass( 'ext-wikispeech-play-stop-stack fa-stack fa-lg' )
				.appendTo( '.ext-wikispeech-play-stop-button' );
		};

		/**
		 * Add an element to the stack on the playStop button.
		 *
		 * @param {string} cssClass The name of the CSS class to add
		 *  the item.
		 */

		this.addElementToPlayStopButtonStack = function ( cssClass ) {
			$( '<i></i>' )
				.addClass( 'fa ' + cssClass )
				.appendTo( '.ext-wikispeech-play-stop-stack' );
		};

		/**
		* Add a button that takes the user to another page.
		*
		* The button gets the link destination from a supplied
		* config variable. If the variable isn't specified, the button
		* isn't added.
		*
		* @param {string} cssClass The name of the CSS class to add to
		*  the button.
		* @param {string} configVariable The config variable to get
		*  link destination from.
		*/

		this.addLinkButton = function ( cssClass, configVariable ) {
			var page, pagePath;

			page = mw.config.get( configVariable );
			if ( page ) {
				pagePath = mw.config.get( 'wgArticlePath' )
					.replace( '$1', page );
				$( '<a></a>' )
					.attr( 'href', pagePath )
					.append(
						$( '<button></button>' )
							.addClass( cssClass )
					)
					.appendTo( '#ext-wikispeech-control-panel' );
			}
		};

		/**
		 * Play or stop, depending on whether an utterance is playing.
		 */

		this.playOrStop = function () {
			if ( self.isPlaying() ) {
				self.stop();
			} else {
				self.play();
			}
		};

		/**
		 * Test if there currently is an utterance playing
		 *
		 * @return {boolean} true if there is an utterance playing,
		 *  else false.
		 */

		this.isPlaying = function () {
			return currentUtterance !== null;
		};

		/**
		 * Stop playing the utterance currently playing.
		 */

		this.stop = function () {
			var $playStopButton;

			if ( self.isPlaying() ) {
				self.stopUtterance( currentUtterance );
			}
			currentUtterance = null;
			$( '.ext-wikispeech-buffering-icon' ).css( 'visibility', 'hidden' );
			$playStopButton = $( '.ext-wikispeech-play-stop' );
			$playStopButton.removeClass( 'ext-wikispeech-stop' );
			$playStopButton.addClass( 'ext-wikispeech-play' );
			self.playingSelection = false;
		};

		/**
		 * Start playing the first utterance or selected text, if any.
		 */

		this.play = function () {
			var $playStopButton;

			if ( !mw.wikispeech.selectionPlayer.playSelectionIfValid() ) {
				self.playUtterance( self.utterances[ 0 ] );
			}
			$playStopButton = $( '.ext-wikispeech-play-stop' );
			$playStopButton.removeClass( 'ext-wikispeech-play' );
			$playStopButton.addClass( 'ext-wikispeech-stop' );
		};

		/**
		 * Play the audio for an utterance.
		 *
		 * This also stops any currently playing utterance.
		 *
		 * @param {Object} utterance The utterance to play the audio
		 *  for.
		 */

		this.playUtterance = function ( utterance ) {
			if ( self.isPlaying() ) {
				self.stopUtterance( currentUtterance );
			}
			currentUtterance = utterance;
			if ( !self.playingSelection ) {
				mw.wikispeech.highlighter.highlightUtterance( utterance );
			}
			utterance.audio.play();
			if ( self.audioIsReady( utterance.audio ) ) {
				$( '.ext-wikispeech-buffering-icon' ).css( 'visibility', 'hidden' );
			} else {
				self.addCanPlayListener( $( utterance.audio ) );
				$( '.ext-wikispeech-buffering-icon' ).css( 'visibility', 'visible' );
			}
		};

		/**
		 * Check if the current audio is ready to play.
		 *
		 * The audio is deemed ready to play as soon as any playable
		 * data is available.
		 *
		 * @param {HTMLElement} audio The audio element to test.
		 * @return {boolean} True if the audio is ready to play else false.
		 */

		this.audioIsReady = function ( audio ) {
			return audio.readyState >= 2;
		};

		/**
		 * Add canplay listener for the audio to hide buffering icon.
		 *
		 * Canplaythrough will be caught implicitly as it occurs after
		 * canplay.
		 *
		 * @param {jQuery} $audioElement Audio element to which the
		 *  listener is added.
		 */

		this.addCanPlayListener = function ( $audioElement ) {
			$audioElement.on( 'canplay', function () {
				$( '.ext-wikispeech-buffering-icon' ).css( 'visibility', 'hidden' );
			} );
		};

		/**
		 * Stop and rewind the audio for an utterance.
		 *
		 * @param {Object} utterance The utterance to stop the audio
		 *  for.
		 */

		this.stopUtterance = function ( utterance ) {
			utterance.audio.pause();
			// Rewind audio for next time it plays.
			utterance.audio.currentTime = 0.0;
			$( utterance.audio ).off( 'canplay' );
			window.clearTimeout( utterance.stopTimeout );
			utterance.stopTimeout = null;
			// Remove sentence highlighting.
			mw.wikispeech.highlighter.removeWrappers(
				'.ext-wikispeech-highlight-sentence'
			);
			// Remove word highlighting.
			mw.wikispeech.highlighter.removeWrappers(
				'.ext-wikispeech-highlight-word'
			);
			mw.wikispeech.highlighter.clearHighlightTokenTimer();
		};

		/**
		 * Skip to the next utterance.
		 *
		 * Stop the current utterance and start playing the next one.
		 */

		this.skipAheadUtterance = function () {
			var nextUtterance = self.getNextUtterance( currentUtterance );
			if ( nextUtterance ) {
				self.playUtterance( nextUtterance );
			} else {
				self.stop();
			}
		};

		/**
		 * Get the utterance after the given utterance.
		 *
		 * @param {Object} utterance The original utterance.
		 * @return {Object} The utterance after the original
		 *  utterance. null if utterance is the last one.
		 */

		this.getNextUtterance = function ( utterance ) {
			return self.getUtteranceByOffset( utterance, 1 );
		};

		/**
		 * Get the utterance by offset from another utterance.
		 *
		 * @param {Object} utterance The original utterance.
		 * @param {number} offset The difference, in index, to the
		 *  wanted utterance. Can be negative for preceding
		 *  utterances.
		 * @return {Object} The utterance on the position before or
		 *  after the original utterance, as specified by
		 *  `offset`. null if the original utterance is null.
		 */

		this.getUtteranceByOffset = function ( utterance, offset ) {
			var index;

			if ( utterance === null ) {
				return null;
			}
			index = self.utterances.indexOf( utterance );
			return self.utterances[ index + offset ];
		};

		/**
		 * Skip to the previous utterance.
		 *
		 * Stop the current utterance and start playing the previous
		 * one. If the first utterance is playing, restart it.
		 */

		this.skipBackUtterance = function () {
			var previousUtterance, rewindThreshold, time;

			previousUtterance =
				self.getPreviousUtterance( currentUtterance );
			if ( previousUtterance ) {
				// Only consider skipping back to previous if the
				// current utterance isn't the first one.
				rewindThreshold = mw.config.get(
					'wgWikispeechSkipBackRewindsThreshold'
				);
				time = currentUtterance.audio.currentTime;
				if ( time > rewindThreshold ) {
					currentUtterance.audio.currentTime = 0.0;
				} else {
					self.playUtterance( previousUtterance );
				}
			} else if ( self.isPlaying() ) {
				// Always skip to start of utterance if the current
				// utterance is the first.
				self.play();
			}
		};

		/**
		 * Get the utterance before the given utterance.
		 *
		 * @param {Object} utterance The original utterance.
		 * @return {Object} The utterance before the original
		 *  utterance. null if the original utterance is the
		 *  first one.
		 */

		this.getPreviousUtterance = function ( utterance ) {
			return self.getUtteranceByOffset( utterance, -1 );
		};

		/**
		 * Skip to the next token.
		 *
		 * If there are no more tokens in the current utterance, skip
		 * to the next utterance.
		 */

		this.skipAheadToken = function () {
			var nextToken;

			if ( self.isPlaying() ) {
				nextToken = self.getNextToken( self.getCurrentToken() );
				if ( nextToken === null ) {
					self.skipAheadUtterance();
				} else {
					currentUtterance.audio.currentTime = nextToken.startTime;
					mw.wikispeech.highlighter.startTokenHighlighting(
						nextToken
					);
				}
			}
		};

		/**
		 * Get the token following a given token.
		 *
		 * @param {Object} originalToken Find the next token after
		 *  this one.
		 * @return {Object} The first token following originalToken
		 *  that has time greater than zero and a transcription. null
		 *  if no such token is found. Will not look beyond
		 *  originalToken's utterance.
		 */

		this.getNextToken = function ( originalToken ) {
			var index, succeedingTokens;

			index = originalToken.utterance.tokens.indexOf( originalToken );
			succeedingTokens =
				originalToken.utterance.tokens.slice( index + 1 ).filter(
					function ( token ) {
						return !self.isSilent( token );
					} );
			if ( succeedingTokens.length === 0 ) {
				return null;
			} else {
				return succeedingTokens[ 0 ];
			}
		};

		/**
		 * Get the token being played.
		 *
		 * @return {Object} The token being played.
		 */

		this.getCurrentToken = function () {
			var tokens, currentTime, currentToken, tokensWithDuration,
				duration, lastTokenWithDuration;

			currentToken = null;
			tokens = currentUtterance.tokens;
			currentTime = currentUtterance.audio.currentTime;
			tokensWithDuration = tokens.filter( function ( token ) {
				duration = token.endTime - token.startTime;
				return duration > 0.0;
			} );
			lastTokenWithDuration =
				mw.wikispeech.util.getLast( tokensWithDuration );
			if ( currentTime === lastTokenWithDuration.endTime ) {
				// If the current time is equal to the end time of the
				// last token, the last token is the current.
				currentToken = lastTokenWithDuration;
			} else {
				currentToken = tokensWithDuration.find( function ( token ) {
					return token.startTime <= currentTime &&
						token.endTime > currentTime;
				} );
			}
			return currentToken;
		};

		/**
		 * Test if a token is silent.
		 *
		 * Silent is here defined as either having no transcription
		 * (i.e. the empty string) or having no duration (i.e. start
		 * and end time is the same.)
		 *
		 * @param {Object} token The token to test.
		 * @return {boolean} true if the token is silent, else false.
		 */

		this.isSilent = function ( token ) {
			return token.startTime === token.endTime ||
				token.string === '';
		};

		/**
		 * Skip to the previous token.
		 *
		 * If there are no preceding tokens, skip to the last token of
		 * the previous utterance.
		 */

		this.skipBackToken = function () {
			var previousToken;

			if ( self.isPlaying() ) {
				previousToken =
					self.getPreviousToken( self.getCurrentToken() );
				if ( previousToken === null ) {
					self.skipBackUtterance();
					previousToken = self.getLastToken( currentUtterance );
				}
				currentUtterance.audio.currentTime = previousToken.startTime;
				mw.wikispeech.highlighter.startTokenHighlighting(
					previousToken
				);
			}
		};

		/**
		 * Get the token preceding a given token.
		 *
		 * @param {Object} originalToken Find the token before this one.
		 * @return {Object} The first token following originalToken
		 *  that has time greater than zero and a transcription. null
		 *  if no such token is found. Will not look beyond
		 *  originalToken's utterance.
		 */

		this.getPreviousToken = function ( originalToken ) {
			var index, precedingTokens, previousToken;

			index = originalToken.utterance.tokens.indexOf( originalToken );
			precedingTokens =
				originalToken.utterance.tokens.slice( 0, index ).filter(
					function ( token ) {
						return !self.isSilent( token );
					} );
			if ( precedingTokens.length === 0 ) {
				return null;
			} else {
				previousToken = mw.wikispeech.util.getLast( precedingTokens );
				return previousToken;
			}
		};

		/**
		 * Get the last token from an utterance.
		 *
		 * @param {Object} utterance The utterance to get the last
		 *  token from.
		 * @return {Object} The last token from the utterance.
		 */

		this.getLastToken = function ( utterance ) {
			var nonSilentTokens, lastToken;

			nonSilentTokens = utterance.tokens.filter( function ( token ) {
				return !self.isSilent( token );
			} );
			lastToken = mw.wikispeech.util.getLast( nonSilentTokens );
			return lastToken;
		};

		/**
		 * Register listeners for keyboard shortcuts.
		 */

		this.addKeyboardShortcuts = function () {
			var shortcuts, name, shortcut;

			shortcuts = mw.config.get( 'wgWikispeechKeyboardShortcuts' );
			$( document ).keydown( function ( event ) {
				if ( self.eventMatchShortcut( event, shortcuts.playStop ) ) {
					self.playOrStop();
					return false;
				} else if ( self.eventMatchShortcut(
					event,
					shortcuts.skipAheadSentence )
				) {
					self.skipAheadUtterance();
					return false;
				} else if ( self.eventMatchShortcut(
					event,
					shortcuts.skipBackSentence )
				) {
					self.skipBackUtterance();
					return false;
				} else if (
					self.eventMatchShortcut( event, shortcuts.skipAheadWord )
				) {
					self.skipAheadToken();
					return false;
				} else if (
					self.eventMatchShortcut( event, shortcuts.skipBackWord )
				) {
					self.skipBackToken();
					return false;
				}
			} );
			// Prevent keyup events from triggering if there is
			// keydown event for the same key combination. This caused
			// buttons in focus to trigger if a shortcut had space as
			// key.
			$( document ).keyup( function ( event ) {
				for ( name in shortcuts ) {
					shortcut = shortcuts[ name ];
					if ( self.eventMatchShortcut( event, shortcut ) ) {
						event.preventDefault();
					}
				}
			} );
		};

		/**
		 * Check if a keydown event matches a shortcut from the
		 * configuration.
		 *
		 * Compare the key and modifier state (of ctrl, alt and shift)
		 * for an event, to those of a shortcut from the
		 * configuration.
		 *
		 * @param {Event} event The event to compare.
		 * @param {Object} shortcut The shortcut object from the
		 *  config to compare to.
		 * @return {boolean} true if key and all the modifiers match
		 *  with the shortcut, else false.
		 */

		this.eventMatchShortcut = function ( event, shortcut ) {
			return event.which === shortcut.key &&
				event.ctrlKey === shortcut.modifiers.indexOf( 'ctrl' ) >= 0 &&
				event.altKey === shortcut.modifiers.indexOf( 'alt' ) >= 0 &&
				event.shiftKey === shortcut.modifiers.indexOf( 'shift' ) >= 0;
		};

		/**
		 * Prepare an utterance for playback.
		 *
		 * Audio for the utterance is requested from the TTS server
		 * and event listeners are added. When an utterance starts
		 * playing, the next one is prepared, and when an utterance is
		 * done, the next utterance is played. This is meant to be a
		 * balance between not having to pause between utterance and
		 * not requesting more than needed.

		 * @param {Object} utterance The utterance to prepare.
		 * @param {Function} callback A function to call when the
		 *  utterance is ready to play. Fires immediately if the
		 *  utterance has already been prepared.
		 */

		this.prepareUtterance = function ( utterance, callback ) {
			var $audio, nextUtterance;

			$audio = $( utterance.audio );
			if ( $audio.attr( 'src' ) ) {
				if ( callback ) {
					// Audio already loaded, call callback, if any.
					callback();
				}
			} else if ( utterance.request ) {
				// Request is ongoing, add callback to fire when it's
				// done.
				utterance.request.done( callback );
			} else {
				// Only load audio for an utterance if it isn't
				// already loaded or waiting for response from server.
				self.loadAudio( utterance );
				utterance.request.done( callback );
				nextUtterance = self.getNextUtterance( utterance );
				$audio.on( {
					playing: function () {
						var firstToken;

						// Highlight token only when the audio starts
						// playing, since we need the token info from
						// the response to know what to highlight.
						if (
							!self.playingSelection &&
								$audio.prop( 'currentTime' ) === 0
						) {
							firstToken = utterance.tokens[ 0 ];
							mw.wikispeech.highlighter.startTokenHighlighting(
								firstToken
							);
						}
					}
				} );
				if ( nextUtterance ) {
					$audio.on( {
						play: function () {
							self.prepareUtterance( nextUtterance );
						},
						ended: function () {
							self.skipAheadUtterance();
						}
					} );
				} else {
					// For last utterance, just stop the playback when
					// done.
					$audio.on( 'ended', function () {
						self.stop();
					} );
				}
			}
		};

		/**
		 * Request audio for an utterance.
		 *
		 * Adds audio and tokens when the response is received.
		 *
		 * @param {Object} utterance The utterance to load audio for.
		 */

		this.loadAudio = function ( utterance ) {
			var text, audioUrl, utteranceIndex;

			mw.log(
				'Loading audio for: [' + self.utterances.indexOf( utterance ) + ']',
				utterance
			);
			text = '';
			utterance.content.forEach( function ( item ) {
				text += item.string;
			} );
			utterance.request = self.requestTts( text );
			// Remove request on success or failure, to allow new
			// requests if this one fails.
			utterance.request.always( function () {
				utterance.request = null;
			} );
			utterance.request.done( function ( response ) {
				audioUrl = response.audio;
				utteranceIndex = self.utterances.indexOf( utterance );
				mw.log(
					'Setting audio url for: [' + utteranceIndex + ']',
					utterance, '=', audioUrl
				);
				utterance.audio.setAttribute( 'src', audioUrl );
				self.addTokens( utterance, response.tokens );
			} );
		};

		/**
		 * Send a request to the TTS server.
		 *
		 * The request should specify the following parameters:
		 * - lang: the language used by the synthesizer.
		 * - input_type: "ssml" if you want SSML markup, otherwise
		 *  "text" for plain text.
		 * - input: the text to be synthesized.
		 * For more on the parameters, see:
		 * https://github.com/stts-se/wikispeech_mockup/wiki/api.
		 *
		 * @param {string} text The utterance string to send in the
		 *  request.
		 */

		this.requestTts = function ( text ) {
			var serverUrl, language, voiceKey, voice, data, request;

			serverUrl = mw.config.get( 'wgWikispeechServerUrl' );
			language = mw.config.get( 'wgPageContentLanguage' );
			// Capitalize first letter in language code.
			voiceKey = 'wikispeechVoice' +
				language[ 0 ].toUpperCase() +
				language.slice( 1 );
			voice = mw.user.options.get( voiceKey );
			data = {
				lang: language,
				// eslint-disable-next-line camelcase
				input_type: 'text',
				input: text
			};
			if ( voice !== '' ) {
				// Set voice if not default.
				data.voice = voice;
			}
			request = $.ajax( {
				url: serverUrl,
				method: 'POST',
				data: data,
				dataType: 'json',
				beforeSend: function ( jqXHR, settings ) {
					mw.log(
						'Sending TTS request: ' + settings.url + '?' +
							settings.data
					);
				}
			} )
				.done( function ( data ) {
					mw.log( 'Response received:', data );
				} )
				.fail( function ( jqXHR, textStatus ) {
					mw.log.warn(
						'Request failed, error type "' + textStatus + '":',
						this.url + '?' + this.data
					);
				} );
			return request;
		};

		/**
		 * Add tokens to an utterance.
		 *
		 * @param {Object} utterance The utterance to add tokens to.
		 * @param {Object[]} responseTokens Tokens from a server response,
		 *  where each token is an object. For these objects, the
		 *  property "orth" is the string used by the TTS to generate
		 *  audio for the token.
		 */

		this.addTokens = function ( utterance, responseTokens ) {
			var i, token, startTime, searchOffset, responseToken;

			utterance.tokens = [];
			searchOffset = 0;
			for ( i = 0; i < responseTokens.length; i++ ) {
				responseToken = responseTokens[ i ];
				if ( i === 0 ) {
					// The first token in an utterance always start on
					// time zero.
					startTime = 0.0;
				} else {
					// Since the response only contains end times for
					// token, the start time for a token is set to the
					// end time of the previous one.
					startTime = responseTokens[ i - 1 ].endtime;
				}
				token = {
					string: responseToken.orth,
					startTime: startTime,
					endTime: responseToken.endtime,
					utterance: utterance
				};
				utterance.tokens.push( token );
				if ( i > 0 ) {
					// Start looking for the next token after the
					// previous one, except for the first token, where
					// we want to start on zero.
					searchOffset += 1;
				}
				searchOffset = self.addOffsetsAndItems(
					token,
					searchOffset
				);
			}
		};

		/**
		 * Add properties for offsets and items to a token.
		 *
		 * The offsets are for the start and end of the token in the
		 * text node which they appear. These text nodes are not
		 * necessary the same.
		 *
		 * The items store information used to get the text nodes in
		 * which the token starts, ends and any text nodes in between.
		 *
		 * @param {Object} token The token to add properties to.
		 * @param {number} searchOffset The offset to start searching
		 *  from, in the concatenated string.
		 * @return {number} The end offset in the concatenated string.
		 */

		this.addOffsetsAndItems = function (
			token,
			searchOffset
		) {
			var startOffsetInUtteranceString,
				endOffsetInUtteranceString, endOffsetForItem,
				firstItemIndex, itemsBeforeStart, lastItemIndex,
				itemsBeforeEnd, items, itemsBeforeStartLength,
				itemsBeforeEndLength, utterance;

			utterance = token.utterance;
			items = [];
			startOffsetInUtteranceString =
				self.getStartOffsetInUtteranceString(
					token.string,
					utterance.content,
					items,
					searchOffset
				);
			endOffsetInUtteranceString =
				startOffsetInUtteranceString +
				token.string.length - 1;

			// `items` now contains all the items in the utterance,
			// from the first one to the last, that contains at least
			// part of the token. To get only the ones that contain
			// part of the token, the items that appear before the
			// token are removed.
			endOffsetForItem = 0;
			items =
				items.filter( function ( item ) {
					endOffsetForItem += item.string.length;
					return endOffsetForItem >
						startOffsetInUtteranceString;
				} );
			token.items = items;

			// Calculate start and end offset for the token, in the
			// text nodes it appears in, and add them to the
			// token.
			firstItemIndex =
				utterance.content.indexOf( items[ 0 ] );
			itemsBeforeStart =
				utterance.content.slice( 0, firstItemIndex );
			itemsBeforeStartLength = 0;
			itemsBeforeStart.forEach( function ( item ) {
				itemsBeforeStartLength += item.string.length;
			} );
			token.startOffset =
				startOffsetInUtteranceString -
				itemsBeforeStartLength;
			if ( token.items[ 0 ] === utterance.content[ 0 ] ) {
				token.startOffset += utterance.startOffset;
			}
			lastItemIndex =
				utterance.content.indexOf(
					mw.wikispeech.util.getLast( items )
				);
			itemsBeforeEnd = utterance.content.slice( 0, lastItemIndex );
			itemsBeforeEndLength = 0;
			itemsBeforeEnd.forEach( function ( item ) {
				itemsBeforeEndLength += item.string.length;
			} );
			token.endOffset =
				endOffsetInUtteranceString - itemsBeforeEndLength;
			if (
				mw.wikispeech.util.getLast( token.items ) ===
					utterance.content[ 0 ]
			) {
				token.endOffset += utterance.startOffset;
			}
			return endOffsetInUtteranceString;
		};

		/**
		 * Calculate the start offset of a token in the utterance string.
		 *
		 * The token is the first match found, starting at
		 * searchOffset.
		 *
		 * @param {string} token The token to search for.
		 * @param {Object[]} content The content of the utterance where
		 *  the token appear.
		 * @param {Object[]} items An array of items to which each
		 *  item, up to and including the last one that contains
		 *  part of the token, is added.
		 * @param {number} searchOffset Where we want to start looking
		 *  for the token in the utterance string.
		 * @return {number} The offset where the first character of
		 *  the token appears in the utterance string.
		 */

		this.getStartOffsetInUtteranceString = function (
			token,
			content,
			items,
			searchOffset
		) {
			var concatenatedText, startOffsetInUtteranceString;

			// The concatenation of the strings from items. Used to
			// find tokens that span multiple text nodes.
			concatenatedText = '';
			$.each( content, function () {
				// Look through the items until we find a
				// substring matching the token.
				concatenatedText += this.string;
				items.push( this );
				if ( searchOffset > concatenatedText.length ) {
					// Don't look in text elements that end before
					// where we start looking.
					return;
				}
				startOffsetInUtteranceString = concatenatedText.indexOf(
					token, searchOffset
				);
				if ( startOffsetInUtteranceString >= 0 ) {
					return false;
				}
			} );
			return startOffsetInUtteranceString;
		};
	}

	mw.wikispeech = {};
	mw.wikispeech.Wikispeech = Wikispeech;
	mw.wikispeech.wikispeech = new mw.wikispeech.Wikispeech();

	mw.loader.using( [ 'mediawiki.api', 'ext.wikispeech' ] ).done(
		function () {
			if ( mw.wikispeech.wikispeech.enabledForNamespace() ) {
				mw.wikispeech.wikispeech.loadUtterances();
				// Prepare the first utterance for playback.
				mw.wikispeech.wikispeech.addControlPanel();
				mw.wikispeech.selectionPlayer.addSelectionPlayer();
				mw.wikispeech.wikispeech.addStackToPlayStopButton();
				mw.wikispeech.wikispeech.addKeyboardShortcuts();
			}
		}
	);
}( mediaWiki, jQuery ) );
