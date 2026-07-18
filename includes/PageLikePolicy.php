<?php

namespace MediaWiki\Extension\PageLike;

use MediaWiki\Config\Config;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageProps;
use MediaWiki\Permissions\Authority;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\ReadOnlyMode;

/**
 * Centralizes feature, account, page and permission eligibility rules.
 */
class PageLikePolicy {
	// MagicWordArray uses IDs as PCRE group names in MediaWiki 1.45, so the
	// brief's hyphenated spelling cannot be parsed safely on the target version.
	public const DISABLE_PAGE_PROPERTY = 'pagelike_nopagelike';

	public function __construct(
		private readonly Config $config,
		private readonly PageProps $pageProps,
		private readonly ReadOnlyMode $readOnlyMode
	) {
	}

	public function isEnabled(): bool {
		return (bool)$this->config->get( 'PageLikeEnabled' );
	}

	public function areWritesEnabled(): bool {
		return (bool)$this->config->get( 'PageLikeEnableWrites' );
	}

	public function isRankingEnabled(): bool {
		return (bool)$this->config->get( 'PageLikeEnableRanking' );
	}

	public function shouldShowDefaultButton(): bool {
		return (bool)$this->config->get( 'PageLikeShowDefaultButton' );
	}

	/**
	 * @return int[] Sorted, unique namespace IDs.
	 */
	public function getAllowedNamespaces(): array {
		$namespaces = [];
		foreach ( (array)$this->config->get( 'PageLikeAllowedNamespaces' ) as $namespace ) {
			if ( is_int( $namespace ) || ctype_digit( (string)$namespace ) ) {
				$namespace = (int)$namespace;
				if ( $namespace >= 0 ) {
					$namespaces[$namespace] = $namespace;
				}
			}
		}
		sort( $namespaces, SORT_NUMERIC );
		return $namespaces;
	}

	public function isNamespaceAllowed( int $namespace ): bool {
		return in_array( $namespace, $this->getAllowedNamespaces(), true );
	}

	/**
	 * Batch-check existing pages against the feature, namespace and PageProps rules.
	 *
	 * @param iterable<PageIdentity> $pages
	 * @return array<int,true> Enabled page IDs.
	 */
	public function getEnabledPageIds( iterable $pages ): array {
		if ( !$this->isEnabled() ) {
			return [];
		}

		$candidates = [];
		foreach ( $pages as $page ) {
			$pageId = $page->getId();
			if ( $pageId > 0 && $this->isNamespaceAllowed( $page->getNamespace() ) ) {
				$candidates[$pageId] = $page;
			}
		}
		if ( !$candidates ) {
			return [];
		}

		$disabled = $this->pageProps->getProperties(
			$candidates,
			self::DISABLE_PAGE_PROPERTY
		);
		$enabled = [];
		foreach ( $candidates as $pageId => $page ) {
			// A valid behavior-switch property can contain the empty string.
			if ( !array_key_exists( $pageId, $disabled ) ) {
				$enabled[$pageId] = true;
			}
		}
		return $enabled;
	}

	public function isPageEnabled( PageIdentity $page ): bool {
		return isset( $this->getEnabledPageIds( [ $page ] )[ $page->getId() ] );
	}

	/**
	 * Recheck the page property on the caller's connection, normally the primary
	 * connection already holding a lock on the page row.
	 */
	public function isPageAllowedOnConnection(
		IReadableDatabase $db,
		int $pageId,
		int $namespace
	): bool {
		if ( !$this->isNamespaceAllowed( $namespace ) ) {
			return false;
		}

		$propertyPageId = $db->newSelectQueryBuilder()
			->select( 'pp_page' )
			->from( 'page_props' )
			->where( [
				'pp_page' => $pageId,
				'pp_propname' => self::DISABLE_PAGE_PROPERTY,
			] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();

		return $propertyPageId === false;
	}

	public function canProbablyLike(
		Authority $authority,
		PageIdentity $page,
		bool $pageAlreadyEnabled = false
	): bool {
		return $this->isEnabled()
			&& $this->areWritesEnabled()
			&& !$this->readOnlyMode->isReadOnly()
			&& $authority->isNamed()
			&& $authority->isAllowed( 'pagelike' )
			&& ( $pageAlreadyEnabled || $this->isPageEnabled( $page ) )
			&& $authority->probablyCan( 'read', $page )
			&& $authority->probablyCan( 'pagelike', $page );
	}

	public function canRead( Authority $authority, PageIdentity $page ): bool {
		return $authority->definitelyCan( 'read', $page );
	}

	/**
	 * Decide whether SkinAfterContent should emit the neutral mount point.
	 */
	public function shouldOutputMount( OutputPage $outputPage ): bool {
		// Article::view() sets this only after read authorization succeeds.
		if ( !$this->isEnabled() || !$outputPage->isArticle() ) {
			return false;
		}

		$request = $outputPage->getRequest();
		if ( $request->getVal( 'action', 'view' ) !== 'view'
			|| $request->getRawVal( 'diff' ) !== null
			|| $request->getRawVal( 'oldid' ) !== null
		) {
			return false;
		}

		$title = $outputPage->getTitle();
		if ( !$title || !$title->canExist() || $title->isSpecialPage()
			|| $title->getArticleID() <= 0
			|| !$this->isNamespaceAllowed( $title->getNamespace() )
		) {
			return false;
		}

		return $outputPage->getProperty( 'pagelike-disabled' ) === null;
	}
}
