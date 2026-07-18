<?php

namespace MediaWiki\Extension\PageLike\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Throwable;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class PrunePageLikes extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete PageLike rows for missing pages or users.' );
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
			$store = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( 'Would delete ' . $store->countOrphans() . " orphan PageLike rows.\n" );
				return;
			}

			$total = 0;
			do {
				$deleted = $store->pruneOrphansBatch( $batchSize );
				$total += $deleted;
				$this->output( "Deleted $total orphan PageLike rows.\n" );
			} while ( $deleted > 0 );
		} catch ( Throwable $exception ) {
			$this->fatalError( 'PageLike prune failed: ' . $exception->getMessage() );
		}
	}
}

$maintClass = PrunePageLikes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
