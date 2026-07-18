<?php

namespace MediaWiki\Extension\PageLike\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\PageLike\PageLikePolicy;
use MediaWiki\Extension\PageLike\RankingService;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\DBError;

/**
 * Lists a permission-filtered PageLike ranking.
 */
class ApiQueryPageLikeRank extends ApiQueryBase {
	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly RankingService $rankingService,
		private readonly PageLikePolicy $policy
	) {
		parent::__construct( $query, $moduleName, 'plr' );
	}

	public function execute(): void {
		if ( !$this->policy->isEnabled() ) {
			$this->dieWithError( 'pagelike-error-disabled', 'pagelike-disabled' );
		}
		if ( !$this->policy->isRankingEnabled() ) {
			$this->dieWithError( 'pagelike-error-ranking-disabled', 'pagelike-ranking-disabled' );
		}

		$params = $this->extractRequestParams();
		try {
			$result = $this->rankingService->getRanking(
				$params['period'],
				(int)$params['limit'],
				$this->getAuthority()
			);
		} catch ( DBError $exception ) {
			LoggerFactory::getInstance( 'PageLike' )->error(
				'PageLike API database operation failed',
				[
					'errorCode' => 'pagelike-database-operation-failed',
					'operation' => 'ranking',
					'exceptionClass' => $exception::class,
				]
			);
			throw $exception;
		}
		$this->getResult()->addValue( 'query', 'pagelikerank', $result );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'period' => [
				ParamValidator::PARAM_TYPE => [ '7d', '30d', 'all' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 10,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 100,
			],
		];
	}

	/** @inheritDoc */
	public function getCacheMode( $params ): string {
		return 'private';
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=query&list=pagelikerank&plrperiod=7d&plrlimit=10'
				=> 'apihelp-query+pagelikerank-example-1',
		];
	}
}
