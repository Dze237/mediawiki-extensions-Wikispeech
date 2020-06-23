<?php

/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Timestamp\TimestampException;

/**
 * Keeps track of utterances in persistent layers.
 *
 * Utterance metadata (i.e. segment hash, page id, language, etc) is stored in a database table.
 * Utterance audio is (synthesised voice audio) is stored as an opus file in file backend.
 * Synthesis metadata (tokens, etc) is stored as a JSON file in file backend.
 *
 * (.opus and .json suffixes are added in file backed store although this class is agnostic
 * regarding to the actual data encoding and formats.)
 *
 * @since 0.1.5
 */
class UtteranceStore {

	/** @var string Name of database table that keeps track of utterance metadata. */
	public const UTTERANCE_TABLE = "wikispeech_utterance";

	/** @var LoggerInterface */
	private $log;

	/**
	 * Don't use this directly, access @see getFileBackend
	 * @var FileBackend Used to store utterance audio and synthesis metadata.
	 */
	private $fileBackend;

	/**
	 * @var \Wikimedia\Rdbms\ILoadBalancer
	 */
	private $dbLoadBalancer;

	/** @var string Name of container (sort of path prefix) used for files in backend. */
	private $fileBackendContainerName;

	public function __construct() {
		$this->log = LoggerFactory::getInstance( 'Wikispeech' );

		$this->fileBackendContainerName = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'wikispeech' )
			->get( 'WikispeechUtteranceFileBackendContainerName' );
		if ( !$this->fileBackendContainerName ) {
			$this->fileBackendContainerName = "wikispeech_utterances";
			$this->log->info( 'Falling back on container name {containerName}', [
				'containerName' => $this->fileBackendContainerName
			] );
		}

