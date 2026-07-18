<?php

use MediaWiki\Extension\PageLike\LikeStore;
use MediaWiki\Extension\PageLike\PageLikePolicy;
use MediaWiki\Extension\PageLike\RankingService;
use MediaWiki\MediaWikiServices;

return [
	'PageLike.Policy' => static function ( MediaWikiServices $services ): PageLikePolicy {
		return new PageLikePolicy(
			$services->getMainConfig(),
			$services->getPageProps(),
			$services->getReadOnlyMode()
		);
	},
	'PageLike.LikeStore' => static function ( MediaWikiServices $services ): LikeStore {
		return new LikeStore(
			$services->getConnectionProvider(),
			$services->get( 'PageLike.Policy' )
		);
	},
	'PageLike.RankingService' => static function ( MediaWikiServices $services ): RankingService {
		return new RankingService(
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache(),
			$services->get( 'PageLike.Policy' ),
			$services->getPageStore(),
			$services->getTitleFormatter()
		);
	},
];
