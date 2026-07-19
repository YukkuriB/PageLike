<?php

namespace MediaWiki\Extension\PageLike\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Throwable;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class DeleteUserLikes extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Delete all PageLike state and notification deduplication rows for one numeric user ID.'
		);
		$this->addOption( 'user-id', 'Numeric local user ID.', true, true );
		$this->addOption( 'dry-run', 'Count rows without deleting them.' );
		$this->addOption( 'batch-size', 'Maximum rows deleted per batch.', false, true );
	}

	public function execute(): void {
		$rawUserId = (string)$this->getOption( 'user-id' );
		$rawBatchSize = (string)$this->getOption( 'batch-size', 500 );
		if ( !ctype_digit( $rawUserId ) || (int)$rawUserId < 1 || (int)$rawUserId > 4294967295 ) {
			$this->fatalError( '--user-id must be a decimal integer between 1 and 4294967295.' );
		}
		if ( !ctype_digit( $rawBatchSize )
			|| (int)$rawBatchSize < 1
			|| (int)$rawBatchSize > 10000
		) {
			$this->fatalError( '--batch-size must be between 1 and 10000.' );
		}
		$userId = (int)$rawUserId;
		$batchSize = (int)$rawBatchSize;

		try {
			$likeStore = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
			$notificationStore = $this->getServiceContainer()->get(
				'PageLike.NotificationDeduplicationStore'
			);
			if ( $this->hasOption( 'dry-run' ) ) {
				$likeCount = $likeStore->countForUser( $userId );
				$notificationCount = $notificationStore->countForUser( $userId );
				$this->output(
					"Would delete $likeCount like rows and $notificationCount " .
					"notification deduplication rows for user $userId.\n"
				);
				return;
			}

			$likeTotal = 0;
			do {
				$deleted = $likeStore->deleteForUserBatch( $userId, $batchSize );
				$likeTotal += $deleted;
				if ( $deleted > 0 ) {
					$this->output( "Deleted $likeTotal like rows for user $userId.\n" );
				}
			} while ( $deleted > 0 );
			if ( $likeTotal === 0 ) {
				$this->output( "Deleted 0 like rows for user $userId.\n" );
			}

			$notificationTotal = 0;
			do {
				$deleted = $notificationStore->deleteForUserBatch( $userId, $batchSize );
				$notificationTotal += $deleted;
				if ( $deleted > 0 ) {
					$this->output(
						"Deleted $notificationTotal notification deduplication rows " .
						"for user $userId.\n"
					);
				}
			} while ( $deleted > 0 );
			if ( $notificationTotal === 0 ) {
				$this->output(
					"Deleted 0 notification deduplication rows for user $userId.\n"
				);
			}
		} catch ( Throwable $exception ) {
			$this->fatalError( 'PageLike user deletion failed: ' . $exception->getMessage() );
		}
	}
}

$maintClass = DeleteUserLikes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