		$this->dbLoadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
	}

	/**
	 * @since 0.1.5
	 * @return FileBackend
	 */
	private function getFileBackend() {
		if ( !$this->fileBackend ) {

			/** @var string Name of file backend group in LocalSettings.php to use. */
			$fileBackendName = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'wikispeech' )
				->get( 'WikispeechUtteranceFileBackendName' );
			if ( !$fileBackendName ) {
				$fileBackendName = 'wikispeech-utterances';
				$this->log->info( 'Falling back on file backend name {fileBackendName}', [
					'fileBackendName' => $fileBackendName
				] );
				// @todo find out if this is ok or even normal behavior
				$tmpDir = sys_get_temp_dir() . 'wikispeech_utterances';
				if ( !file_exists( $tmpDir ) ) {
					mkdir( $tmpDir );
				}
				$this->log->info(
					"No file backend named {name} defined in LocalSettings.php. "
					. "Falling back on transient FS storage in {tmpDir}.", [
						'name' => $fileBackendName,
						'tmpDir' => $tmpDir
					]
				);
				$this->fileBackend = new FSFileBackend( [
					'name' => $fileBackendName,
					'wikiId' => WikiMap::getCurrentWikiId(),
					'basePath' => $tmpDir
				] );
			} else {
				$fileBackend = MediaWikiServices::getInstance()
					->getFileBackendGroup()
					->get( $fileBackendName );
				if ( $fileBackend ) {
					$this->fileBackend = $fileBackend;
				} else {
					$this->log->error(
						"No file backend group in LocalSettings.php named {fileBackendName}. "
						. "Exceptions related to accessing files are to be expected very soon.",
						[ 'fileBackendName' => $fileBackendName ]
					);
				}
			}
		}
		return $this->fileBackend;
	}

	/**
	 * Retrieves an utterance from the database for a given segment in a page,
	 * using a specific voice and language.
	 *
	 * @since 0.1.5
	 * @param int $pageId Mediawiki page ID.
	 * @param string $language ISO-639.
	 * @param string $voice Name of synthesis voice.
	 * @param string $segmentHash Hash of segment representing utterance.
	 * @param bool $omitAudio If true, then no audio is returned.
	 * @return array|null Utterance found, or null if non-existing.
	 */
	public function findUtterance( $pageId, $language, $voice, $segmentHash, $omitAudio = false ) {
		$dbr = $this->dbLoadBalancer->getConnection( DB_REPLICA );
		$res = $dbr->select( self::UTTERANCE_TABLE, [
			'wsu_utterance_id',
			'wsu_page_id',
			'wsu_lang',
			'wsu_voice',
			'wsu_seg_hash',
			'wsu_date_stored'
		], [
			'wsu_page_id' => $pageId,
			'wsu_lang' => $language,
			'wsu_voice' => $voice,
			'wsu_seg_hash' => $segmentHash
		], __METHOD__, [
			'ORDER BY date_stored DESC',
			'LIMIT 1'
		] );
		if ( !$res ) {
			return null;
		}
		$row = $dbr->fetchObject( $res );
		if ( !$row ) {
			return null;
		}
		$utterance = [
			'utteranceId' => intval( $row->wsu_utterance_id ),
			'pageId' => intval( $row->wsu_page_id ),
			'language' => strval( $row->wsu_lang ),
			'voice' => strval( $row->wsu_voice ),
			'segmentHash' => strval( $row->wsu_seg_hash ),
			'dateStored' => MWTimestamp::getInstance( $row->wsu_date_stored )
		];
		$dbr->freeResult( $res );

		// load utterance audio and synthesis metadata

		// @todo We might want to keep this as separate function calls,
		// allowing the user to request when needed, and perhaps
		// pass a stream straight down from file backend to user
		// rather than bouncing it via RAM.
		// Not sure if this is an existing thing in PHP though.

		if ( !$omitAudio ) {
			$audioSrc = $this->audioUrlFactory( $utterance['utteranceId'] );
			$utterance['audio'] = $this->getFileBackend()->getFileContents( [
				'src' => $audioSrc
			] );
			if ( $utterance['audio'] == FileBackend::CONTENT_FAIL ) {
				$this->log->warning(
					"Inconsistency! Database contains utterance with ID {id} "
					. "that does not exist as audio file named {src} in file backend.", [
						'id' => $utterance['utteranceId'],
						'src' => $audioSrc
					]
				);
				// @todo mark system to flush inconsistencies from database
				return null;
			}
		}

		$synthesisMetadataSrc = $this->synthesisMetadataUrlFactory( $utterance['utteranceId'] );
		$utterance['synthesisMetadata'] = $this->getFileBackend()->getFileContents( [
			'src' => $synthesisMetadataSrc
		] );
		if ( $utterance['synthesisMetadata'] == FileBackend::CONTENT_FAIL ) {
			$this->log->warning(
				"Inconsistency! Database contains utterance with ID {id} "
				. "that does not exist as synthesis metadata file named {src} in file backend.", [
					'id' => $utterance['utteranceId'],
					'src' => $synthesisMetadataSrc
				]
			);
			// @todo mark system to flush inconsistencies from database
			return null;
		}
		return $utterance;
	}

	/**
	 * Creates an utterance in the database.
	 *
	 * @since 0.1.5
	 * @param int $pageId Mediawiki page ID.
	 * @param string $language ISO 639.
	 * @param string $voice Name of synthesis voice.
	 * @param string $segmentHash Hash of segment representing utterance.
	 * @param string $audio Utterance audio.
	 * @param string $synthesisMetadata JSON form metadata about the audio.
	 * @return array Inserted utterance.
	 * @throws ExternalStoreException If unable to prepare or create files in file backend.
	 */
	public function createUtterance(
		$pageId,
		$language,
		$voice,
		$segmentHash,
		$audio,
		$synthesisMetadata
	) {
		$dbw = $this->dbLoadBalancer->getConnection( DB_MASTER );
		$rows = [
			'wsu_page_id' => $pageId,
			'wsu_lang' => $language,
			'wsu_voice' => $voice,
			'wsu_seg_hash' => $segmentHash,
			'wsu_date_stored' => $dbw->timestamp()
		];
		$dbw->insert( self::UTTERANCE_TABLE, $rows );
		$utterance = [
			'pageId' => $pageId,
			'language' => $language,
			'voice' => $voice,
			'segmentHash' => $segmentHash,
			'dateStored' => $rows['wsu_date_stored']
		];
		$utterance['utteranceId'] = $dbw->insertId();

		// create audio file
		$audioUrl = $this->audioUrlFactory( $utterance['utteranceId'] );
		if ( !$this->getFileBackend()->prepare( [
			'dir' => dirname( $audioUrl ),
			'noAccess' => 1,
			'noListing' => 1
		] )->isOK() ) {
			throw new ExternalStoreException( 'Failed to prepare audio file ' . $audioUrl );
		}
		if ( !$this->getFileBackend()->create( [
			'dst' => $audioUrl,
			'content' => $audio
		] )->isOK() ) {
			throw new ExternalStoreException( 'Failed to create audio file ' . $audioUrl );
		}

		// create synthesis metadata file
		$synthesisMetadataUrl = $this->synthesisMetadataUrlFactory( $utterance['utteranceId'] );
		if ( !$this->getFileBackend()->prepare( [
			'dir' => dirname( $synthesisMetadataUrl ),
			'noAccess' => 1,
			'noListing' => 1
		] )->isOK() ) {
			throw new ExternalStoreException(
				'Failed to prepare synthesis metadata file ' . $synthesisMetadataUrl
			);
		}
		if ( !$this->getFileBackend()->create( [
			'dst' => $synthesisMetadataUrl,
			'content' => $synthesisMetadata
		] )->isOK() ) {
			throw new ExternalStoreException(
				'Failed to create synthesis metadata file ' . $synthesisMetadataUrl
			);
		}

		return $utterance;
	}

	/**
	 * Clears database of utterances older than a given age.
	 *
	 * @since 0.1.5
	 * @param MWTimestamp $expirationDate
	 * @return int Number of utterances flushed.
	 * @throws TimestampException In case of {@see TS_MW} no longer is valid, i.e. never.
	 */
	public function flushUtterancesByExpirationDate( $expirationDate ) {
		$dbw = $this->dbLoadBalancer->getConnection( DB_MASTER );
		$results = $dbw->select( self::UTTERANCE_TABLE,
			[ 'wsu_utterance_id' ],
			[ 1 => 'wsu_date_stored <= ' . $expirationDate->getTimestamp( TS_MW ) ]
		);
		return $this->flushUtterances( $dbw, $results );
	}

	/**
	 * Clears database of all utterances for a given page.
	 *
	 * @since 0.1.5
	 * @param int $pageId Mediawiki page ID.
	 * @return int Number of utterances flushed.
	 */
	public function flushUtterancesByPage( $pageId ) {
		$dbw = $this->dbLoadBalancer->getConnection( DB_MASTER );
		$results = $dbw->select( self::UTTERANCE_TABLE,
			[ 'wsu_utterance_id' ],
			[ 'wsu_page_id' => $pageId ]
		);
		return $this->flushUtterances( $dbw, $results );
	}

	/**
	 * Clears database of all utterances for a given language and voice.
	 * If no voice is set, then all voices will be removed.
	 *
	 * @since 0.1.5
	 * @param string $language ISO 639.
	 * @param string|null $voice Optional name of synthesis voice to limit flush to.
	 * @return int Number of utterances flushed.
	 */
	public function flushUtterancesByLanguageAndVoice( $language, $voice = null ) {
		$conditions = [
			'wsu_lang' => $language
		];
		if ( $voice != null ) {
			$conditions['wsu_voice'] = $voice;
		}
		$dbw = wfGetDB( DB_MASTER );
		$results = $dbw->select( self::UTTERANCE_TABLE,
			[ 'wsu_utterance_id' ], $conditions
		);
		return $this->flushUtterances( $dbw, $results );
	}

	/**
	 * Flushes utterances listed in a result set containing
	 * at least the wsu_utterance_id column.
	 *
	 * In order for return value to increase, the utterance must have been
	 * successfully deleted in all layers, i.e. utterance metadata database row,
	 * utterance audio and synthesis metadata from file store.
	 * E.g. if the utterance audio file is missing and thus not explicitally removed,
	 * but at the same time we managed to remove the utterance metadata from database
	 * and also removed the synthesis metadata file, this will not count as a
	 * successfully removed utterance. It would however be removed from all layers
	 * and it would also cause an out-of-sync warning in the log.
	 *
	 * @since 0.1.5
	 * @todo Consider if use of database should be transactional flushing.
	 * @param IDatabase $dbw Writable database connection.
	 * @param IResultWrapper $results Result set.
	 * @return int Number of utterances that were successfully flushed in all layers.
	 */
	private function flushUtterances( $dbw, $results ) {
		if ( !$results ) {
			return 0;
		}
		$successfullyFlushedCounter = 0;
		foreach ( $results as $row ) {
			$utteranceId = $row->wsu_utterance_id;

			// 1. delete in database
			$successfullyDeletedTableRow = $dbw->delete(
				self::UTTERANCE_TABLE,
				[ 'wsu_utterance_id' => $utteranceId ],
				__METHOD__
			);
			if ( !$successfullyDeletedTableRow ) {
				$this->log->warning(
					"Failed to delete utterance {utteranceId} from database.",
					[ 'utteranceId' => $utteranceId ]
				);
			} else {
				$this->log->debug(
					'Flushed out utterance with id {utteranceId} from database',
					[ 'utteranceId' => $utteranceId ]
				);
			}

			// 2. delete in file store.
			$successfullyDeletedAudioFile = $this->deleteFileBackendFile(
				$this->audioUrlFactory( $utteranceId ),
				$utteranceId,
				'audio file'
			);
			$successfullyDeletedSynthesisMetadataFile = $this->deleteFileBackendFile(
				$this->synthesisMetadataUrlFactory( $utteranceId ),
				$utteranceId,
				'synthesis metadata file'
			);

			if ( $successfullyDeletedTableRow
				&& $successfullyDeletedAudioFile
				&& $successfullyDeletedSynthesisMetadataFile ) {
				$successfullyFlushedCounter++;
			}
		}
		$dbw->freeResult( $results );
		return $successfullyFlushedCounter;
	}

	/**
	 * @since 0.1.5
	 * @param string $src
	 * @param int $utteranceId
	 * @param string $type
	 * @return bool If successfully deleted
	 */
	private function deleteFileBackendFile( $src, $utteranceId, $type ) {
		$synthesisMetadataFile = [
			'src' => $src
		];
		if ( $this->getFileBackend()->fileExists( $synthesisMetadataFile ) ) {
			if ( !$this->getFileBackend()->delete( $synthesisMetadataFile )->isOK() ) {
				$this->log->warning(
					"Unable to delete {type} for utterance with identity {utteranceId}.",
					[
						'utteranceId' => $utteranceId,
						'type' => $type
					]
				);
				return false;
			} else {
				$this->getFileBackend()->clean( [ 'dir' => $this->urlPathFactory( $utteranceId ) ] );
			}
		} else {
			$this->log->warning(
				"Attempted to delete non existing {type} for utterance {utteranceId}.",
				[
					'utteranceId' => $utteranceId,
					'type' => $type
				]
			);
			return false;
		}
		$this->log->debug( 'Flushed out file {src}', [ 'src' => $src ] );
		return true;
	}

	/**
	 * Creates a deterministic path based on utterance identity,
	 * causing no more than 1000 files and 10 subdirectories per directory.
	 * (Actually, 2000 files, as we store both .json and .opus)
	 *
	 * Overloading a directory with files often cause performance problems.
	 *
	 * 1 -> /
	 * 12 -> /
	 * 123 -> /
	 * 1234 -> /1/
	 * 12345 -> /1/2/
	 * 123456 -> /1/2/3/
	 * 1234567 -> /1/2/3/4/
	 *
	 * @since 0.1.5
	 * @param int $utteranceId
	 * @return string Path
	 */
	private function urlPathFactory( $utteranceId ) {
		$path = '/';
		$utteranceIdText = strval( $utteranceId );
		$utteranceIdTextLength = strlen( $utteranceIdText );
		for ( $index = 0; $index < $utteranceIdTextLength - 3; $index++ ) {
			$path .= substr( $utteranceIdText, $index, 1 );
			$path .= '/';
		}
		return $path;
	}

	/**
	 * @since 0.1.5
	 * @param int $utteranceId Utterance identity.
	 * @return string url used to access object in file store
	 */
	private function audioUrlPrefixFactory( $utteranceId ) {
		return $this->getFileBackend()->getContainerStoragePath( $this->fileBackendContainerName )
			. $this->urlPathFactory( $utteranceId ) . $utteranceId;
	}

	/**
	 * @since 0.1.5
	 * @param int $utteranceId Utterance identity.
	 * @return string url used to access object in file store
	 */
	private function audioUrlFactory( $utteranceId ) {
		return $this->audioUrlPrefixFactory( $utteranceId ) . '.opus';
	}

	/**
	 * @since 0.1.5
	 * @param int $utteranceId Utterance identity.
	 * @return string url used to access object in file store
	 */
	private function synthesisMetadataUrlFactory( $utteranceId ) {
		return $this->audioUrlPrefixFactory( $utteranceId ) . '.json';
	}
}
