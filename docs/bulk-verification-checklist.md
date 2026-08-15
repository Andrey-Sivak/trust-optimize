# Bulk optimization verification checklist

Run this checklist on staging before production rollout.

## Environment

- Confirm the plugin activates without starting a bulk job.
- Confirm Action Scheduler is loaded.
- Confirm uploads is writable.
- Confirm WebP and AVIF encoder support matches expectations.
- Confirm `wp trust-optimize status` works if WP-CLI is available.

## Automated smoke check

Run the behavioral smoke check on a disposable local/staging WordPress install:

```bash
wp eval-file wp-content/plugins/trust-optimize/tests/smoke/bulk-workflows.php --allow-root
```

When WP-CLI is not installed in the container, run the same file through an
available WP-CLI container pointed at this WordPress install, or install WP-CLI
in the current PHP container before running it.

The smoke script creates temporary `trust-optimize-smoke-*` media fixtures and
checks:

- plugin activation/classes and custom tables;
- REST status, single sync/remove, and bulk pause/resume/cancel endpoints;
- single attachment sync;
- single attachment remove;
- bulk inventory job tick;
- bounded bulk sync job tick;
- repeated sync after profile hash changes;
- missing source file handling;
- unsupported image MIME handling;
- unsupported WebP/AVIF reporting when the environment lacks those encoders;
- cleanup deletes only manifest-owned generated files and preserves originals.

For the full job lifecycle coverage, run it when no bulk job is `pending`,
`running`, or `paused`. If an existing active job is present, the script skips
job creation/pause/resume/cancel and bounded bulk tick checks rather than
modifying a real job.

## Upload and single attachment

- Upload one JPEG and one PNG.
- Confirm upload schedules normal per-attachment conversion work.
- Confirm generated variants are recorded in `metadata.generated_variants`.
- Run single attachment sync and confirm missing variants are repaired.
- Delete the attachment and confirm originals and WordPress thumbnails are preserved until WordPress deletes its own files.

## Bulk sync

- Run inventory from admin UI or CLI.
- Run sync on 100+ fixture images.
- Interrupt the worker/process mid-job.
- Resume the job and confirm `cursor_id` continues from the last processed attachment.
- Confirm Action Scheduler rows do not grow by `size × format × attachment` for bulk.
- Confirm one failing attachment increments `failed_count` and does not stop the job.

## Reprocess and cleanup

- Change image quality and run sync; stale variants should regenerate.
- Disable AVIF and run sync; only TrustOptimize AVIF variants from the manifest should be removed.
- Place an adjacent `.webp` file not recorded in the manifest; cleanup must not delete it.
- Run remove all with explicit confirmation; originals, WordPress thumbnails, `-scaled`, and `-rotated` files must remain.

## REST and UI

- Unauthenticated REST requests must be rejected.
- A user without `manage_options` must be rejected.
- Start must reject when another active job exists.
- Pause, resume, and cancel should be idempotent.
- Opening the admin page must not start processing.
- Progress counters must show processed, skipped, failed, created, deleted, cursor, and last error.

## Uninstall

- With `trust_optimize_remove_data_on_uninstall` disabled, uninstall must keep plugin data/files.
- With `trust_optimize_remove_data_on_uninstall` enabled, uninstall must clean only manifest-owned files, drop TrustOptimize tables, and delete plugin options.
- Confirm Action Scheduler shared tables remain.

## Known limitations

- Uninstall cleanup is bounded and logs a warning under `WP_DEBUG` if it cannot finish deleting every plugin-owned file before the configured limits.
- For very large sites, run confirmed cleanup through WP-CLI or admin bulk remove before uninstalling with data removal enabled.
