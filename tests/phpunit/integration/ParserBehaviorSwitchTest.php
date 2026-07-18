<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\PageLike\PageLikePolicy;
use MediaWiki\Page\PageProps;
use MediaWiki\Parser\ParserOptions;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\PageLike\Hooks
 * @group Database
 */
class ParserBehaviorSwitchTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'PageLikeEnabled' => true,
			'PageLikeAllowedNamespaces' => [ NS_MAIN ],
		] );
	}

	/**
	 * @dataProvider provideAliases
	 */
	public function testAliasesSetPageProperty( string $wikitext ): void {
		$page = $this->getExistingTestPage( 'PageLike behavior switch host' );
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$output = $parser->parse( $wikitext, $page->getTitle(), ParserOptions::newFromAnon() );

		$this->assertSame(
			'',
			$output->getPageProperty( PageLikePolicy::DISABLE_PAGE_PROPERTY )
		);
	}

	public static function provideAliases(): array {
		return [
			'English alias' => [ '__NOPAGELIKE__' ],
			'Chinese alias' => [ '__关闭点赞__' ],
		];
	}

	public function testTranscludedSwitchDisablesHostParserOutput(): void {
		$this->editPage( 'Template:PageLike disabled fixture', '__NOPAGELIKE__' );
		$host = $this->getExistingTestPage( 'PageLike transclusion host' );
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$output = $parser->parse(
			'{{PageLike disabled fixture}}',
			$host->getTitle(),
			ParserOptions::newFromAnon()
		);

		$this->assertNotNull(
			$output->getPageProperty( PageLikePolicy::DISABLE_PAGE_PROPERTY )
		);
	}

	public function testPagePropsPersistsAndClearsWithPageEdits(): void {
		$page = $this->getExistingTestPage( 'PageLike persisted behavior switch' );
		$this->assertStatusGood( $this->editPage( $page->getTitle(), '__NOPAGELIKE__' ) );
		DeferredUpdates::doUpdates();
		$this->assertSame( '', $this->getStoredProperty( $page->getId() ) );
		$this->assertFalse(
			$this->getServiceContainer()->get( 'PageLike.Policy' )->isPageEnabled( $page )
		);

		$this->assertStatusGood( $this->editPage( $page->getTitle(), 'PageLike enabled again' ) );
		DeferredUpdates::doUpdates();
		$this->assertFalse( $this->getStoredProperty( $page->getId() ) );
		$services = $this->getServiceContainer();
		$freshRequestPolicy = new PageLikePolicy(
			$services->getMainConfig(),
			new PageProps( $services->getLinkBatchFactory(), $services->getConnectionProvider() ),
			$services->getReadOnlyMode()
		);
		$this->assertTrue( $freshRequestPolicy->isPageEnabled( $page ) );
	}

	/**
	 * @return string|false
	 */
	private function getStoredProperty( int $pageId ) {
		$value = $this->getDb()->newSelectQueryBuilder()
			->select( 'pp_value' )
			->from( 'page_props' )
			->where( [
				'pp_page' => $pageId,
				'pp_propname' => PageLikePolicy::DISABLE_PAGE_PROPERTY,
			] )
			->caller( __METHOD__ )
			->fetchField();
		return $value === false ? false : (string)$value;
	}
}
