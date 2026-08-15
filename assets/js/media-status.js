/**
 * TrustOptimize - Media Library Status Polling
 *
 * Polls the REST API for optimization status of images that are
 * currently being processed in the background.
 *
 * @package TrustOptimize
 */
( function( $ ) {
	'use strict';

	var POLL_INTERVAL = 5000; // 5 seconds
	var pollingTimer = null;

	/**
	 * Find all elements that need status polling.
	 *
	 * @return {jQuery} Collection of polling elements.
	 */
	function getPollingElements() {
		return $( '.trust-optimize-polling' );
	}

	/**
	 * Update the status display for a single attachment.
	 *
	 * @param {jQuery}  $element     The status element.
	 * @param {Object}  data         Response data from the REST API.
	 */
	function updateStatusDisplay( $element, data ) {
		if ( data.status === 'completed' ) {
			$element
				.removeClass( 'trust-optimize-polling' )
				.attr( 'data-status', 'completed' )
				.css( 'color', '#46b450' )
				.html(
					'<span class="dashicons dashicons-yes-alt"></span> Optimized'
				);
		} else if ( data.status === 'pending' || data.status === 'processing' ) {
			$element
				.attr( 'data-status', data.status )
				.html(
					'<span class="dashicons dashicons-update spin"></span> ' +
					data.progress + '%'
				);
		} else if ( data.status === 'failed' ) {
			$element
				.removeClass( 'trust-optimize-polling' )
				.attr( 'data-status', 'failed' )
				.css( 'color', '#dc3232' )
				.html(
					'<span class="dashicons dashicons-warning"></span> Failed'
				);
		}
	}

	/**
	 * Poll status for all in-progress items.
	 */
	function pollStatuses() {
		var $items = getPollingElements();

		if ( $items.length === 0 ) {
			stopPolling();
			return;
		}

		$items.each( function() {
			var $el = $( this );
			var attachmentId = $el.data( 'attachment-id' );

			if ( ! attachmentId ) {
				return;
			}

			$.ajax( {
				url: trustOptimizeMedia.restUrl + 'image/' + attachmentId + '/status',
				method: 'GET',
				beforeSend: function( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', trustOptimizeMedia.nonce );
				},
				success: function( data ) {
					updateStatusDisplay( $el, data );
				}
			} );
		} );
	}

	/**
	 * Start the polling interval.
	 */
	function startPolling() {
		if ( pollingTimer ) {
			return;
		}

		// Run immediately once, then set interval
		pollStatuses();
		pollingTimer = setInterval( pollStatuses, POLL_INTERVAL );
	}

	/**
	 * Stop the polling interval.
	 */
	function stopPolling() {
		if ( pollingTimer ) {
			clearInterval( pollingTimer );
			pollingTimer = null;
		}
	}

	// Initialize when DOM is ready
	$( function() {
		if ( getPollingElements().length > 0 ) {
			startPolling();
		}
	} );

} )( jQuery );
