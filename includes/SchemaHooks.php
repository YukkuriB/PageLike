<?php

namespace MediaWiki\Extension\PageLike;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Schema registration must not depend on the service container.
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'pagelike_like',
			__DIR__ . '/../sql/mysql/tables-generated.sql'
		);
		$updater->addExtensionTable(
			'pagelike_notification_dedupe',
			__DIR__ . '/../sql/mysql/patch-add-pagelike-notification-dedupe.sql'
		);
	}
}
