<?php

namespace MediaWiki\Extension\PageLike;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Notification\Types\WikiNotification;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Sends creator notifications when Echo is available and otherwise does nothing.
 */
final class CreatorNotificationService {
	public function __construct(
		private readonly ?NotificationService $notifications,
		private readonly RevisionLookup $revisionLookup,
		private readonly UserFactory $userFactory,
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/**
	 * Schedule creator resolution after the response. Tying the update to the
	 * primary connection cancels it if the like transaction rolls back.
	 */
	public function schedule( PageIdentity $page, UserIdentity $agent ): void {
		$notifications = $this->notifications;
		if ( $notifications === null ) {
			return;
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $notifications, $page, $agent ): void {
				$this->notifyPageCreator( $notifications, $page, $agent );
			},
			DeferredUpdates::POSTSEND,
			$this->connectionProvider->getPrimaryDatabase()
		);
	}

	/**
	 * Resolve the current named creator on the primary database and notify them
	 * only if they are not the agent and can still read the page.
	 */
	private function notifyPageCreator(
		NotificationService $notifications,
		PageIdentity $page,
		UserIdentity $agent
	): void {
		try {
			$firstRevision = $this->revisionLookup->getFirstRevision(
				$page,
				IDBAccessObject::READ_LATEST
			);
			$creatorIdentity = $firstRevision?->getUser();
			if ( !$creatorIdentity || $creatorIdentity->getId() === $agent->getId() ) {
				return;
			}

			$creator = $this->userFactory->newFromUserIdentity( $creatorIdentity );
			if ( !$creator->isNamed() || !$creator->definitelyCan( 'read', $page ) ) {
				return;
			}

			$notifications->notify(
				new WikiNotification( 'page-like', $page, $agent ),
				new RecipientSet( $creator )
			);
		} catch ( Throwable $exception ) {
			LoggerFactory::getInstance( 'PageLike' )->error(
				'PageLike creator notification failed after a successful like',
				[
					'errorCode' => 'pagelike-creator-notification-failed',
					'pageId' => $page->getId(),
					'agentId' => $agent->getId(),
					'exceptionClass' => $exception::class,
				]
			);
		}
	}
}
