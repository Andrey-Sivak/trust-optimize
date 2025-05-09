# TrustOptimize

Advanced media optimization solution for WordPress that dynamically optimizes images based on visitor's device capabilities and viewport size.

## Structure

- `includes/`: PHP classes and core functionality
    - `core/`: Core plugin functionality
    - `admin/`: Admin-related functionality
    - `frontend/`: Frontend-related functionality
    - `features/`: Plugin features
        - `optimization/`: Image optimization functionality
    - `api/`: REST API controllers
    - `utils/`: Utility classes
- `templates/`: Template files
    - `admin/`: Admin templates
    - `frontend/`: Frontend templates
- `assets/`: Frontend assets
    - `js/`: JavaScript files
    - `css/`: CSS files
    - `images/`: Images
- `languages/`: Translation files
- `vendor/`: Composer dependencies
- `tests/`: Test files

## Development

## Features

- WebP conversion support

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- GD or Imagick extension

### Installation

1. Clone the repository
2. Run `composer install`
3. Activate the plugin in WordPress

### Development Workflow

1. Make changes to the code
2. Run `composer phpcs` to check for coding standard issues
3. Run `composer phpcbf` to fix coding standard issues
4. Run `composer test` to run unit tests
