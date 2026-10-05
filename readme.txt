=== TrustOptimize ===
Contributors: andreysivak
Donate link:
Tags: optimization, images, performance, media, webp, avif
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Converts the image sizes WordPress creates into WebP and AVIF and serves them through <picture>, on your own server.

== Description ==

TrustOptimize creates WebP and AVIF copies of every JPEG and PNG size that WordPress generates and serves them to browsers that support them. The originals are never modified or replaced.

= Features =

* **WebP and AVIF conversion** of every size WordPress generates for JPEG and PNG uploads. Which formats the server can write is detected automatically.
* **`<picture>` on top of WordPress' own `srcset`**: the `<source>` elements reuse the `srcset` and `sizes` that WordPress calculates, only the URLs and the `type` change, so crops and responsive sizes stay correct. A format is offered only when it has a finished variant for every size listed in the `srcset`; otherwise the original `<img>` is left untouched.
* **Background conversion** with Action Scheduler: uploads return immediately, one task per image, a failing image does not block the others.
* **Bulk conversion and removal** of the whole media library from the settings page or WP-CLI, with progress, pause, resume and cancel.
* **Safe by design**: a converted file is written to a temporary file and renamed, a variant that is not smaller than its source is not kept, huge images and low disk space pause the work instead of failing the server. A file that another attachment still uses, and the original file of another attachment, is never deleted or overwritten.
* **Site Health tests** for background tasks, supported image formats and free disk space.
* **WP-CLI**: `wp trust-optimize` (inventory, sync, status, pause, resume, cancel, remove, sync-attachment, remove-attachment).
* **REST API** for the Media Library status column and bulk jobs.
* No external services: all processing happens on your server.

Variants are stored next to the original and named after it with the format appended: `photo.jpg` gets `photo.jpg.webp` and `photo.jpg.avif`.

= Requirements =

* PHP 8.0 or newer
* WordPress 6.5 or newer
* MySQL 5.7 or newer, or MariaDB 10.3 or newer
* GD or Imagick with WebP (and optionally AVIF) support

= Privacy Notice =

TrustOptimize processes images locally on your server and does not send any data to external services.

== Installation ==

1. Install the release archive (it contains its dependencies) or upload the `trust-optimize` folder to `/wp-content/plugins/`. When you work from a git checkout, run `composer install --no-dev` in the plugin folder.
2. Activate the plugin through the 'Plugins' menu.
3. Open the TrustOptimize page, check the formats and quality, and start a bulk conversion for the images that are already in your library.

== Frequently Asked Questions ==

= Does this plugin send my images to an external service? =

No. Everything happens on your server.

= Which images are converted? =

JPEG and PNG attachments and all their generated sizes. WebP and AVIF originals, GIF and other types are not converted. Originals are never changed or compressed.

= Which formats does my server support? =

TrustOptimize asks WordPress which formats its image editor (GD or Imagick) can write and stores the answer. If a conversion proves that a format does not work, the format is switched off for that environment and a notice is shown. See Tools > Site Health for the check.

= Why was an image not converted? =

Typical reasons, shown in the Media Library and by `wp trust-optimize status`: the converted file would not be smaller than the original (`not_smaller`), the image exceeds the pixel limit, the source file is missing, or free disk space is below the threshold and the work is paused. The limits are the settings "Largest image to convert" and "Minimum free disk space".

= The conversion does not run, or runs slowly =

Conversion runs in Action Scheduler tasks that are started by WP-Cron, which needs site visits. On a production site run the queue from the system cron every minute:

`* * * * * cd /path/to/site && wp action-scheduler run --quiet`

= How do I use it on a multisite network? =

Activate the plugin for the network. Every site has its own settings, its own database tables (`{prefix}trust_optimize_*`), its own queue and its own uploads folder (`uploads/sites/N`). A site creates its tables on its first request after activation.

The queue of a site is processed only in the context of that site. The system cron of a network must therefore visit every site, otherwise only the main site is processed:

`wp site list --field=url | xargs -I{} wp action-scheduler run --url={} --quiet`

= Does this plugin work with CDNs? =

Yes, when the CDN serves the uploads directory under the same path as your site, for example `https://cdn.example.com/wp-content/uploads/...`. Add the CDN host:

`add_filter( 'trust_optimize_cdn_hosts', fn( $hosts ) => array_merge( $hosts, array( 'cdn.example.com' ) ) );`

The CDN has to serve `.webp` and `.avif` files from the same origin. If it rewrites image URLs itself, switch the delivery off for it with the `trust_optimize_should_render` filter.

= Does it change lazy loading? =

No. `loading`, `fetchpriority` and `decoding` stay as WordPress sets them, so the largest image of a page is not delayed. The setting "Force lazy loading" adds `loading="lazy"` to images that have no `loading` attribute and are not `fetchpriority="high"`. To change a single image use the standard `$attr` argument of `wp_get_attachment_image()`, or the `trust_optimize_img_attributes` filter.

= I changed the settings while a bulk conversion was running. =

