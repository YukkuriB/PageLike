<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiQueryTokens;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\SessionManager;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @covers \MediaWiki\Extension\PageLike\Api\ApiPageLike
 * @covers \MediaWiki\Extension\PageLike\Api\ApiQueryPageLikeInfo
 * @covers \MediaWiki\Extension\PageLike\Api\ApiQueryPageLikeRank
 * @group Database
 */
class ApiPageLikeTest extends ApiTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'PageLikeEnabled' => true,
			'PageLikeEnableWrites' => true,
			'PageLikeEnableRanking' => true,
			'PageLikeAllowedNamespaces' => [ NS_MAIN ],
		] );
		$this->setGroupPermissions( 'sysop', 'pagelike', true );
	}

	public function testWriteReturnsPrimaryStateAndInfoReadsIt(): void {
		$page = $this->getExistingTestPage( 'PageLike API write' );
		$authority = $this->getTestSysop()->getUser();

		[ $write ] = $this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
			'formatversion' => 2,
		], null, $authority, 'csrf' );
		$this->assertSame( [
			'pageid' => $page->getId(),
			'enabled' => true,
			'liked' => true,
			'count' => 1,
		], $write['pagelike'] );

		[ $info ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'pagelikeinfo',
			'pageids' => $page->getId(),
			'formatversion' => 2,
		], null, false, $authority );
		$this->assertSame( [
			'enabled' => true,
			'liked' => true,
			'count' => 1,
			'canlike' => true,
		], $info['query']['pages'][$page->getId()]['pagelikeinfo'] );
	}

	public function testDisabledSwitchHidesStoredState(): void {
		$page = $this->getExistingTestPage( 'PageLike API disabled' );
		$authority = $this->getTestSysop()->getUser();
		$store = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
		$store->setState( $page->getId(), $authority->getId(), true );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'page_props' )
			->row( [
				'pp_page' => $page->getId(),
				'pp_propname' => 'pagelike_nopagelike',
				'pp_value' => '',
				'pp_sortkey' => null,
			] )
			->caller( __METHOD__ )
			->execute();

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'pagelikeinfo',
			'pageids' => $page->getId(),
			'formatversion' => 2,
		], null, false, $authority );
		$this->assertSame( [
			'enabled' => false,
			'liked' => false,
			'count' => 0,
			'canlike' => false,
		], $result['query']['pages'][$page->getId()]['pagelikeinfo'] );
	}

	public function testWritesOffKeepsNamedUsersExistingStateVisible(): void {
		$page = $this->getExistingTestPage( 'PageLike writes-off state' );
		$authority = $this->getTestSysop()->getUser();
		$this->getServiceContainer()->get( 'PageLike.LikeStore' )
			->setState( $page->getId(), $authority->getId(), true );
		$this->overrideConfigValue( 'PageLikeEnableWrites', false );

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'pagelikeinfo',
			'pageids' => $page->getId(),
			'formatversion' => 2,
		], null, false, $authority );
		$this->assertSame( [
			'enabled' => true,
			'liked' => true,
			'count' => 1,
			'canlike' => false,
		], $result['query']['pages'][$page->getId()]['pagelikeinfo'] );
	}

	public function testAnonymousUserSeesCountButNoPersonalState(): void {
		$page = $this->getExistingTestPage( 'PageLike anonymous state' );
		$user = $this->getTestSysop()->getUser();
		$this->getServiceContainer()->get( 'PageLike.LikeStore' )
			->setState( $page->getId(), $user->getId(), true );
		$anonymous = $this->getServiceContainer()->getUserFactory()->newAnonymous();

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'pagelikeinfo',
			'pageids' => $page->getId(),
			'formatversion' => 2,
		], null, false, $anonymous );
		$this->assertSame( [
			'enabled' => true,
			'liked' => false,
			'count' => 1,
			'canlike' => false,
		], $result['query']['pages'][$page->getId()]['pagelikeinfo'] );
	}

	public function testFeatureAndRankingSwitchesHaveStableErrors(): void {
		$this->overrideConfigValue( 'PageLikeEnabled', false );
		$this->expectApiErrorCode( 'pagelike-disabled' );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'pagelikerank',
			'plrperiod' => 'all',
		] );
	}

	public function testRankingSwitchError(): void {
		$this->overrideConfigValue( 'PageLikeEnableRanking', false );
		$this->expectApiErrorCode( 'pagelike-ranking-disabled' );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'pagelikerank',
			'plrperiod' => 'all',
		] );
	}

	public function testAnonymousCannotWrite(): void {
		$page = $this->getExistingTestPage( 'PageLike anonymous write' );
		$this->expectApiErrorCode( 'notloggedin' );
		$this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, $this->getServiceContainer()->getUserFactory()->newAnonymous() );
	}

	public function testUserWithoutRightCannotWrite(): void {
		$this->setGroupPermissions( 'user', 'pagelike', false );
		$page = $this->getExistingTestPage( 'PageLike no-right write' );
		$user = $this->getTestUser( [ 'pagelike-no-right' ] )->getUser();
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, $user );
	}

	public function testReadOnlyWikiRejectsWrite(): void {
		$page = $this->getExistingTestPage( 'PageLike read-only write' );
		$this->overrideConfigValue( 'ReadOnly', 'PageLike test read-only mode' );
		$this->expectApiErrorCode( 'readonly' );
		$this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, $this->getTestSysop()->getUser() );
	}

	public function testBlockedUserCannotWrite(): void {
		$page = $this->getExistingTestPage( 'PageLike blocked write' );
		$blockedUser = $this->getMutableTestUser( [ 'sysop' ] )->getUser();
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'address' => $blockedUser->getName(),
			'by' => $this->getTestSysop()->getUser(),
			'reason' => 'PageLike test block',
			'timestamp' => '20260718000000',
			'expiry' => 'infinity',
		] );

		$this->expectApiErrorCode( 'blocked' );
		$this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, $blockedUser );
	}

	public function testRateLimitCountsOneAuthorizationPerRequest(): void {
		$page = $this->getExistingTestPage( 'PageLike rate limit write' );
		$this->setGroupPermissions( 'pagelike-rate-test', 'pagelike', true );
		$user = $this->getTestUser( [ 'pagelike-rate-test' ] )->getUser();
		$params = [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		];
		for ( $request = 0; $request < 30; $request++ ) {
			$this->doApiRequestWithToken( $params, null, $user );
		}

		$this->expectApiErrorCode( 'ratelimited' );
		$this->doApiRequestWithToken( $params, null, $user );
	}

	public function testWriteDeclaresPostRequirement(): void {
		$module = ( new ApiMain() )->getModuleManager()->getModule( 'pagelike', 'action' );
		$this->assertTrue( $module->mustBePosted() );
	}

	public function testActualGetRequestIsRejected(): void {
		$this->overrideConfigValue( 'EnableWriteAPI', true );
		$authority = $this->getTestSysop()->getUser();
		$sessionUser = clone $authority;
		$session = SessionManager::singleton()->getEmptySession();
		$session->setUser( $sessionUser );
		$token = ApiQueryTokens::getToken(
			$authority,
			$session,
			ApiQueryTokens::getTokenTypeSalts()['csrf']
		)->toString();
		$request = new FauxRequest( [
			'action' => 'pagelike',
			'pageid' => 1,
			'set' => 1,
			'token' => $token,
			'format' => 'json',
		], false, $session );
		$request->setRequestURL( self::$apiUrl );
		$context = $this->apiContext->newTestContext( $request, $authority );
		$api = new ApiMain( $context, true, false );

		ob_start();
		$api->execute();
		$response = json_decode( (string)ob_get_clean(), true );

		// Posted-only parameters are rejected before the module-level POST check.
		$this->assertSame( 'mustpostparams', $response['error']['code'] ?? null );
	}

	public function testWriteRequiresToken(): void {
		$page = $this->getExistingTestPage( 'PageLike missing token write' );
		$this->expectApiErrorCode( 'missingparam' );
		$this->doApiRequest( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, false, $this->getTestSysop()->getUser() );
	}

	public function testWriteRejectsBadToken(): void {
		$page = $this->getExistingTestPage( 'PageLike bad token write' );
		$this->expectApiErrorCode( 'badtoken' );
		$this->doApiRequest( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
			'token' => 'not-a-valid-token',
		], null, false, $this->getTestSysop()->getUser() );
	}

	public function testWriteSwitchHasStableError(): void {
		$page = $this->getExistingTestPage( 'PageLike writes disabled' );
		$this->overrideConfigValue( 'PageLikeEnableWrites', false );
		$this->expectApiErrorCode( 'pagelike-writes-disabled' );
		$this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, $this->getTestSysop()->getUser() );
	}

	public function testDisallowedNamespaceHasStableError(): void {
		$page = $this->getExistingTestPage( 'Project:PageLike API disabled namespace' );
		$this->expectApiErrorCode( 'pagelike-disabled-page' );
		$this->doApiRequestWithToken( [
			'action' => 'pagelike',
			'pageid' => $page->getId(),
			'set' => 1,
		], null, $this->getTestSysop()->getUser() );
	}

	public function testDeleteAndUndeleteStartWithNoLikes(): void {
		$page = $this->getExistingTestPage( 'PageLike delete lifecycle' );
		$titleText = $page->getTitle()->getPrefixedText();
		$pageId = $page->getId();
		$authority = $this->getTestSysop()->getUser();
		$store = $this->getServiceContainer()->get( 'PageLike.LikeStore' );
		$store->setState( $pageId, $authority->getId(), true );

		$this->doApiRequestWithToken( [
			'action' => 'delete',
			'pageid' => $pageId,
		], null, $authority );
		$this->assertSame(
			[ 'liked' => false, 'count' => 0 ],
			$store->getStates( [ $pageId ], $authority->getId(), true )[$pageId]
		);

		$this->doApiRequestWithToken( [
			'action' => 'undelete',
			'title' => $titleText,
		], null, $authority );
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'pagelikeinfo',
			'titles' => $titleText,
			'formatversion' => 2,
		], null, false, $authority );
		$restoredPage = array_values( $result['query']['pages'] )[0];
		$this->assertSame( [
			'enabled' => true,
			'liked' => false,
			'count' => 0,
			'canlike' => true,
		], $restoredPage['pagelikeinfo'] );
	}
}
