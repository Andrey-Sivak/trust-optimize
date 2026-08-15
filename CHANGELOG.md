# Changelog

All notable changes to the TrustOptimize plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-05-05

### Added
- Asynchronous image conversion via Action Scheduler — uploads return instantly.
- `ConversionQueue` class for managing background conversion tasks.
- Queue status tracking columns (`status`, `total_tasks`, `completed_tasks`) in `trust_optimize_images` table.
- REST endpoint `GET /trust-optimize/v1/image/{id}/status` for polling optimization progress.
- "Optimization" status column in the Media Library list view.
- JavaScript polling script for real-time status updates in Media Library.
- Graceful degradation in `ImageProcessor` — serves original `<img>` while conversions are in progress.
- Transient caching for format lookups on completed images.
- Per-request object cache in `ImageModel` to avoid repeated DB queries.

### Changed
- `ImageConverter::handle_image_upload()` now schedules async tasks instead of converting synchronously.
- Minimum PHP version raised from 7.4 to 8.0.
- Database schema version bumped to 1.1.0.

### Dependencies
- Added `woocommerce/action-scheduler` ^3.8 as a runtime dependency.
