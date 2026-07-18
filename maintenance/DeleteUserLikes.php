<?php

namespace MediaWiki\Extension\PageLike\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Throwable;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class DeleteUserLikes extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete all PageLike rows for one numeric user ID.' );
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
			$store = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
			if ( $this->hasOption( 'dry-run' ) ) {
				$count = $store->countForUser( $userId );
				$this->output( "Would delete $count PageLike rows for user $userId.\n" );
				return;
			}

			$total = 0;
			do {
				$deleted = $store->deleteForUserBatch( $userId, $batchSize );
				$total += $deleted;
				$this->output( "Deleted $total PageLike rows for user $userId.\n" );
			} while ( $deleted > 0 );
		} catch ( Throwable $exception ) {
			$this->fatalError( 'PageLike user deletion failed: ' . $exception->getMessage() );
		}
	}
}

$maintClass = DeleteUserLikes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
