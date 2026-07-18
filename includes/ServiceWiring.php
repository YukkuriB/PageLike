<?php

use MediaWiki\Extension\PageLike\CreatorNotificationService;
use MediaWiki\Extension\PageLike\LikeStore;
use MediaWiki\Extension\PageLike\PageLikePolicy;
use MediaWiki\Extension\PageLike\RankingService;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

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
	'PageLike.CreatorNotificationService' => static function (
		MediaWikiServices $services
	): CreatorNotificationService {
		$notifications = ExtensionRegistry::getInstance()->isLoaded( 'Echo' )
			? $services->getNotificationService()
			: null;
		return new CreatorNotificationService(
			$notifications,
			$services->getRevisionLookup(),
			$services->getUserFactory(),
			$services->getConnectionProvider()
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
