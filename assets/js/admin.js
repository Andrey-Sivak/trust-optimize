/**
 * TrustOptimize Admin JavaScript
 *
 * Handles admin interface functionality
 */
(function($) {
    'use strict';

    // TrustOptimize Admin object
    var TrustOptimizeAdmin = {
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
            this.initTabs();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Settings form submission
            $('#trust-optimize-settings-form').on('submit', function(e) {
                // Form validation could go here
            });
            
            // Reset settings button
            $('#trust-optimize-reset-settings').on('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to reset all settings to defaults?')) {
                    // Reset logic
                    $('#trust-optimize-reset-form').submit();
                }
            });
        },

        /**
         * Initialize tabbed interface
         */
        initTabs: function() {
            $('.trust-optimize-tab-link').on('click', function(e) {
                e.preventDefault();
                
                // Get the target tab
                var target = $(this).attr('href');
                
                // Hide all tabs
                $('.trust-optimize-tab-content').hide();
                
                // Show target tab
                $(target).show();
                
                // Update active state
                $('.trust-optimize-tab-link').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
            });

            // Show the first tab by default
            $('.trust-optimize-tab-link:first').click();
        }
    };

    // Initialize when the document is ready
    $(document).ready(function() {
        TrustOptimizeAdmin.init();
    });

})(jQuery);