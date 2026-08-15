/**
 * TrustOptimize Frontend JavaScript (Vanilla JS)
 *
 * Handles dynamic image adaptation based on viewport and container size
 */
(function () {
    'use strict';

    const TrustOptimize = {

        init() {
            this.initAdaptiveImages();
            this.bindEvents();
        },

        initAdaptiveImages() {
            const adaptiveImages = document.querySelectorAll('img[data-adaptive="true"]');

            adaptiveImages.forEach((img) => {
                this.setupAdaptiveImage(img);
            });
        },

        bindEvents() {
            let resizeTimer;

            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    this.refreshAdaptiveImages();
                }, 250);
            });

            window.addEventListener('orientationchange', () => {
                this.refreshAdaptiveImages();
            });
        },

        setupAdaptiveImage(img) {
            // Store original dimensions
            const originalWidth = img.getAttribute('width') || '';
            const originalHeight = img.getAttribute('height') || '';

            img.setAttribute('data-original-width', originalWidth);
            img.setAttribute('data-original-height', originalHeight);

            // Initial sizing
            this.updateImageSize(img);

            // Lazy loading via IntersectionObserver
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries, obs) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            this.loadAdaptiveImage(entry.target);
                            obs.unobserve(entry.target);
                        }
                    });
                });

                observer.observe(img);
            } else {
                // Fallback
                this.loadAdaptiveImage(img);
            }
        },

        updateImageSize(img) {
            const container = img.parentElement;
            if (!container) return;

            const containerWidth = container.clientWidth;
            if (!containerWidth) return;

            const dpr = window.devicePixelRatio || 1;
            const optimalWidth = Math.round(containerWidth * dpr);

            const currentWidth = parseInt(img.getAttribute('data-current-width')) || 0;

            // Avoid unnecessary updates
            if (Math.abs(optimalWidth - currentWidth) <= 50) return;

            const originalSrc = img.getAttribute('data-original-src');
            if (!originalSrc) return;

            const newSrc = this.getAdaptiveUrl(originalSrc, optimalWidth);

            img.src = newSrc;
            img.setAttribute('data-current-width', optimalWidth);
        },

        loadAdaptiveImage(img) {
            img.classList.add('trust-optimize-loading');
            this.updateImageSize(img);
        },

        refreshAdaptiveImages() {
            const images = document.querySelectorAll('img[data-adaptive="true"]');

            images.forEach((img) => {
                this.updateImageSize(img);
            });
        },

        getAdaptiveUrl(originalSrc, width) {
            const url = new URL(originalSrc, window.location.href);

            url.searchParams.set('width', width);
            url.searchParams.set('trust_optimize', '1');
            url.searchParams.set('dpr', window.devicePixelRatio || 1);

            return url.href;
        }
    };

    // DOM ready
    document.addEventListener('DOMContentLoaded', () => {
        TrustOptimize.init();
    });

})();