<?php

namespace MediaWiki\Extension\PageLike\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\PageLike\LikeStore;
use MediaWiki\Extension\PageLike\PageLikePolicy;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\Rdbms\DBError;

/**
 * Adds PageLike status to each readable page in an Action API page set.
 */
class ApiQueryPageLikeInfo extends ApiQueryBase {
	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly LikeStore $store,
		private readonly PageLikePolicy $policy
	) {
		parent::__construct( $query, $moduleName, 'pli' );
	}

	public function execute(): void {
		$authority = $this->getAuthority();
		$pages = $this->getPageSet()->getGoodPages();
		$readablePages = [];
		foreach ( $pages as $pageId => $page ) {
			if ( $this->policy->canRead( $authority, $page ) ) {
				$readablePages[(int)$pageId] = $page;
			}
		}
		if ( !$readablePages ) {
			return;
		}

		$enabledPageIds = $this->policy->getEnabledPageIds( $readablePages );
		$enabledIds = array_keys( $enabledPageIds );
		$userId = $authority->isNamed() ? $authority->getUser()->getId() : null;
		try {
			$states = $enabledIds ? $this->store->getStates( $enabledIds, $userId ) : [];
		} catch ( DBError $exception ) {
			LoggerFactory::getInstance( 'PageLike' )->error(
				'PageLike API database operation failed',
				[
					'errorCode' => 'pagelike-database-operation-failed',
					'operation' => 'state',
					'pageCount' => count( $enabledIds ),
					'exceptionClass' => $exception::class,
				]
			);
			throw $exception;
		}

		foreach ( $readablePages as $pageId => $page ) {
			if ( !isset( $enabledPageIds[$pageId] ) ) {
				$info = [
					'enabled' => false,
					'liked' => false,
					'count' => 0,
					'canlike' => false,
				];
			} else {
				$state = $states[$pageId] ?? [ 'liked' => false, 'count' => 0 ];
				$info = [
					'enabled' => true,
					'liked' => $state['liked'],
					'count' => $state['count'],
					'canlike' => $this->policy->canProbablyLike( $authority, $page, true ),
				];
			}
			$this->getResult()->addValue(
				[ 'query', 'pages', $pageId ],
				'pagelikeinfo',
				$info
			);
		}
	}

	/** @inheritDoc */
	public function getCacheMode( $params ): string {
		return 'private';
	}
}
