<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Extension\PageLike\NotificationDeduplicationStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\PageLike\NotificationDeduplicationStore
 * @group Database
 */
class NotificationDeduplicationStoreTest extends MediaWikiIntegrationTestCase {
	private NotificationDeduplicationStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->getServiceContainer()->get(
			'PageLike.NotificationDeduplicationStore'
		);
	}

	public function testClaimIsUniqueAndSurvivesUnlike(): void {
		$page = $this->getExistingTestPage( 'PageLike notification deduplication' );
		$user = $this->getTestUser( [ 'pagelike-notification-dedupe-user' ] )->getUserIdentity();

		$this->assertTrue( $this->store->claim( $page->getId(), $user->getId() ) );
		$this->assertFalse( $this->store->claim( $page->getId(), $user->getId() ) );

		$likeStore = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
		$likeStore->setState( $page->getId(), $user->getId(), true );
		$likeStore->setState( $page->getId(), $user->getId(), false );

		$this->assertFalse( $this->store->claim( $page->getId(), $user->getId() ) );
	}

	public function testUsersAndPagesClaimIndependently(): void {
		$pageA = $this->getExistingTestPage( 'PageLike notification claim A' );
		$pageB = $this->getExistingTestPage( 'PageLike notification claim B' );
		$userA = $this->getTestUser( [ 'pagelike-notification-claim-a' ] )->getUserIdentity();
		$userB = $this->getTestUser( [ 'pagelike-notification-claim-b' ] )->getUserIdentity();

		$this->assertTrue( $this->store->claim( $pageA->getId(), $userA->getId() ) );
		$this->assertTrue( $this->store->claim( $pageA->getId(), $userB->getId() ) );
		$this->assertTrue( $this->store->claim( $pageB->getId(), $userA->getId() ) );
		$this->assertFalse( $this->store->claim( $pageA->getId(), $userA->getId() ) );
	}

	public function testPageAndUserCleanup(): void {
		$pageA = $this->getExistingTestPage( 'PageLike notification cleanup A' );
		$pageB = $this->getExistingTestPage( 'PageLike notification cleanup B' );
		$user = $this->getTestUser( [ 'pagelike-notification-cleanup' ] )->getUserIdentity();

		$this->store->claim( $pageA->getId(), $user->getId() );
		$this->store->claim( $pageB->getId(), $user->getId() );
		$this->assertSame( 2, $this->store->countForUser( $user->getId() ) );
		$this->assertSame( 1, $this->store->deleteForPageIfTableExists( $pageA->getId() ) );
		$this->assertSame( 1, $this->store->countForUser( $user->getId() ) );
		$this->assertSame( 1, $this->store->deleteForUserBatch( $user->getId(), 1 ) );
		$this->assertSame( 0, $this->store->deleteForUserBatch( $user->getId(), 1 ) );
	}
}
