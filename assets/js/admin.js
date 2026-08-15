/**
 * TrustOptimize Admin JavaScript.
 *
 * Handles admin interface functionality.
 */
(function($) {
	'use strict';

	var pollingTimer = null;

	var TrustOptimizeAdmin = {
		init: function() {
			this.bindEvents();
			this.initTabs();
			this.configureApiFetch();
			this.refreshBulkStatus();
		},

		configureApiFetch: function() {
			if (window.wp && wp.apiFetch && window.trustOptimizeAdmin) {
				wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(window.trustOptimizeAdmin.nonce));
			}
		},

		bindEvents: function() {
			$('#trust-optimize-settings-form').on('submit', function() {
				return true;
			});

			$('#trust-optimize-reset-settings').on('click', function(e) {
				e.preventDefault();
				if (window.confirm('Are you sure you want to reset all settings to defaults?')) {
					$('#trust-optimize-reset-form').submit();
				}
			});

			$('.trust-optimize-bulk-action').on('click', this.handleBulkAction.bind(this));
			$('.trust-optimize-bulk-control').on('click', this.handleBulkControl.bind(this));
		},

		initTabs: function() {
			$('.trust-optimize-tab-link').on('click', function(e) {
				e.preventDefault();

				var target = $(this).attr('href');

				$('.trust-optimize-tab-content').hide();
				$(target).show();
				$('.trust-optimize-tab-link').removeClass('active nav-tab-active');
				$(this).addClass('active nav-tab-active');
			});

			$('.trust-optimize-tab-link:first').click();
		},

		handleBulkAction: function(e) {
			e.preventDefault();

			var action = $(e.currentTarget).data('action');
			var request;

			if ('remove' === action && !window.confirm(window.trustOptimizeAdmin.i18n.confirmRemove)) {
				return;
			}

			if ('inventory' === action) {
				request = {
					path: '/trust-optimize/v1/bulk/inventory',
					method: 'POST'
				};
			} else {
				request = {
					path: '/trust-optimize/v1/bulk/start',
					method: 'POST',
					data: {
						type: action,
						confirm: 'remove' === action
					}
				};
			}

			this.sendRequest(request);
		},

		handleBulkControl: function(e) {
			e.preventDefault();

			var action = $(e.currentTarget).data('action');

			if ('cancel' === action && !window.confirm(window.trustOptimizeAdmin.i18n.confirmCancel)) {
				return;
			}

			this.sendRequest({
				path: '/trust-optimize/v1/bulk/' + action,
				method: 'POST',
				data: {
					confirm: 'cancel' === action
				}
			});
		},

		sendRequest: function(request) {
			if (!(window.wp && wp.apiFetch)) {
				return;
			}

			this.setStatusText('Working…');

			wp.apiFetch(request)
				.then(this.renderBulkStatus.bind(this))
				.catch(this.renderError.bind(this));
		},

		refreshBulkStatus: function() {
			if (!(window.wp && wp.apiFetch)) {
				return;
			}

			wp.apiFetch({
				path: '/trust-optimize/v1/bulk/status'
			})
				.then(this.renderBulkStatus.bind(this))
				.catch(this.renderError.bind(this));
		},

		renderBulkStatus: function(response) {
			var job = response && response.job ? response.job : null;

			if (!job) {
				this.setStatusText('No active bulk job.');
				this.updateProgress(0);
				this.updateCounters({});
				this.schedulePolling(false);
				return;
			}

			var total = parseInt(job.total, 10) || 0;
			var processed = parseInt(job.processed, 10) || 0;
			var progress = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

			this.setStatusText('Job #' + job.id + ' — ' + job.status + ' — ' + progress + '%');
			this.updateProgress(progress);
			this.updateCounters(job);
			this.schedulePolling('pending' === job.status || 'running' === job.status);
		},

		updateProgress: function(progress) {
			$('.trust-optimize-progress-bar span').css('width', progress + '%');
		},

		updateCounters: function(job) {
			var defaults = {
				status: '—',
				processed: 0,
				skipped: 0,
				failed_count: 0,
				created_count: 0,
				deleted_count: 0,
				cursor_id: 0,
				last_error: '—'
			};

			$.each(defaults, function(field, defaultValue) {
				var value = job && job[field] ? job[field] : defaultValue;
				$('.trust-optimize-bulk-counters [data-field="' + field + '"]').text(value);
			});
		},

		renderError: function(error) {
			var message = error && error.message ? error.message : 'Request failed.';

			this.setStatusText(message);
		},

		setStatusText: function(text) {
			$('.trust-optimize-bulk-status').text(text);
		},

		schedulePolling: function(enabled) {
			if (pollingTimer) {
				window.clearTimeout(pollingTimer);
				pollingTimer = null;
			}

			if (enabled) {
				pollingTimer = window.setTimeout(this.refreshBulkStatus.bind(this), 5000);
			}
		}
	};

	$(document).ready(function() {
		TrustOptimizeAdmin.init();
	});
})(jQuery);
