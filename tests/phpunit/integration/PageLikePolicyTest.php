<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PageLike\Hooks;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\PageLike\PageLikePolicy
 * @covers \MediaWiki\Extension\PageLike\Hooks::onSkinAfterContent
 * @group Database
 */
class PageLikePolicyTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'PageLikeEnabled' => true,
			'PageLikeShowDefaultButton' => false,
			'PageLikeAllowedNamespaces' => [ NS_MAIN ],
		] );
	}

	public function testMountOnlyAppearsOnLatestArticleView(): void {
		$page = $this->getExistingTestPage( 'PageLike mount policy' );
		$policy = $this->getServiceContainer()->get( 'PageLike.Policy' );

		$this->assertTrue( $policy->shouldOutputMount( $this->newOutputPage( $page->getTitle() ) ) );
		$this->assertFalse( $policy->shouldOutputMount(
			$this->newOutputPage( $page->getTitle(), [], false )
		) );
		foreach ( [
			[ 'action' => 'edit' ],
			[ 'action' => 'history' ],
			[ 'diff' => 'prev' ],
			[ 'oldid' => '1' ],
		] as $query ) {
			$this->assertFalse(
				$policy->shouldOutputMount( $this->newOutputPage( $page->getTitle(), $query ) ),
				json_encode( $query )
			);
		}

		$missing = Title::makeTitle( NS_MAIN, 'PageLike missing mount page' );
		$special = Title::makeTitle( NS_SPECIAL, 'Version' );
		$disallowed = $this->getExistingTestPage( 'Project:PageLike mount policy' );
		$this->assertFalse( $policy->shouldOutputMount( $this->newOutputPage( $missing ) ) );
		$this->assertFalse( $policy->shouldOutputMount( $this->newOutputPage( $special ) ) );
		$this->assertFalse( $policy->shouldOutputMount(
			$this->newOutputPage( $disallowed->getTitle() )
		) );

		$disabledOutput = $this->newOutputPage( $page->getTitle() );
		$disabledOutput->setProperty( 'pagelike-disabled', true );
		$this->assertFalse( $policy->shouldOutputMount( $disabledOutput ) );
	}

	public function testSkinHookOutputsNeutralMountAndLoadsModuleOnlyWhenConfigured(): void {
		$page = $this->getExistingTestPage( 'PageLike neutral mount' );
		$hooks = new Hooks(
			$this->getServiceContainer()->get( 'PageLike.Policy' ),
			$this->getServiceContainer()->get( 'PageLike.LikeStore' )
		);
		$outputPage = $this->newOutputPage( $page->getTitle() );
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getOutput' )->willReturn( $outputPage );
		$html = '';
		$hooks->onSkinAfterContent( $html, $skin );

		$this->assertStringContainsString( 'class="ext-pagelike"', $html );
		$this->assertStringContainsString( 'data-page-id="' . $page->getId() . '"', $html );
		$this->assertSame( [], $outputPage->getModules() );

		$this->overrideConfigValue( 'PageLikeShowDefaultButton', true );
		$outputPage = $this->newOutputPage( $page->getTitle() );
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getOutput' )->willReturn( $outputPage );
		$html = '';
		$hooks->onSkinAfterContent( $html, $skin );
		$this->assertContains( 'ext.pageLike', $outputPage->getModules() );
	}

	private function newOutputPage( Title $title, array $query = [], bool $isArticle = true ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( $title );
		$context->setRequest( new FauxRequest( $query ) );
		$context->setUser( $this->getTestSysop()->getUser() );
		$outputPage = new OutputPage( $context );
		$outputPage->setArticleFlag( $isArticle );
		return $outputPage;
	}
}
