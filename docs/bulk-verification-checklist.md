# Bulk optimization verification checklist

Run this checklist on staging before production rollout.

## Environment

- Confirm the plugin activates without starting a bulk job.
- Confirm Action Scheduler is loaded.
- Confirm uploads is writable.
- Confirm WebP and AVIF encoder support matches expectations.
- Confirm `wp trust-optimize status` works if WP-CLI is available.

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

- Full uninstall cleanup runs in the uninstall request and may be unsuitable for very large libraries.
- For large sites, run confirmed cleanup through WP-CLI or admin bulk remove before uninstalling with data removal enabled.
