<?php

namespace MediaWiki\Extension\PageLike;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;

/**
 * Renders the Echo notification sent to the creator of a liked page.
 */
class PageLikePresentationModel extends EchoEventPresentationModel {
	/** @inheritDoc */
	public function canRender() {
		$title = $this->event->getTitle();
		return $title && $this->getUser()->definitelyCan( 'read', $title );
	}

	/** @inheritDoc */
	public function getIconType() {
		return 'site';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		$msg = parent::getHeaderMessage();
		$msg->params( $this->getTruncatedTitleText( $this->event->getTitle(), true ) );
		$msg->params( $this->getViewingUserForGender() );
		return $msg;
	}

	/** @inheritDoc */
	public function getCompactHeaderMessage() {
		$msg = parent::getCompactHeaderMessage();
		$msg->params( $this->getTruncatedTitleText( $this->event->getTitle(), true ) );
		$msg->params( $this->getViewingUserForGender() );
		return $msg;
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'notification-link-text-view-page' )->text(),
		];
	}

	/** @inheritDoc */
	public function getSecondaryLinks() {
		return array_values( array_filter( [ $this->getAgentLink() ] ) );
	}
}
