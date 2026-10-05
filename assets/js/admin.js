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

			if ($('.trust-optimize-bulk-status').length) {
				this.refreshBulkStatus();
			}
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
				if (window.confirm(window.trustOptimizeAdmin.i18n.confirmReset)) {
					$('#trust-optimize-reset-form').submit();
				}
			});

			$('.trust-optimize-bulk-action').on('click', this.handleBulkAction.bind(this));
			$('.trust-optimize-bulk-control').on('click', this.handleBulkControl.bind(this));
		},

		initTabs: function() {
			var self = this;

			if (!$('.trust-optimize-tabs').length) {
				return;
			}

			$('.trust-optimize-tabs .nav-tab, .trust-optimize-open-tab').on('click', function(e) {
				e.preventDefault();
				self.showTab($(this).data('tab'), true);
			});

			$(window).on('hashchange', function() {
				self.showTab(self.tabFromHash(), false);
			});

			this.showTab(this.tabFromHash(), false);
		},

		tabFromHash: function() {
			var name = window.location.hash.replace('#', '');

			return /^[a-z-]+$/.test(name) && $('#trust-optimize-tab-' + name).length ? name : 'overview';
		},

		showTab: function(name, updateHash) {
			$('.trust-optimize-panel').prop('hidden', true);
			$('#trust-optimize-tab-' + name).prop('hidden', false);
			$('.trust-optimize-tabs .nav-tab').each(function() {
				var active = $(this).data('tab') === name;

				$(this).toggleClass('nav-tab-active', active).attr('aria-current', active ? 'page' : null);
			});

			if (updateHash && window.history && history.replaceState) {
				history.replaceState(null, '', '#' + name);
			}
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

			this.setStatusText(this.text('working'));

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

		text: function(key) {
			var i18n = (window.trustOptimizeAdmin && window.trustOptimizeAdmin.i18n) || {};

			return i18n[key] || '';
		},

		/**
		 * Fill the numbered placeholders (%1$s, %2$s, ...) of a translated string.
		 */
		format: function(template, values) {
			return template.replace(/%(\d)\$s/g, function(match, index) {
				return values[parseInt(index, 10) - 1];
			});
		},

		/**
		 * The status of a job in words: "Optimizing — 45% (90 of 200)", "Paused — …", "Completed".
		 */
		describeJob: function(job, progress, finishing) {
			var i18n = (window.trustOptimizeAdmin && window.trustOptimizeAdmin.i18n) || {};
			var running = 'pending' === job.status || 'running' === job.status;
			var label = running ? (i18n.active || {})[job.type] : (i18n.statuses || {})[job.status];

			if (!label) {
				label = job.status;
			}

			if (!running && 'paused' !== job.status) {
				return label;
			}

			if (finishing) {
				return this.format(i18n.progressFinishing, [label]);
			}

			return this.format(i18n.progress, [label, progress + '%', job.processed, job.total]);
		},

		renderBulkStatus: function(response) {
			var job = response && response.job ? response.job : null;

			if (!job) {
				this.setStatusText(this.text('idle'));
				this.updateProgress(0);
				this.updateCounters(null);
				this.updateControls(null);
				this.schedulePolling(false);
				return;
			}

			var total = parseInt(job.total, 10) || 0;
			var processed = parseInt(job.processed, 10) || 0;
			var progress = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
			var finishing = 'running' === job.status && 0 === parseInt(job.in_flight, 10) && processed > 0 && processed >= total;

			this.setStatusText(this.describeJob(job, progress, finishing));
			this.updateProgress(progress);
			this.updateCounters(job);
			this.updateControls(job);
			this.schedulePolling('pending' === job.status || 'running' === job.status);
		},

		updateProgress: function(progress) {
			$('.trust-optimize-progress-bar span').css('width', progress + '%');
		},

		/**
		 * Show only the buttons that apply: start buttons wait for the active job, Pause and Resume replace each other.
		 */
		updateControls: function(job) {
			var status = job ? job.status : '';
			var active = 'pending' === status || 'running' === status || 'paused' === status;

			$('.trust-optimize-bulk-action').prop('disabled', active);
			$('.trust-optimize-bulk-control[data-action="pause"]').prop('hidden', !('pending' === status || 'running' === status));
			$('.trust-optimize-bulk-control[data-action="resume"]').prop('hidden', 'paused' !== status);
			$('.trust-optimize-bulk-control[data-action="cancel"]').prop('hidden', !active);
		},

		/**
		 * Show the counters of the job type and the last error only when there is one.
		 */
		updateCounters: function(job) {
			var $table = $('.trust-optimize-bulk-counters');

			$table.prop('hidden', !job);

			if (!job) {
				return;
			}

			$table.find('tr[data-types]').each(function() {
				$(this).prop('hidden', -1 === $(this).data('types').split(' ').indexOf(job.type));
			});

			$.each(['processed', 'skipped', 'failed_count', 'created_count', 'deleted_count'], function(index, field) {
				$table.find('[data-field="' + field + '"]').text(job[field] || 0);
			});

			$table.find('.trust-optimize-last-error').prop('hidden', !job.last_error);
			$table.find('[data-field="last_error"]').text(job.last_error || '');
		},

		renderError: function(error) {
			var message = error && error.message ? error.message : this.text('requestFailed');

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
