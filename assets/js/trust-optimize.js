/**
 * TrustOptimize Frontend JavaScript
 *
 * Handles dynamic image adaptation based on viewport and container size
 */
(function($) {
    'use strict';

    // TrustOptimize main object
    var TrustOptimize = {
        /**
         * Initialize the functionality
         */
        init: function() {
            this.initAdaptiveImages();
            this.bindEvents();
        },

        /**
         * Initialize adaptive images
         */
        initAdaptiveImages: function() {
            // Find all images with data-adaptive attribute
            var adaptiveImages = $('img[data-adaptive="true"]');
            
            // Process each image
            adaptiveImages.each(function() {
                TrustOptimize.setupAdaptiveImage($(this));
            });
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Handle window resize
            var resizeTimer;
            $(window).on('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    TrustOptimize.refreshAdaptiveImages();
                }, 250);
            });
            
            // Handle orientation change
            $(window).on('orientationchange', function() {
                TrustOptimize.refreshAdaptiveImages();
            });
        },

        /**
         * Set up an individual adaptive image
         * 
         * @param {jQuery} $img The image element
         */
        setupAdaptiveImage: function($img) {
            // Store original dimensions
            var originalWidth = $img.attr('width') || '';
            var originalHeight = $img.attr('height') || '';
            
            $img.attr('data-original-width', originalWidth);
            $img.attr('data-original-height', originalHeight);
            
            // Set container-based size
            this.updateImageSize($img);
            
            // Set up lazy loading if enabled
            if (typeof IntersectionObserver !== 'undefined') {
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            TrustOptimize.loadAdaptiveImage($(entry.target));
                            observer.unobserve(entry.target);
                        }
                    });
                });
                
                observer.observe($img[0]);
            } else {
                // Fallback for browsers without IntersectionObserver
                this.loadAdaptiveImage($img);
            }
        },

        /**
         * Update image size based on container
         * 
         * @param {jQuery} $img The image element
         */
        updateImageSize: function($img) {
            // Get container width
            var $container = $img.parent();
            var containerWidth = $container.width();
            
            // Get device pixel ratio
            var dpr = window.devicePixelRatio || 1;
            
            // Calculate optimal width
            var optimalWidth = Math.round(containerWidth * dpr);
            
            // Update src attribute with optimal size
            var currentWidth = $img.attr('data-current-width') || 0;
            
            // Only update if significantly different (to avoid thrashing)
            if (Math.abs(optimalWidth - currentWidth) > 50) {
                var originalSrc = $img.attr('data-original-src');
                if (originalSrc) {
                    var newSrc = this.getAdaptiveUrl(originalSrc, optimalWidth);
                    $img.attr('src', newSrc);
                    $img.attr('data-current-width', optimalWidth);
                }
            }
        },

        /**
         * Load adaptive image
         * 
         * @param {jQuery} $img The image element
         */
        loadAdaptiveImage: function($img) {
            $img.addClass('trust-optimize-loading');
            this.updateImageSize($img);
        },

        /**
         * Refresh all adaptive images
         */
        refreshAdaptiveImages: function() {
            $('img[data-adaptive="true"]').each(function() {
                TrustOptimize.updateImageSize($(this));
            });
        },

        /**
         * Get adaptive URL for an image
         * 
         * @param {string} originalSrc The original image URL
         * @param {number} width The target width
         * @return {string} The adaptive URL
         */
        getAdaptiveUrl: function(originalSrc, width) {
            // Parse the URL
            var url = new URL(originalSrc, window.location.href);
            
            // Add or update parameters
            url.searchParams.set('width', width);
            url.searchParams.set('trust_optimize', 1);
            url.searchParams.set('dpr', window.devicePixelRatio || 1);
            
            return url.href;
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        TrustOptimize.init();
    });

})(jQuery);