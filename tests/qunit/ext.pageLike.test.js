'use strict';

QUnit.module( 'ext.pageLike', ( hooks ) => {
	const ui = require( 'ext.pageLike' );
	let originalIsNamed;
	let originalMatchMedia;

	hooks.beforeEach( () => {
		originalIsNamed = mw.user.isNamed;
		originalMatchMedia = window.matchMedia;
	} );

	hooks.afterEach( () => {
		mw.user.isNamed = originalIsNamed;
		window.matchMedia = originalMatchMedia;
	} );

	QUnit.test( 'initial pending state shows one heart and zero without loading text', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '122';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		let resolveRead;
		const mounted = ui.mount( root, {
			get: () => new Promise( ( resolve ) => {
				resolveRead = resolve;
			} )
		} );
		const heart = mounted.elements.icon.querySelector( '.ext-pagelike__heart' );
		const heartPath = heart.querySelector( '.ext-pagelike__heart-shape' );

		assert.ok( heart, 'the heart SVG is rendered immediately' );
		assert.ok( heartPath.getAttribute( 'd' ), 'the heart uses one reusable path' );
		assert.strictEqual( mounted.elements.icon.querySelectorAll( 'path' ).length, 1 );
		assert.strictEqual( mounted.elements.count.textContent, '0' );
		assert.strictEqual( mounted.elements.button.textContent, '0' );
		assert.strictEqual( mounted.elements.status.textContent, '', 'no loading copy is visible' );
		assert.true( mounted.elements.button.disabled );
		assert.strictEqual( mounted.elements.button.getAttribute( 'aria-busy' ), 'true' );

		resolveRead( {
			query: { pages: [ {
				pageid: 122,
				pagelikeinfo: { enabled: true, liked: false, count: 3, canlike: true }
			} ] }
		} );
		mounted.ready.then( () => {
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.strictEqual( mounted.elements.count.textContent, '3' );
			assert.strictEqual( mounted.elements.status.textContent, '' );
			assert.false( mounted.elements.button.disabled );
			assert.notOk( mounted.elements.button.hasAttribute( 'aria-busy' ) );
			done();
		} );
	} );

	QUnit.test( 'initial state and write keep the button and fire one hook', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '123';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		let reads = 0;
		let writeParams;
		const api = {
			get: () => {
				reads++;
				return Promise.resolve( {
					query: { pages: [ {
						pageid: 123,
						pagelikeinfo: { enabled: true, liked: false, count: 4, canlike: true }
					} ] }
				} );
			},
			postWithToken: ( tokenType, params ) => {
				assert.strictEqual( tokenType, 'csrf' );
				writeParams = params;
				return Promise.resolve( {
					pagelike: { enabled: true, liked: true, count: 5 }
				} );
			}
		};
		const mounted = ui.mount( root, api );
		const button = mounted.elements.button;
		const heart = mounted.elements.icon.querySelector( '.ext-pagelike__heart' );
		const heartPath = heart.querySelector( '.ext-pagelike__heart-shape' );
		const heartShape = heartPath.getAttribute( 'd' );
		let hookCalls = 0;
		const listener = ( event ) => {
			hookCalls++;
			assert.strictEqual( event.root, root );
		};
		mw.hook( 'ext.pageLike.changed' ).add( listener );

		mounted.ready.then( () => {
			assert.strictEqual( mounted.elements.count.textContent, '4' );
			assert.strictEqual( mounted.elements.icon.querySelector( 'svg' ), heart );
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.strictEqual( heartPath.getAttribute( 'd' ), heartShape );
			assert.false( root.classList.contains( 'is-liked' ), 'unliked state outlines the heart' );
			assert.strictEqual( button.textContent, '4', 'the SVG and count are the only visible content' );
			assert.strictEqual( button.getAttribute( 'aria-label' ), mw.msg( 'pagelike-button-like' ) );
			assert.notOk( button.querySelector( '.ext-pagelike__label' ), 'no visible text label is rendered' );
			assert.false( root.classList.contains( 'is-celebrating' ), 'initial state does not celebrate' );
			button.focus();
			button.click();
			return new Promise( ( resolve ) => {
				const changedHook = mw.hook( 'ext.pageLike.changed' );
				const resolveOnce = () => {
					changedHook.remove( resolveOnce );
					resolve();
				};
				changedHook.add( resolveOnce );
			} );
		} ).then( () => {
			assert.strictEqual( root.querySelector( 'button' ), button, 'same button node' );
			assert.strictEqual( document.activeElement, button, 'focus is preserved' );
			assert.true( root.classList.contains( 'is-liked' ) );
			assert.strictEqual( button.getAttribute( 'aria-pressed' ), 'true' );
			assert.strictEqual( mounted.elements.icon.querySelector( 'svg' ), heart );
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.strictEqual( heartPath.getAttribute( 'd' ), heartShape );
			assert.strictEqual( button.textContent, '5', 'the updated count remains visible' );
			assert.strictEqual( button.getAttribute( 'aria-label' ), mw.msg( 'pagelike-button-unlike' ) );
			assert.true( root.classList.contains( 'is-celebrating' ), 'successful like celebrates once' );
			assert.strictEqual( hookCalls, 1 );
			assert.strictEqual( reads, 1, 'write response is authoritative; no follow-up GET' );
			assert.strictEqual( writeParams.set, 1, 'explicit like state is sent' );
			mw.hook( 'ext.pageLike.changed' ).remove( listener );
			done();
		} );
	} );

	QUnit.test( 'initial liked state uses a solid heart without celebration', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '124';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		const mounted = ui.mount( root, {
			get: () => Promise.resolve( {
				query: { pages: [ {
					pageid: 124,
					pagelikeinfo: { enabled: true, liked: true, count: 5, canlike: true }
				} ] }
			} )
		} );

		mounted.ready.then( () => {
			assert.strictEqual( mounted.elements.icon.querySelectorAll( 'svg' ).length, 1 );
			assert.strictEqual( mounted.elements.icon.querySelectorAll( 'path' ).length, 1 );
			assert.strictEqual(
				mounted.elements.button.getAttribute( 'aria-label' ),
				mw.msg( 'pagelike-button-unlike' )
			);
			assert.true( root.classList.contains( 'is-liked' ) );
			assert.false( root.classList.contains( 'is-celebrating' ) );
			done();
		} );
	} );

	QUnit.test( 'unlike resets feedback and a later like can celebrate again', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '125';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		const writes = [];
		const responses = [
			{ enabled: true, liked: true, count: 1 },
			{ enabled: true, liked: false, count: 0 },
			{ enabled: true, liked: true, count: 1 }
		];
		const mounted = ui.mount( root, {
			get: () => Promise.resolve( {
				query: { pages: [ {
					pageid: 125,
					pagelikeinfo: { enabled: true, liked: false, count: 0, canlike: true }
				} ] }
			} ),
			postWithToken: ( tokenType, params ) => {
				assert.strictEqual( tokenType, 'csrf' );
				writes.push( params.set );
				return Promise.resolve( { pagelike: responses[ writes.length - 1 ] } );
			}
		} );
		const heartPath = mounted.elements.icon.querySelector( 'path' );
		const clickAndWait = () => new Promise( ( resolve ) => {
			const changedHook = mw.hook( 'ext.pageLike.changed' );
			const resolveOnce = () => {
				changedHook.remove( resolveOnce );
				resolve();
			};
			changedHook.add( resolveOnce );
			mounted.elements.button.click();
		} );

		mounted.ready.then( clickAndWait ).then( () => {
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.true( root.classList.contains( 'is-celebrating' ) );
			return clickAndWait();
		} ).then( () => {
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.false( root.classList.contains( 'is-liked' ) );
			assert.false( root.classList.contains( 'is-celebrating' ), 'unlike clears celebration state' );
			return clickAndWait();
		} ).then( () => {
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.true( root.classList.contains( 'is-liked' ) );
			assert.true( root.classList.contains( 'is-celebrating' ), 'later like can celebrate again' );
			assert.deepEqual( writes, [ 1, 0, 1 ], 'each write sends an explicit target state' );
			done();
		} );
	} );

	QUnit.test( 'celebration respects reduced motion', ( assert ) => {
		const root = document.createElement( 'div' );
		window.matchMedia = () => ( { matches: false } );
		ui.celebrate( root );
		assert.true( root.classList.contains( 'is-celebrating' ), 'animation is enabled normally' );

		root.classList.remove( 'is-celebrating' );
		window.matchMedia = () => ( { matches: true } );
		ui.celebrate( root );
		assert.false( root.classList.contains( 'is-celebrating' ), 'animation is skipped when reduced' );
	} );

	QUnit.test( 'pending state suppresses rapid repeated clicks', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '321';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		let resolveWrite;
		let writes = 0;
		const api = {
			get: () => Promise.resolve( {
				query: { pages: [ {
					pageid: 321,
					pagelikeinfo: { enabled: true, liked: false, count: 0, canlike: true }
				} ] }
			} ),
			postWithToken: () => {
				writes++;
				return new Promise( ( resolve ) => {
					resolveWrite = resolve;
				} );
			}
		};
		const mounted = ui.mount( root, api );
		mounted.ready.then( () => {
			mounted.elements.button.click();
			mounted.elements.button.click();
			assert.strictEqual( writes, 1 );
			assert.true( root.classList.contains( 'is-pending' ) );
			assert.strictEqual( mounted.elements.button.getAttribute( 'aria-busy' ), 'true' );
			resolveWrite( { pagelike: { enabled: true, liked: true, count: 1 } } );
			return Promise.resolve();
		} ).then( () => {
			done();
		} );
	} );

	QUnit.test( 'anonymous and temporary accounts see count with an accessible login label', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => false;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '456';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		const mounted = ui.mount( root, {
			get: () => Promise.resolve( {
				query: { pages: [ {
					pageid: 456,
					pagelikeinfo: { enabled: true, liked: false, count: 7, canlike: false }
				} ] }
			} )
		} );
		mounted.ready.then( () => {
			assert.strictEqual( mounted.elements.count.textContent, '7' );
			assert.strictEqual( mounted.elements.button.textContent, '7' );
			assert.strictEqual( mounted.elements.icon.querySelectorAll( 'path' ).length, 1 );
			assert.strictEqual(
				mounted.elements.button.getAttribute( 'aria-label' ),
				mw.msg( 'pagelike-button-login' )
			);
			assert.notOk(
				mounted.elements.button.querySelector( '.ext-pagelike__label' ),
				'login guidance is not visibly rendered'
			);
			assert.true( mounted.elements.button.disabled );
			done();
		} );
	} );

	QUnit.test( 'an initial read failure is exposed in the live region', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '654';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		const mounted = ui.mount( root, {
			get: () => Promise.reject( { info: 'Read failed' } )
		} );

		mounted.ready.then( () => {
			assert.true( root.classList.contains( 'is-error' ) );
			assert.strictEqual( mounted.elements.status.textContent, 'Read failed' );
			assert.strictEqual( mounted.elements.status.getAttribute( 'aria-live' ), 'polite' );
			assert.true( mounted.elements.button.disabled );
			done();
		} );
	} );

	QUnit.test( 'a write failure restores focus and does not fire the change hook', ( assert ) => {
		const done = assert.async();
		mw.user.isNamed = () => true;
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '987';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		let rejectWrite;
		let writePromise;
		const mounted = ui.mount( root, {
			get: () => Promise.resolve( {
				query: { pages: [ {
					pageid: 987,
					pagelikeinfo: { enabled: true, liked: false, count: 2, canlike: true }
				} ] }
			} ),
			postWithToken: () => {
				writePromise = new Promise( ( ...callbacks ) => {
					rejectWrite = callbacks[ 1 ];
				} );
				return writePromise;
			}
		} );
		const heartPath = mounted.elements.icon.querySelector( 'path' );
		let hookCalls = 0;
		const listener = () => {
			hookCalls++;
		};
		mw.hook( 'ext.pageLike.changed' ).add( listener );

		mounted.ready.then( () => {
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.false( root.classList.contains( 'is-liked' ) );
			mounted.elements.button.focus();
			mounted.elements.button.click();
			rejectWrite( { info: 'Write failed' } );
			return writePromise.catch( () => {} );
		} ).then( () => {
			assert.true( root.classList.contains( 'is-error' ) );
			assert.strictEqual( mounted.elements.status.textContent, 'Write failed' );
			assert.strictEqual( document.activeElement, mounted.elements.button );
			assert.strictEqual( mounted.elements.icon.querySelector( 'path' ), heartPath );
			assert.false( root.classList.contains( 'is-liked' ), 'failed write keeps outline heart' );
			assert.false( root.classList.contains( 'is-celebrating' ), 'failed write does not celebrate' );
			assert.strictEqual( hookCalls, 0 );
			mw.hook( 'ext.pageLike.changed' ).remove( listener );
			done();
		} );
	} );

	QUnit.test( 'a second mount does not duplicate the button', ( assert ) => {
		const root = document.createElement( 'div' );
		root.className = 'ext-pagelike';
		root.dataset.pageId = '741';
		document.getElementById( 'qunit-fixture' ).appendChild( root );
		const api = { get: () => new Promise( () => {} ) };

		assert.notStrictEqual( ui.mount( root, api ), null );
		assert.strictEqual( ui.mount( root, api ), null );
		assert.strictEqual( root.querySelectorAll( 'button' ).length, 1 );
		assert.strictEqual( root.querySelectorAll( '.ext-pagelike__heart' ).length, 1 );
		assert.strictEqual( root.querySelectorAll( '.ext-pagelike__heart-shape' ).length, 1 );
	} );
} );
