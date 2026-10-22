<?php

declare(strict_types=1);

/**
 * Common messages (backend).
 *
 * Key convention: `{namespace}.{key}`, where the namespace maps to a file in this directory.
 * Missing keys fall back to zh-CN; if both locales lack the key, the key itself is returned
 * and a warning is logged.
 */
return [
    'not_logged_in'          => 'Not signed in',
    'session_expired'        => 'Your session has expired, please sign in again',
    'tenant_context_missing' => 'Tenant context is missing, the operation was rejected',
    'invalid_credentials'    => 'Invalid credentials or the user no longer exists',
    'unauthorized'           => 'Not signed in or the session has expired',
    'no_permission'          => 'Permission denied: {slug}',
    'permission_not_declared' => 'Endpoint does not declare a permission: {target}',
    'too_many_requests'      => 'Too many requests, please retry in {seconds} seconds',

    // ---- protocol-level errors (emitted by middleware, not tied to a business module) ----
    'method_not_allowed'     => 'Request method not allowed: {method}',
    'encoding_not_acceptable' => 'Response encoding not acceptable: {encoding}',

    // ---- generic operation results (controller response message, reused across modules) ----
    'created'          => 'Created successfully',
    'updated'          => 'Updated successfully',
    'deleted'          => 'Deleted successfully',
    'saved'            => 'Saved successfully',
    'imported'         => 'Imported successfully',
    'copied'           => 'Copied successfully',
    'generated'        => 'Generated successfully',
    'moved'            => 'Moved successfully',
    'reset'            => 'Reset successfully',
    'logged_out'       => 'Signed out',
    'exec_success'     => 'Execution succeeded',
    'exec_failed'      => 'Execution failed',
    'marked_read'      => 'Marked as read',
    'marked_all_read'  => 'All marked as read',
    'forced_offline'   => 'Forced offline',
    'password_changed' => 'Password updated',
    'device_offline'   => 'That device has been signed out',

    // bulk results with count
    'updated_count'    => 'Updated {count} item(s)',
    'deleted_count'    => 'Deleted {count} item(s)',
    'assigned_count'   => 'Assigned {count} item(s)',
    'restored_count'   => 'Restored {count} item(s)',
    'purged_count'     => 'Permanently deleted {count} item(s)',

    // generic validation
    'select_required'  => 'Please select data to operate on',
];
