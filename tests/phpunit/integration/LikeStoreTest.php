<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Extension\PageLike\LikeStore;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\PageLike\LikeStore
 * @group Database
 */
class LikeStoreTest extends MediaWikiIntegrationTestCase {
	private LikeStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'PageLikeEnabled' => true,
			'PageLikeEnableWrites' => true,
			'PageLikeAllowedNamespaces' => [ NS_MAIN ],
		] );
		$this->store = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	public function testExplicitStateIsIdempotentAndRelikeGetsNewTime(): void {
		$page = $this->getExistingTestPage( 'PageLike store idempotency' );
		$user = $this->getTestUser()->getUserIdentity();
		$pageId = $page->getId();
		$userId = $user->getId();

		$this->store->setState( $pageId, $userId, false );
		ConvertibleTimestamp::setFakeTime( '20260101000000' );
		$this->assertSame(
			[ 'liked' => true, 'count' => 1 ],
			$this->store->setState( $pageId, $userId, true )
		);
		$firstTimestamp = $this->getLikedTimestamp( $pageId, $userId );

		ConvertibleTimestamp::setFakeTime( '20260102000000' );
		$this->assertSame(
			[ 'liked' => true, 'count' => 1 ],
			$this->store->setState( $pageId, $userId, true )
		);
		$this->assertSame( $firstTimestamp, $this->getLikedTimestamp( $pageId, $userId ) );

		$this->assertSame(
			[ 'liked' => false, 'count' => 0 ],
			$this->store->setState( $pageId, $userId, false )
		);
		$this->assertSame(
			[ 'liked' => false, 'count' => 0 ],
			$this->store->setState( $pageId, $userId, false )
		);

		ConvertibleTimestamp::setFakeTime( '20260103000000' );
		$this->store->setState( $pageId, $userId, true );
		$this->assertSame( '20260103000000', $this->getLikedTimestamp( $pageId, $userId ) );
	}

	public function testUsersAndPagesHaveIndependentState(): void {
		$pageA = $this->getExistingTestPage( 'PageLike store page A' );
		$pageB = $this->getExistingTestPage( 'PageLike store page B' );
		$userA = $this->getTestUser( [ 'pagelike-test-a' ] )->getUserIdentity();
		$userB = $this->getTestUser( [ 'pagelike-test-b' ] )->getUserIdentity();

		$this->store->setState( $pageA->getId(), $userA->getId(), true );
		$this->store->setState( $pageA->getId(), $userB->getId(), true );
		$this->store->setState( $pageB->getId(), $userA->getId(), true );

		$this->assertSame( [
			$pageA->getId() => [ 'liked' => true, 'count' => 2 ],
			$pageB->getId() => [ 'liked' => true, 'count' => 1 ],
		], $this->store->getStates(
			[ $pageA->getId(), $pageB->getId() ],
			$userA->getId(),
			true
		) );
	}

	public function testIneligibleNamespaceIsRejectedInsideTransaction(): void {
		$page = $this->getExistingTestPage( 'Project:PageLike store disabled namespace' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->assertNull( $this->store->setState( $page->getId(), $user->getId(), true ) );
	}

	public function testMovePreservesStateAndDisallowedNamespaceSleeps(): void {
		$page = $this->getExistingTestPage( 'PageLike move lifecycle' );
		$pageId = $page->getId();
		$user = $this->getTestUser()->getUserIdentity();
		$mover = $this->getTestSysop()->getUser();
		$source = Title::newFromText( 'PageLike move lifecycle' );
		$disallowed = Title::newFromText( 'Project:PageLike move lifecycle' );
		$this->store->setState( $pageId, $user->getId(), true );

		$status = $this->getServiceContainer()->getMovePageFactory()
			->newMovePage( $source, $disallowed )
			->move( $mover, 'PageLike lifecycle test', false );
		$this->assertStatusGood( $status );
		$movedPage = $this->getServiceContainer()->getPageStore()
			->getPageById( $pageId, IDBAccessObject::READ_LATEST );
		$this->assertNotNull( $movedPage );
		$this->assertSame( NS_PROJECT, $movedPage->getNamespace() );
		$this->assertFalse(
			$this->getServiceContainer()->get( 'PageLike.Policy' )->isPageEnabled( $movedPage )
		);
		$this->assertNull( $this->store->setState( $pageId, $user->getId(), false ) );
		$this->assertSame(
			[ 'liked' => true, 'count' => 1 ],
			$this->store->getStates( [ $pageId ], $user->getId(), true )[$pageId]
		);

		$status = $this->getServiceContainer()->getMovePageFactory()
			->newMovePage( $disallowed, $source )
			->move( $mover, 'PageLike lifecycle test', false );
		$this->assertStatusGood( $status );
		$restoredPage = $this->getServiceContainer()->getPageStore()
			->getPageById( $pageId, IDBAccessObject::READ_LATEST );
		$this->assertNotNull( $restoredPage );
		$this->assertTrue(
			$this->getServiceContainer()->get( 'PageLike.Policy' )->isPageEnabled( $restoredPage )
		);
		$this->assertSame(
			[ 'liked' => true, 'count' => 1 ],
			$this->store->getStates( [ $pageId ], $user->getId(), true )[$pageId]
		);
	}

	public function testUserCleanupIsStrictlyBatched(): void {
		$pages = [
			$this->getExistingTestPage( 'PageLike user cleanup A' ),
			$this->getExistingTestPage( 'PageLike user cleanup B' ),
			$this->getExistingTestPage( 'PageLike user cleanup C' ),
		];
		$user = $this->getTestUser( [ 'pagelike-cleanup-user' ] )->getUserIdentity();
		$otherUser = $this->getTestUser( [ 'pagelike-cleanup-other' ] )->getUserIdentity();
		foreach ( $pages as $page ) {
			$this->store->setState( $page->getId(), $user->getId(), true );
		}
		$this->store->setState( $pages[0]->getId(), $otherUser->getId(), true );

		$this->assertSame( 2, $this->store->deleteForUserBatch( $user->getId(), 2 ) );
		$this->assertSame( 1, $this->store->countForUser( $user->getId() ) );
		$this->assertSame( 1, $this->store->deleteForUserBatch( $user->getId(), 2 ) );
		$this->assertSame( 0, $this->store->deleteForUserBatch( $user->getId(), 2 ) );
		$this->assertSame( 1, $this->store->countForUser( $otherUser->getId() ) );
	}

	private function getLikedTimestamp( int $pageId, int $userId ): string {
		return (string)$this->getDb()->newSelectQueryBuilder()
			->select( 'pll_liked_at' )
			->from( 'pagelike_like' )
			->where( [
				'pll_page_id' => $pageId,
				'pll_user_id' => $userId,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
