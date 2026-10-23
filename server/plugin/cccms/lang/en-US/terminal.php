<?php

declare(strict_types=1);

/**
 * Online command terminal (F2).
 *
 * Command names / descriptions / parameter labels live here; `config/terminal.php`
 * only registers keys, so there is no second copy of the wording in the frontend.
 */
return [
    // ---- Commands ----
    'menu_sync'            => 'Sync menus',
    'menu_sync_desc'       => 'Write directories / menus from db/menu.php into sys_menu (idempotent)',
    'perm_scan'            => 'Sync permission nodes',
    'perm_scan_desc'       => 'Scan #[Permission] annotations and upsert button nodes',
    'openapi_check'        => 'API consistency check',
    'openapi_check_desc'   => 'Verify routes, permission annotations and the API doc agree (non-zero exit code = mismatch)',
    'data_check'           => 'Data scope check',
    'data_check_desc'      => 'Detect controllers querying tables directly and unmodelled guarded tables',
    'log_verify'           => 'Verify audit chain',
    'log_verify_desc'      => 'Verify the chained hashes of sys_log and locate tampered / deleted rows (read-only)',
    'log_archive'          => 'Archive old logs',
    'log_archive_desc'     => 'Archive logs older than N days, then delete rows from the main DB; try dry-run first',
    'db_upgrade'           => 'Initialize database schema',
    'db_upgrade_desc'      => 'Run schema.sql + seed.sql (idempotent, but changes schema and seed data)',

    // ---- Parameters ----
    'param_from'       => 'From ID',
    'param_to'         => 'To ID',
    'param_days'       => 'Keep days',
    'param_batch'      => 'Batch size',
    'param_path'       => 'Only this path',
    'param_dry_run'    => 'Count only, do not delete',

    // ---- Messages ----
    'disabled'         => 'Online terminal is disabled (config/terminal.php `enable`)',
    'not_found'        => 'Command not found or retired',
    'danger_forbidden' => 'This command requires the "run dangerous commands" permission',
    'confirm_required' => 'Type {key} to confirm this dangerous command',
    'param_required'   => 'Parameter "{name}" is required',
    'param_invalid'    => 'Parameter "{name}" has an invalid format',
    'param_range'      => 'Parameter "{name}" is out of range ({min}~{max})',
    'param_length'     => 'Parameter "{name}" is too long (max {max} characters)',
    'submitted'        => 'Command submitted, check the output in a moment',
    'push_failed'      => 'Failed to enqueue the command: {reason}',
    'job_not_found'    => 'Execution record not found',
    'unknown'          => 'Unnamed command',
    'spawn_failed'     => 'Unable to spawn the command process (proc_open unavailable?)',
    'no_output'        => '(no output)',
    'output_truncated' => '—— Output exceeded {limit} bytes and was truncated ——',
    'timeout'          => '—— Command timed out after {seconds}s and was terminated ——',
    'exit_code'        => 'Exit code {code}',
];