Images processed after the change use the new settings; images processed before it keep their variants. Run the conversion again (the TrustOptimize page or `wp trust-optimize sync`) to bring the whole library in line with the current settings.

= What happens on uninstall? =

By default nothing is deleted. With the setting "Remove data on uninstall" the plugin deletes the generated variants, its tables and options (on a network: for every site that has the setting on).

If the removal of the files could not be finished in one request, the tables and options are kept, and the option `trust_optimize_pending_cleanup` records how many files remain. Install and activate the plugin again, run "Remove Generated Files" on the TrustOptimize page (or `wp trust-optimize remove --all --yes --wait`) and delete the plugin once more.

= Uninstall on a large multisite network =

Deleting the plugin from the network admin cleans up every site in one web request, with a limited amount of work per site. If the request is interrupted (for example by a time limit), the sites that were not reached keep all their data, and nothing is deleted half-way: a site's tables and options are dropped only after its generated files are gone. Delete the plugin again to continue.

On a large network run the removal from the command line, where a web request's time limit does not apply, and repeat the command if it was interrupted:

`wp plugin uninstall trust-optimize --deactivate`

Sites without the setting "Remove data on uninstall" are left untouched. If `trust_optimize_pending_cleanup` remains on a site afterwards, see "What happens on uninstall?" above.

= Settings =

* Serve optimized images (on by default): the `<picture>` delivery.
* Create WebP / Create AVIF, and the quality of each (1-100, defaults 85 and 80). A changed quality applies to images converted from then on.
* Force lazy loading (off by default).
* Largest image to convert, in pixels (default 50,000,000): larger images are skipped without being decoded.
* Minimum free disk space in MB (0 = automatic: the larger of 1 GB and 5% of the volume).
* Remove data on uninstall (off by default).
* Reset to Defaults.

= WP-CLI =

* `wp trust-optimize inventory` – counts per type, state and variant status.
* `wp trust-optimize sync [--yes] [--wait] [--now] [--batch-size=<n>]` – convert the library.
* `wp trust-optimize status | pause | resume | cancel` – show and control the bulk job.
* `wp trust-optimize remove --all --yes [--wait]` – delete all variants.
* `wp trust-optimize sync-attachment <id>` and `remove-attachment <id>` – one image.

= REST API =

All routes are under `/wp-json/trust-optimize/v1/`. The status route needs the capability `upload_files`, every other route `manage_options`.

* `GET /images/status?ids=1,2,3` – status of several attachments.
* `GET /status`, `POST /bulk/inventory`, `POST /bulk/start`, `GET /bulk/status`, `POST /bulk/{pause|resume|cancel}` – library status and bulk jobs.
* `POST /image/{id}/sync`, `POST /image/{id}/remove` – one attachment.

= Hooks =

Filters:

* `trust_optimize_should_render` ( bool $render, string $context, int $attachment_id ) – turn the `<picture>` delivery off for an image.
* `trust_optimize_img_attributes` ( array $attrs, int $attachment_id, string $context ) – attributes set on the `<img>`; `null` or `false` removes an attribute.
* `trust_optimize_cdn_hosts` ( string[] $hosts ) – extra hosts that serve the uploads directory.
* `trust_optimize_webp_quality` and `trust_optimize_avif_quality` ( int $quality ) – quality of new variants.
* `trust_optimize_max_pixels` ( int $pixels ) – largest image that is converted.
* `trust_optimize_min_free_disk_bytes` ( int $bytes ) – free space below which conversion pauses.
* `trust_optimize_worker_time_budget` ( float $seconds ) – time one task works before it continues in a new task (default 20).
* `trust_optimize_bulk_batch_size` ( int ) – attachments queued per producer run (default 25).
* `trust_optimize_bulk_max_pending` ( int ) – pending tasks the bulk job keeps queued (default 200).
* `trust_optimize_bulk_time_budget` ( int $seconds ) – time of one producer run (default 20).
* `trust_optimize_bulk_stale_after_seconds` ( int ) – a running job without progress is considered stale after this time (default 900).
* `trust_optimize_uninstall_cleanup_batch_size` ( int ) – variant records handled per step on uninstall (default 100).
* `trust_optimize_uninstall_cleanup_max_records` ( int ) – variant records handled per site in one uninstall request (default 5000).
* `trust_optimize_uninstall_cleanup_max_seconds` ( float ) – time one uninstall request spends per site (default 20).

== Screenshots ==

1. TrustOptimize settings page
2. Optimization statistics
3. Bulk conversion progress

== Changelog ==

= 1.0.0 =
* First public release.
* WebP and AVIF conversion of every JPEG and PNG size WordPress generates, delivered through `<picture>` on top of the core `srcset`.
* Background conversion with Action Scheduler, bulk conversion and removal with pause and resume, WP-CLI, REST API, Site Health tests.
* Safe by design: originals and files of other attachments are never overwritten or deleted.
* See CHANGELOG.md in the plugin for the complete list.

== Upgrade Notice ==

= 1.0.0 =
First public release.

== Development ==

TrustOptimize is developed on GitHub. If you want to contribute, please visit:
https://github.com/Andrey-Sivak/trust-optimize
