<?php

namespace MediaWiki\Extension\PageLike\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Throwable;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class PrunePageLikes extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Delete PageLike state and notification deduplication rows for missing pages or users.'
		);
		$this->addOption( 'dry-run', 'Count rows without deleting them.' );
		$this->addOption( 'batch-size', 'Maximum rows deleted per batch.', false, true );
	}

	public function execute(): void {
		$rawBatchSize = (string)$this->getOption( 'batch-size', 500 );
		if ( !ctype_digit( $rawBatchSize )
			|| (int)$rawBatchSize < 1
			|| (int)$rawBatchSize > 10000
		) {
			$this->fatalError( '--batch-size must be between 1 and 10000.' );
		}
		$batchSize = (int)$rawBatchSize;

		try {
			$likeStore = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
			$notificationStore = $this->getServiceContainer()->get(
				'PageLike.NotificationDeduplicationStore'
			);
			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output(
					'Would delete ' . $likeStore->countOrphans() . ' orphan like rows and ' .
					$notificationStore->countOrphans() .
					" orphan notification deduplication rows.\n"
				);
				return;
			}

			$likeTotal = 0;
			do {
				$deleted = $likeStore->pruneOrphansBatch( $batchSize );
				$likeTotal += $deleted;
				if ( $deleted > 0 ) {
					$this->output( "Deleted $likeTotal orphan like rows.\n" );
				}
			} while ( $deleted > 0 );
			if ( $likeTotal === 0 ) {
				$this->output( "Deleted 0 orphan like rows.\n" );
			}

			$notificationTotal = 0;
			do {
				$deleted = $notificationStore->pruneOrphansBatch( $batchSize );
				$notificationTotal += $deleted;
				if ( $deleted > 0 ) {
					$this->output(
						"Deleted $notificationTotal orphan notification deduplication rows.\n"
					);
				}
			} while ( $deleted > 0 );
			if ( $notificationTotal === 0 ) {
				$this->output( "Deleted 0 orphan notification deduplication rows.\n" );
			}
		} catch ( Throwable $exception ) {
			$this->fatalError( 'PageLike prune failed: ' . $exception->getMessage() );
		}
	}
}

$maintClass = PrunePageLikes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
