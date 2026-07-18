<?php

namespace MediaWiki\Extension\PageLike\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\PageLike\CreatorNotificationService;
use MediaWiki\Extension\PageLike\LikeStore;
use MediaWiki\Extension\PageLike\PageLikePolicy;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\PageStore;
use MediaWiki\Permissions\PermissionStatus;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * POST action that sets an explicit like state.
 */
class ApiPageLike extends ApiBase {
	public function __construct(
		ApiMain $main,
		string $action,
		private readonly LikeStore $store,
		private readonly PageLikePolicy $policy,
		private readonly PageStore $pageStore,
		private readonly CreatorNotificationService $creatorNotifications
	) {
		parent::__construct( $main, $action );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$authority = $this->getAuthority();

		// Keep this order stable to avoid leaking page-level configuration.
		if ( !$this->policy->isEnabled() ) {
			$this->dieWithError( 'pagelike-error-disabled', 'pagelike-disabled' );
		}
		if ( !$this->policy->areWritesEnabled() ) {
			$this->dieWithError( 'pagelike-error-writes-disabled', 'pagelike-writes-disabled' );
		}
		if ( !$authority->isNamed() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		$globalPermission = PermissionStatus::newEmpty();
		if ( !$authority->isAllowed( 'pagelike', $globalPermission ) ) {
			$this->dieStatus( $globalPermission );
		}

		$pageId = (int)$params['pageid'];
		$page = $this->pageStore->getPageById( $pageId, IDBAccessObject::READ_LATEST );
		if ( !$page ) {
			$this->dieWithError( [ 'apierror-nosuchpageid', $pageId ] );
		}
		$readPermission = PermissionStatus::newEmpty();
		if ( !$authority->definitelyCan( 'read', $page, $readPermission ) ) {
			$this->dieStatus( $readPermission );
		}
		if ( !$this->policy->isPageEnabled( $page ) ) {
			$this->dieWithError( 'pagelike-error-disabled-page', 'pagelike-disabled-page' );
		}

		// This is the only side-effecting authorization and rate-limit entry.
		$writePermission = PermissionStatus::newEmpty();
		if ( !$authority->authorizeWrite( 'pagelike', $page, $writePermission ) ) {
			$this->dieStatus( $writePermission );
		}

		try {
			$state = $this->store->setState(
				$pageId,
				$authority->getUser()->getId(),
				(bool)$params['set']
			);
		} catch ( DBError $exception ) {
			LoggerFactory::getInstance( 'PageLike' )->error(
				'PageLike API database operation failed',
				[
					'errorCode' => 'pagelike-database-operation-failed',
					'operation' => 'write',
					'pageId' => $pageId,
					'exceptionClass' => $exception::class,
				]
			);
			throw $exception;
		}
		if ( $state === null ) {
			$this->dieWithError( 'pagelike-error-disabled-page', 'pagelike-disabled-page' );
		}
		if ( $state['newlyLiked'] ) {
			$this->creatorNotifications->schedule( $page, $authority->getUser() );
		}

		$this->getResult()->addValue( null, 'pagelike', [
			'pageid' => $pageId,
			'enabled' => true,
			'liked' => $state['liked'],
			'count' => $state['count'],
		] );
	}

	/** @inheritDoc */
	public function isWriteMode(): bool {
		return true;
	}

	/** @inheritDoc */
	public function mustBePosted(): bool {
		return true;
	}

	/** @inheritDoc */
	public function needsToken(): string {
		return 'csrf';
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
			'set' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 0,
				IntegerDef::PARAM_MAX => 1,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=pagelike&pageid=123&set=1&token=123ABC'
				=> 'apihelp-pagelike-example-1',
		];
	}
}
