'use strict';

const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
const HEART_PATH = 'M12 21.25c-0.31 0-0.61-0.12-0.84-0.34l-7.11-6.64' +
	'c-3.03-2.83-3.18-7.42-0.33-10.21 2.31-2.26 5.87-2.16 8.28 0.09 ' +
	'2.41-2.25 5.97-2.35 8.28-0.09 2.85 2.79 2.7 7.38-0.33 10.21l-7.11 6.64' +
	'c-0.23 0.22-0.53 0.34-0.84 0.34z';

function appendElement( parent, tagName, className, text ) {
	const element = document.createElement( tagName );
	element.className = className;
	if ( text !== undefined ) {
		element.textContent = text;
	}
	parent.appendChild( element );
	return element;
}

function createButton( root ) {
	const button = appendElement( root, 'button', 'ext-pagelike__button' );
	button.type = 'button';
	button.setAttribute( 'aria-pressed', 'false' );
	button.setAttribute(
		'aria-label',
		mw.msg( mw.user.isNamed() ? 'pagelike-button-like' : 'pagelike-button-login' )
	);

	const icon = appendElement( button, 'span', 'ext-pagelike__icon' );
	icon.setAttribute( 'aria-hidden', 'true' );
	const heart = document.createElementNS( SVG_NAMESPACE, 'svg' );
	heart.setAttribute( 'class', 'ext-pagelike__heart' );
	heart.setAttribute( 'viewBox', '0 0 24 24' );
	heart.setAttribute( 'focusable', 'false' );
	const heartPath = document.createElementNS( SVG_NAMESPACE, 'path' );
	heartPath.setAttribute( 'class', 'ext-pagelike__heart-shape' );
	heartPath.setAttribute( 'd', HEART_PATH );
	heart.appendChild( heartPath );
	icon.appendChild( heart );
	const count = appendElement( button, 'span', 'ext-pagelike__count', '0' );
	const status = appendElement( root, 'span', 'ext-pagelike__status' );
	status.setAttribute( 'aria-live', 'polite' );

	return { button, icon, count, status };
}

function setPending( root, elements, pending ) {
	root.classList.toggle( 'is-pending', pending );
	elements.button.disabled = pending;
	if ( pending ) {
		elements.button.setAttribute( 'aria-busy', 'true' );
	} else {
		elements.button.removeAttribute( 'aria-busy' );
	}
}

function render( root, elements, state ) {
	root.hidden = !state.enabled;
	root.classList.toggle( 'is-liked', state.liked );
	elements.button.setAttribute( 'aria-pressed', state.liked ? 'true' : 'false' );
	elements.count.textContent = String( state.count );

	if ( !mw.user.isNamed() ) {
		elements.button.setAttribute( 'aria-label', mw.msg( 'pagelike-button-login' ) );
	} else {
		elements.button.setAttribute( 'aria-label', mw.msg(
			state.liked ? 'pagelike-button-unlike' : 'pagelike-button-like'
		) );
	}
	elements.button.disabled = !state.canlike;
}

function getPageInfo( response, pageId ) {
	const pages = response && response.query && response.query.pages;
	if ( !Array.isArray( pages ) ) {
		throw new Error( 'Invalid pagelikeinfo response' );
	}
	const page = pages.find( ( candidate ) => Number( candidate.pageid ) === pageId );
	if ( !page || !page.pagelikeinfo ) {
		throw new Error( 'Missing pagelikeinfo response' );
	}
	return page.pagelikeinfo;
}

function showError( root, elements, error ) {
	root.classList.add( 'is-error' );
	const apiMessage = error && ( error.info || error.message );
	elements.status.textContent = apiMessage || mw.msg( 'pagelike-status-error' );
}

function restoreButtonFocus( root, elements, shouldRestore ) {
	if ( shouldRestore && !root.hidden && !elements.button.disabled ) {
		elements.button.focus();
	}
}

function celebrate( root ) {
	const reducedMotion = typeof window.matchMedia === 'function' &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	if ( !reducedMotion ) {
		root.classList.add( 'is-celebrating' );
	}
}

function mount( root, api ) {
	if ( root.dataset.pagelikeMounted === '1' ) {
		return null;
	}
	root.dataset.pagelikeMounted = '1';
	const pageId = Number( root.dataset.pageId );
	if ( !Number.isInteger( pageId ) || pageId <= 0 ) {
		return null;
	}

	const client = api || new mw.Api();
	const elements = createButton( root );
	const state = {
		enabled: true,
		liked: false,
		count: 0,
		canlike: false,
		pending: false
	};
	setPending( root, elements, true );

	const ready = client.get( {
		action: 'query',
		prop: 'pagelikeinfo',
		pageids: pageId,
		formatversion: 2
	} ).then( ( response ) => {
		Object.assign( state, getPageInfo( response, pageId ) );
		state.pending = false;
		root.classList.remove( 'is-error' );
		elements.status.textContent = '';
		setPending( root, elements, false );
		render( root, elements, state );
		return state;
	}, ( error ) => {
		state.pending = false;
		setPending( root, elements, false );
		render( root, elements, state );
		showError( root, elements, error );
		return state;
	} );

	elements.button.addEventListener( 'click', () => {
		if ( state.pending || !state.canlike ) {
			return;
		}
		const restoreFocus = document.activeElement === elements.button;
		state.pending = true;
		root.classList.remove( 'is-error' );
		elements.status.textContent = '';
		setPending( root, elements, true );

		client.postWithToken( 'csrf', {
			action: 'pagelike',
			pageid: pageId,
			set: state.liked ? 0 : 1,
			formatversion: 2
		} ).then( ( response ) => {
			const result = response.pagelike;
			const shouldCelebrate = !state.liked && Boolean( result.liked );
			state.enabled = Boolean( result.enabled );
			state.liked = Boolean( result.liked );
			state.count = Number( result.count );
			state.pending = false;
			setPending( root, elements, false );
			render( root, elements, state );
			if ( shouldCelebrate ) {
				celebrate( root );
			} else if ( !state.liked ) {
				root.classList.remove( 'is-celebrating' );
			}
			restoreButtonFocus( root, elements, restoreFocus );
			mw.hook( 'ext.pageLike.changed' ).fire( {
				root,
				pageId,
				liked: state.liked,
				count: state.count
			} );
		}, ( error ) => {
			state.pending = false;
			setPending( root, elements, false );
			render( root, elements, state );
			showError( root, elements, error );
			restoreButtonFocus( root, elements, restoreFocus );
		} );
	} );

	return { elements, ready, state };
}

module.exports = {
	celebrate,
	getPageInfo,
	mount,
	render,
	setPending
};
