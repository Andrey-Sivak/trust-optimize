/**
 * TrustOptimize - Media Library status polling.
 *
 * One request per round asks for the state of every attachment that is still queued or
 * processing. Polling stops when nothing is waiting any more, after 30 minutes, or when a
 * request fails (for example because the session expired).
 *
 * @package TrustOptimize
 */
( function() {
	'use strict';

	var POLL_INTERVAL = 5000;
	var MAX_DURATION = 30 * 60 * 1000;
	var MAX_IDS = 100;
	var WAITING_SELECTOR = '.trust-optimize-polling';
	var states = ( window.trustOptimizeMedia && window.trustOptimizeMedia.states ) || {};
	var timer = null;
	var startedAt = 0;

	/**
	 * Show a state in a status element; a state that is not waiting ends its polling.
	 *
	 * @param {Element} element Status element.
	 * @param {string}  state   AttachmentState value.
	 */
	function show( element, state ) {
		var presentation = states[ state ];
		var waiting = 'queued' === state || 'processing' === state;

		element.setAttribute( 'data-status', state );

		if ( ! waiting ) {
			element.classList.remove( 'trust-optimize-polling' );
		}

		if ( ! presentation ) {
			return;
		}

		element.style.color = presentation.color;
		element.textContent = '';

		if ( presentation.icon ) {
			var icon = document.createElement( 'span' );
			icon.className = 'dashicons ' + presentation.icon;
			element.appendChild( icon );
			element.appendChild( document.createTextNode( ' ' ) );
		}

		element.appendChild( document.createTextNode( presentation.label ) );
	}

	/**
	 * Ask for the states of the waiting elements and update them.
	 */
	function poll() {
		var elements = Array.prototype.slice.call( document.querySelectorAll( WAITING_SELECTOR ) );

		if ( 0 === elements.length || Date.now() - startedAt > MAX_DURATION ) {
			stop();
			return;
		}

		var byId = {};
		elements.forEach( function( element ) {
			byId[ element.getAttribute( 'data-attachment-id' ) ] = element;
		} );

		var requests = [];
		var ids = Object.keys( byId );
		for ( var i = 0; i < ids.length; i += MAX_IDS ) {
			requests.push(
				wp.apiFetch( { path: '/trust-optimize/v1/images/status?ids=' + ids.slice( i, i + MAX_IDS ).join( ',' ) } )
			);
		}

		Promise.all( requests ).then( function( responses ) {
			responses.forEach( function( response ) {
				Object.keys( response.states ).forEach( function( id ) {
					if ( byId[ id ] ) {
						show( byId[ id ], response.states[ id ] );
					}
				} );
			} );
		}, stop );
	}

	function stop() {
		if ( timer ) {
			clearInterval( timer );
			timer = null;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		if ( document.querySelector( WAITING_SELECTOR ) ) {
			startedAt = Date.now();
			poll();
			timer = setInterval( poll, POLL_INTERVAL );
		}
	} );
} )();
