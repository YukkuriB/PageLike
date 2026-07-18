<?php

namespace MediaWiki\Extension\PageLike;

use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Owns reads and writes for the single PageLike business table.
 */
class LikeStore {
	private const TABLE = 'pagelike_like';

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly PageLikePolicy $policy
	) {
	}

	/**
	 * Read counts and the current named user's state in two batch queries.
	 *
	 * @param int[] $pageIds
	 * @return array<int,array{liked:bool,count:int}>
	 */
	public function getStates( array $pageIds, ?int $userId = null, bool $usePrimary = false ): array {
		$pageIds = array_values( array_unique( array_filter(
			array_map( 'intval', $pageIds ),
			static fn ( int $pageId ): bool => $pageId > 0
		) ) );
		$states = [];
		foreach ( $pageIds as $pageId ) {
			$states[$pageId] = [ 'liked' => false, 'count' => 0 ];
		}
		if ( !$pageIds ) {
			return $states;
		}

		$db = $usePrimary
			? $this->connectionProvider->getPrimaryDatabase()
			: $this->connectionProvider->getReplicaDatabase();
		$rows = $db->newSelectQueryBuilder()
			->select( [
				'page_id' => 'pll_page_id',
				'like_count' => 'COUNT(*)',
			] )
			->from( self::TABLE )
			->where( [ 'pll_page_id' => $pageIds ] )
			->groupBy( 'pll_page_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $rows as $row ) {
			$pageId = (int)$row->page_id;
			$states[$pageId]['count'] = (int)$row->like_count;
		}

		if ( $userId !== null && $userId > 0 ) {
			$likedPageIds = $db->newSelectQueryBuilder()
				->select( 'pll_page_id' )
				->from( self::TABLE )
				->where( [
					'pll_page_id' => $pageIds,
					'pll_user_id' => $userId,
				] )
				->caller( __METHOD__ )
				->fetchFieldValues();
			foreach ( $likedPageIds as $pageId ) {
				$states[(int)$pageId]['liked'] = true;
			}
		}

		return $states;
	}

	/**
	 * Set an explicit state and return primary-authoritative state and count.
	 * Null means the page disappeared or became ineligible during the request.
	 *
	 * @return array{liked:bool,count:int}|null
	 */
	public function setState( int $pageId, int $userId, bool $liked ): ?array {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$method = __METHOD__;

		return $dbw->doAtomicSection(
			$method,
			function ( IDatabase $dbw ) use ( $pageId, $userId, $liked, $method ): ?array {
				// Lock order is always page, then pagelike_like.
				$page = $dbw->newSelectQueryBuilder()
					->select( [ 'page_id', 'page_namespace' ] )
					->from( 'page' )
					->where( [ 'page_id' => $pageId ] )
					->forUpdate()
					->caller( $method )
					->fetchRow();
				if ( !$page || !$this->policy->isPageAllowedOnConnection(
					$dbw,
					$pageId,
					(int)$page->page_namespace
				) ) {
					return null;
				}

				if ( $liked ) {
					$dbw->newInsertQueryBuilder()
						->insertInto( self::TABLE )
						->row( [
							'pll_page_id' => $pageId,
							'pll_user_id' => $userId,
							'pll_liked_at' => MWTimestamp::getInstance()->getTimestamp( TS_MW ),
						] )
						->ignore()
						->caller( $method )
						->execute();
				} else {
					$dbw->newDeleteQueryBuilder()
						->deleteFrom( self::TABLE )
						->where( [
							'pll_page_id' => $pageId,
							'pll_user_id' => $userId,
						] )
						->caller( $method )
						->execute();
				}

				$count = (int)$dbw->newSelectQueryBuilder()
					->select( 'COUNT(*)' )
					->from( self::TABLE )
					->where( [ 'pll_page_id' => $pageId ] )
					->caller( $method )
					->fetchField();
				$storedPageId = $dbw->newSelectQueryBuilder()
					->select( 'pll_page_id' )
					->from( self::TABLE )
					->where( [
						'pll_page_id' => $pageId,
						'pll_user_id' => $userId,
					] )
					->limit( 1 )
					->caller( $method )
					->fetchField();

				return [
					'liked' => $storedPageId !== false,
					'count' => $count,
				];
			}
		);
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
			->where( [ 'pll_page_id' => $pageId ] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows();
	}

	public function countForUser( int $userId ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		return (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( [ 'pll_user_id' => $userId ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function deleteForUserBatch( int $userId, int $batchSize ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$pageIds = $dbw->newSelectQueryBuilder()
			->select( 'pll_page_id' )
			->from( self::TABLE )
			->where( [ 'pll_user_id' => $userId ] )
			->limit( $batchSize )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$pageIds ) {
			return 0;
		}
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [
				'pll_user_id' => $userId,
				'pll_page_id' => $pageIds,
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
			->select( [ 'pll.pll_page_id', 'pll.pll_user_id' ] )
			->limit( $batchSize )
			->fetchResultSet();

		$conditions = [];
		foreach ( $rows as $row ) {
			$conditions[] = $dbw->andExpr( [
				'pll_page_id' => (int)$row->pll_page_id,
				'pll_user_id' => (int)$row->pll_user_id,
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

	/**
	 * Build the shared primary query for missing page or user references.
	 */
	private function newOrphanQuery( IDatabase $dbw ): SelectQueryBuilder {
		return $dbw->newSelectQueryBuilder()
			->from( self::TABLE, 'pll' )
			->leftJoin( 'page', 'p', 'p.page_id = pll.pll_page_id' )
			->leftJoin( 'user', 'u', 'u.user_id = pll.pll_user_id' )
			->where( 'p.page_id IS NULL OR u.user_id IS NULL' )
			->caller( __METHOD__ );
	}
}
