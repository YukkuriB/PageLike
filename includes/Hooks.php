<?php

namespace MediaWiki\Extension\PageLike;

use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\SkinAfterContentHook;
use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use Throwable;

class Hooks implements
	GetDoubleUnderscoreIDsHook,
	OutputPageParserOutputHook,
	SkinAfterContentHook,
	PageDeleteCompleteHook
{
	public function __construct(
		private readonly PageLikePolicy $policy,
		private readonly LikeStore $store,
		private readonly NotificationDeduplicationStore $notificationDedupeStore
	) {
	}

	/** @inheritDoc */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = PageLikePolicy::DISABLE_PAGE_PROPERTY;
	}

	/** @inheritDoc */
	public function onOutputPageParserOutput( $outputPage, $parserOutput ): void {
		if ( $parserOutput->getPageProperty( PageLikePolicy::DISABLE_PAGE_PROPERTY ) !== null ) {
			$outputPage->setProperty( 'pagelike-disabled', true );
		}
	}

	/** @inheritDoc */
	public function onSkinAfterContent( &$data, $skin ) {
		$outputPage = $skin->getOutput();
		if ( !$this->policy->shouldOutputMount( $outputPage ) ) {
			return;
		}

		$pageId = $outputPage->getTitle()->getArticleID();
		$data .= Html::element( 'div', [
			'class' => 'ext-pagelike',
			'data-page-id' => (string)$pageId,
		] );
		if ( $this->policy->shouldShowDefaultButton() ) {
			$outputPage->addModules( 'ext.pageLike' );
		}
	}

	/** @inheritDoc */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		try {
			$this->store->deleteForPageIfTableExists( $pageID );
		} catch ( Throwable $exception ) {
			// A cleanup failure must never prevent a core page deletion.
			LoggerFactory::getInstance( 'PageLike' )->error(
				'PageLike page cleanup failed',
				[
					'pageId' => $pageID,
					'errorCode' => 'pagelike-page-delete-cleanup-failed',
					'exceptionClass' => $exception::class,
				]
			);
		}
		try {
			$this->notificationDedupeStore->deleteForPageIfTableExists( $pageID );
		} catch ( Throwable $exception ) {
			// A cleanup failure must never prevent a core page deletion.
			LoggerFactory::getInstance( 'PageLike' )->error(
				'PageLike notification deduplication cleanup failed',
				[
					'pageId' => $pageID,
					'errorCode' => 'pagelike-notification-dedupe-page-delete-cleanup-failed',
					'exceptionClass' => $exception::class,
				]
			);
		}
	}
}
