<?php

namespace MediaWiki\Extension\PageLike;

use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Persists at-most-once creator-notification claims for page/user pairs.
 */
class NotificationDeduplicationStore {
	private const TABLE = 'pagelike_notification_dedupe';

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/**
	 * Atomically claim the only creator notification for this page/user pair.
	 *
	 * The claim intentionally survives an unlike and a notification failure so
	 * retries cannot turn a transient failure into a notification flood.
	 */
	public function claim( int $pageId, int $userId ): bool {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [
				'plnd_page_id' => $pageId,
				'plnd_user_id' => $userId,
			] )
			->ignore()
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() === 1;
	}

	public function deleteForPageIfTableExists( int $pageId ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		if ( !( $dbw instanceof IMaintainableDatabase )
			|| !$dbw->tableExists( self::TABLE, __METHOD__ )
		) {
			return 0;
		}
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'plnd_page_id' => $pageId ] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows();
	}

	public function countForUser( int $userId ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		return (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( [ 'plnd_user_id' => $userId ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function deleteForUserBatch( int $userId, int $batchSize ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$pageIds = $dbw->newSelectQueryBuilder()
			->select( 'plnd_page_id' )
			->from( self::TABLE )
			->where( [ 'plnd_user_id' => $userId ] )
			->limit( $batchSize )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$pageIds ) {
			return 0;
		}
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [
				'plnd_user_id' => $userId,
				'plnd_page_id' => $pageIds,
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows();
	}

	public function countOrphans(): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		return (int)$this->newOrphanQuery( $dbw )
			->select( 'COUNT(*)' )
			->fetchField();
	}

	public function pruneOrphansBatch( int $batchSize ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$rows = $this->newOrphanQuery( $dbw )
			->select( [ 'plnd.plnd_page_id', 'plnd.plnd_user_id' ] )
			->limit( $batchSize )
			->fetchResultSet();

		$conditions = [];
		foreach ( $rows as $row ) {
			$conditions[] = $dbw->andExpr( [
				'plnd_page_id' => (int)$row->plnd_page_id,
				'plnd_user_id' => (int)$row->plnd_user_id,
			] );
		}
		if ( !$conditions ) {
			return 0;
		}

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( $dbw->orExpr( $conditions ) )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows();
	}

	private function newOrphanQuery( IDatabase $dbw ): SelectQueryBuilder {
		return $dbw->newSelectQueryBuilder()
			->from( self::TABLE, 'plnd' )
			->leftJoin( 'page', 'p', 'p.page_id = plnd.plnd_page_id' )
			->leftJoin( 'user', 'u', 'u.user_id = plnd.plnd_user_id' )
			->where( 'p.page_id IS NULL OR u.user_id IS NULL' )
			->caller( __METHOD__ );
	}
}
