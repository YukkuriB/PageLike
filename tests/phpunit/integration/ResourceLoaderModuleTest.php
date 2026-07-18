<?php

namespace MediaWiki\Extension\PageLike\Tests\Integration;

use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 */
class ResourceLoaderModuleTest extends MediaWikiIntegrationTestCase {
	public function testDefaultModuleBuildsFromExtensionPaths(): void {
		$resourceLoader = $this->getServiceContainer()->getResourceLoader();
		$context = new Context( $resourceLoader, new FauxRequest( [
			'lang' => 'en',
			'skin' => 'fallback',
			'debug' => '1',
		] ) );
		$module = $resourceLoader->getModule( 'ext.pageLike' );

		$this->assertNotNull( $module );
		$this->assertIsArray( $module->getScript( $context ) );
		$this->assertIsArray( $module->getStyles( $context ) );
	}
}
