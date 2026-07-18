<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @coversNothing
 * @group Database
 */
class OpenBoxDefaultsTest extends ApiTestCase {
	public function testFeatureDefaultsAreReadyForUse(): void {
		$config = $this->getServiceContainer()->getMainConfig();

		$this->assertTrue( $config->get( 'PageLikeEnabled' ) );
		$this->assertTrue( $config->get( 'PageLikeEnableWrites' ) );
		$this->assertTrue( $config->get( 'PageLikeShowDefaultButton' ) );
		$this->assertFalse( $config->get( 'PageLikeEnableRanking' ) );
	}

	public function testNamedUserCanLikeByDefaultButAnonymousHasNoRight(): void {
		$page = $this->getExistingTestPage( 'PageLike open-box defaults' );
		$user = $this->getTestUser()->getUser();
		$anonymous = $this->getServiceContainer()->getUserFactory()->newAnonymous();

		$this->assertTrue( $user->isAllowed( 'pagelike' ) );
		$this->assertFalse( $anonymous->isAllowed( 'pagelike' ) );

		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
			'formatversion' => 2,
		], null, $user, 'csrf' );

		$this->assertSame( [
			'pageid' => $page->getId(),
			'enabled' => true,
			'liked' => true,
			'count' => 1,
		], $result['pagelike'] );
	}
}
