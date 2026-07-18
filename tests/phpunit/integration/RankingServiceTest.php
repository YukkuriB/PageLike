<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Extension\PageLike\RankingService;
use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\PageLike\RankingService
 * @group Database
 */
class RankingServiceTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'PageLikeEnabled' => true,
			'PageLikeEnableRanking' => true,
			'PageLikeAllowedNamespaces' => [ NS_MAIN ],
		] );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	public function testRollingBoundaryStableSortAndCachedGenerationTime(): void {
		$pageA = $this->getExistingTestPage( 'PageLike rank page A' );
		$pageB = $this->getExistingTestPage( 'PageLike rank page B' );
		$userA = $this->getTestUser( [ 'pagelike-rank-a' ] )->getUserIdentity();
		$userB = $this->getTestUser( [ 'pagelike-rank-b' ] )->getUserIdentity();
		$userC = $this->getTestUser( [ 'pagelike-rank-c' ] )->getUserIdentity();

		// Generated at 2026-07-18 06:00 UTC; the 7d start is 2026-07-11 06:00.
		$this->insertLike( $pageA->getId(), $userA->getId(), '20260711060000' );
		$this->insertLike( $pageA->getId(), $userB->getId(), '20260718060000' );
		$this->insertLike( $pageB->getId(), $userC->getId(), '20260711055959' );
		ConvertibleTimestamp::setFakeTime( '20260718060000' );

		$service = $this->newService();
		$authority = $this->getTestSysop()->getUser();
		$first = $service->getRanking( '7d', 10, $authority );
		$this->assertSame( '2026-07-18T06:00:00Z', $first['generatedAt'] );
		$this->assertSame( [
			[
				'pageid' => $pageA->getId(),
				'title' => 'PageLike rank page A',
				'count' => 2,
			],
		], $first['items'] );

		ConvertibleTimestamp::setFakeTime( '20260718060030' );
		$second = $service->getRanking( '7d', 10, $authority );
		$this->assertSame( $first['generatedAt'], $second['generatedAt'] );
	}

	public function testEqualCountsUseAscendingPageId(): void {
		$pageA = $this->getExistingTestPage( 'PageLike rank tie A' );
		$pageB = $this->getExistingTestPage( 'PageLike rank tie B' );
		$pageFuture = $this->getExistingTestPage( 'PageLike rank future row' );
		$userA = $this->getTestUser( [ 'pagelike-rank-tie-a' ] )->getUserIdentity();
		$userB = $this->getTestUser( [ 'pagelike-rank-tie-b' ] )->getUserIdentity();
		$userFuture = $this->getTestUser( [ 'pagelike-rank-future' ] )->getUserIdentity();
		$this->insertLike( $pageB->getId(), $userA->getId(), '20260718050000' );
		$this->insertLike( $pageA->getId(), $userB->getId(), '20260718050000' );
		$this->insertLike( $pageFuture->getId(), $userFuture->getId(), '20260718060001' );
		ConvertibleTimestamp::setFakeTime( '20260718060000' );

		$service = $this->newService();
		$result = $service->getRanking( 'all', 10, $this->getTestSysop()->getUser() );
		$this->assertSame(
			[ min( $pageA->getId(), $pageB->getId() ), max( $pageA->getId(), $pageB->getId() ) ],
			array_column( $result['items'], 'pageid' )
		);
	}

	public function testThirtyDayBoundaryIsInclusive(): void {
		$pageAtStart = $this->getExistingTestPage( 'PageLike 30d boundary' );
		$pageTooOld = $this->getExistingTestPage( 'PageLike 30d too old' );
		$userAtStart = $this->getTestUser( [ 'pagelike-30d-start' ] )->getUserIdentity();
		$userTooOld = $this->getTestUser( [ 'pagelike-30d-old' ] )->getUserIdentity();
		$this->insertLike( $pageAtStart->getId(), $userAtStart->getId(), '20260618060000' );
		$this->insertLike( $pageTooOld->getId(), $userTooOld->getId(), '20260618055959' );
		ConvertibleTimestamp::setFakeTime( '20260718060000' );

		$result = $this->newService()->getRanking( '30d', 10, $this->getTestSysop()->getUser() );
		$this->assertSame( [ $pageAtStart->getId() ], array_column( $result['items'], 'pageid' ) );
	}

	public function testRawCacheIsPermissionFilteredPerAuthority(): void {
		$pageA = $this->getExistingTestPage( 'PageLike authority rank A' );
		$pageB = $this->getExistingTestPage( 'PageLike authority rank B' );
		$userA = $this->getTestUser( [ 'pagelike-authority-a' ] )->getUserIdentity();
		$userB = $this->getTestUser( [ 'pagelike-authority-b' ] )->getUserIdentity();
		$this->insertLike( $pageA->getId(), $userA->getId(), '20260718050000' );
		$this->insertLike( $pageB->getId(), $userB->getId(), '20260718050000' );
		ConvertibleTimestamp::setFakeTime( '20260718060000' );
		$service = $this->newService();

		$authorityA = $this->createMock( Authority::class );
		$authorityA->method( 'definitelyCan' )->willReturnCallback(
			static fn ( string $action, $page ): bool => $page->getId() === $pageA->getId()
		);
		$authorityB = $this->createMock( Authority::class );
		$authorityB->method( 'definitelyCan' )->willReturnCallback(
			static fn ( string $action, $page ): bool => $page->getId() === $pageB->getId()
		);

		$resultA = $service->getRanking( 'all', 10, $authorityA );
		$resultB = $service->getRanking( 'all', 10, $authorityB );
		$this->assertSame( $resultA['generatedAt'], $resultB['generatedAt'] );
		$this->assertSame( [ $pageA->getId() ], array_column( $resultA['items'], 'pageid' ) );
		$this->assertSame( [ $pageB->getId() ], array_column( $resultB['items'], 'pageid' ) );
	}

	public function testAllPeriodsExcludeDisabledNamespaceAndDeletedPages(): void {
		$visible = $this->getExistingTestPage( 'PageLike visible rank page' );
		$disabled = $this->getExistingTestPage( 'PageLike disabled rank page' );
		$disallowed = $this->getExistingTestPage( 'Project:PageLike disallowed rank page' );
		$deleted = $this->getExistingTestPage( 'PageLike deleted rank page' );
		$deletedId = $deleted->getId();
		$deleteStatus = $deleted->doDeleteArticleReal( '', $this->getTestSysop()->getUser() );
		$this->assertStatusGood( $deleteStatus );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'page_props' )
			->row( [
				'pp_page' => $disabled->getId(),
				'pp_propname' => 'pagelike_nopagelike',
				'pp_value' => '',
				'pp_sortkey' => null,
			] )
			->caller( __METHOD__ )
			->execute();

		$pages = [ $visible->getId(), $disabled->getId(), $disallowed->getId(), $deletedId ];
		foreach ( $pages as $index => $pageId ) {
			$user = $this->getTestUser( [ "pagelike-filter-$index" ] )->getUserIdentity();
			$this->insertLike( $pageId, $user->getId(), '20260718050000' );
		}
		ConvertibleTimestamp::setFakeTime( '20260718060000' );
		$service = $this->newService();
		foreach ( [ '7d', '30d', 'all' ] as $period ) {
			$result = $service->getRanking( $period, 10, $this->getTestSysop()->getUser() );
			$this->assertSame(
				[ $visible->getId() ],
				array_column( $result['items'], 'pageid' ),
				$period
			);
		}
	}

	public function testCacheExpiryCreatesANewWindow(): void {
		$page = $this->getExistingTestPage( 'PageLike expiring rank cache' );
		$user = $this->getTestUser( [ 'pagelike-expiring-cache' ] )->getUserIdentity();
		$this->insertLike( $page->getId(), $user->getId(), '20260718050000' );
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wallClock = 1000.0;
		$cache->setMockTime( $wallClock );
		ConvertibleTimestamp::setFakeTime( '20260718060000' );
		$service = $this->newService( $cache );
		$first = $service->getRanking( 'all', 10, $this->getTestSysop()->getUser() );

		$wallClock += 61;
		ConvertibleTimestamp::setFakeTime( '20260718060101' );
		$second = $service->getRanking( 'all', 10, $this->getTestSysop()->getUser() );
		$this->assertNotSame( $first['generatedAt'], $second['generatedAt'] );
	}

	private function newService( ?WANObjectCache $cache = null ): RankingService {
		return new RankingService(
			$this->getServiceContainer()->getConnectionProvider(),
			$cache ?? new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$this->getServiceContainer()->get( 'PageLike.Policy' ),
			$this->getServiceContainer()->getPageStore(),
			$this->getServiceContainer()->getTitleFormatter()
		);
	}

	private function insertLike( int $pageId, int $userId, string $timestamp ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'pagelike_like' )
			->row( [
				'pll_page_id' => $pageId,
				'pll_user_id' => $userId,
				'pll_liked_at' => $timestamp,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
