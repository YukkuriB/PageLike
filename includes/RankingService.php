<?php

namespace MediaWiki\Extension\PageLike;

use InvalidArgumentException;
use MediaWiki\Page\PageStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Computes rolling rankings and caches only raw page/count candidates.
 */
class RankingService {
	private const CACHE_TTL = 60;
	private const CANDIDATE_LIMIT = 500;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly WANObjectCache $cache,
		private readonly PageLikePolicy $policy,
		private readonly PageStore $pageStore,
		private readonly TitleFormatter $titleFormatter
	) {
	}

	/**
	 * @return array{period:string,generatedAt:string,items:array<int,array{pageid:int,title:string,count:int}>}
	 */
	public function getRanking( string $period, int $limit, Authority $authority ): array {
		if ( !in_array( $period, [ '7d', '30d', 'all' ], true ) ) {
			throw new InvalidArgumentException( 'Invalid PageLike ranking period' );
		}

		$raw = $this->getRawCandidates( $period );
		$pageIds = array_column( $raw['candidates'], 'pageid' );
		$pages = $pageIds
			? $this->pageStore->newSelectQueryBuilder()
				->wherePageIds( $pageIds )
				->caller( __METHOD__ )
				->fetchPageRecordArray()
			: [];
		$enabledPageIds = $this->policy->getEnabledPageIds( $pages );

		$items = [];
		foreach ( $raw['candidates'] as $candidate ) {
			$pageId = $candidate['pageid'];
			$page = $pages[$pageId] ?? null;
			if ( !$page || !isset( $enabledPageIds[$pageId] )
				|| !$this->policy->canRead( $authority, $page )
			) {
				continue;
			}
			$items[] = [
				'pageid' => $pageId,
				'title' => $this->titleFormatter->getPrefixedText( $page ),
				'count' => $candidate['count'],
			];
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return [
			'period' => $period,
			'generatedAt' => $raw['generatedAt'],
			'items' => $items,
		];
	}

	/**
	 * @return array{generatedAt:string,candidates:array<int,array{pageid:int,count:int}>}
	 */
	private function getRawCandidates( string $period ): array {
		$namespaces = $this->policy->getAllowedNamespaces();
		$method = __METHOD__;
		$key = $this->cache->makeKey(
			'pagelike',
			'rank',
			'v1',
			$period,
			sha1( json_encode( $namespaces ) )
		);

		return $this->cache->getWithSetCallback(
			$key,
			self::CACHE_TTL,
			function () use ( $period, $namespaces, $method ): array {
				$now = MWTimestamp::getInstance();
				$generatedUnix = (int)$now->getTimestamp( TS_UNIX );
				$generatedMw = $now->getTimestamp( TS_MW );
				$generatedAt = $now->format( 'Y-m-d\\TH:i:s\\Z' );
				if ( !$namespaces ) {
					return [ 'generatedAt' => $generatedAt, 'candidates' => [] ];
				}

				$dbr = $this->connectionProvider->getReplicaDatabase();
				$query = $dbr->newSelectQueryBuilder()
					->select( [
						'pageid' => 'pll_page_id',
						'like_count' => 'COUNT(*)',
					] )
					->from( 'pagelike_like' )
					->join( 'page', null, 'page_id = pll_page_id' )
					->where( [ 'page_namespace' => $namespaces ] )
					->andWhere( $dbr->expr( 'pll_liked_at', '<=', $dbr->timestamp( $generatedMw ) ) );

				$seconds = $period === '7d' ? 7 * 86400 : ( $period === '30d' ? 30 * 86400 : null );
				if ( $seconds !== null ) {
					$start = MWTimestamp::getInstance( (string)( $generatedUnix - $seconds ) )
						->getTimestamp( TS_MW );
					$query->andWhere( $dbr->expr( 'pll_liked_at', '>=', $dbr->timestamp( $start ) ) );
				}

				$rows = $query->groupBy( 'pll_page_id' )
					->orderBy( 'like_count', SelectQueryBuilder::SORT_DESC )
					->orderBy( 'pll_page_id', SelectQueryBuilder::SORT_ASC )
					->limit( self::CANDIDATE_LIMIT )
					->caller( $method )
					->fetchResultSet();
				$candidates = [];
				foreach ( $rows as $row ) {
					$candidates[] = [
						'pageid' => (int)$row->pageid,
						'count' => (int)$row->like_count,
					];
				}

				return [
					'generatedAt' => $generatedAt,
					'candidates' => $candidates,
				];
			}
		);
	}
}
