<?php

namespace MediaWiki\Extension\PageLike\Tests\Unit;

use MediaWiki\Extension\PageLike\CreatorNotificationService;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @covers \MediaWiki\Extension\PageLike\CreatorNotificationService
 */
class CreatorNotificationServiceTest extends MediaWikiUnitTestCase {
	public function testMissingEchoReturnsBeforeSchedulingAnyWork(): void {
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->expects( $this->never() )->method( 'getPrimaryDatabase' );
		$service = new CreatorNotificationService(
			null,
			$this->createMock( RevisionLookup::class ),
			$this->createMock( UserFactory::class ),
			$connectionProvider
		);

		$service->schedule(
			$this->createMock( PageIdentity::class ),
			$this->createMock( UserIdentity::class )
		);

		$this->addToAssertionCount( 1 );
	}
}
