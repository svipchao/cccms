<?php

declare(strict_types=1);

/** Database backup / restore (F3) messages. */
return [
    'not_found'        => 'Backup not found',
    'not_ready'        => 'The backup is not finished (or has failed) and cannot be used',
    'already_finished' => 'This backup job has already finished',
    'failed'           => 'Backup failed: {reason}',
    'file_missing'     => 'The backup file no longer exists (deleted manually?). Please remove this record',
    'checksum_failed'  => 'Backup file checksum mismatch (sha256), the file may be corrupted; restore refused',
    'select_none'      => 'Select the backups to delete first',
    'delete_running'   => 'A running backup cannot be deleted (the archive is still being written)',
    'no_database'      => 'Cannot determine the current database name; restore refused',
    'confirm_mismatch' => 'Type the current database name {database} to confirm the restore',
    'tables_missing'   => 'These tables are not in the backup: {tables}',
    'restore_locked'   => 'Another restore is in progress, please wait',
    'lock_failed'      => 'Cannot create the restore lock; check permissions on runtime/backups',
    'created'          => 'Backup completed',
    'deleted'          => 'Deleted {count} backup(s)',
    'restore_done'     => 'Restore finished: {tables} table(s) / {statements} statement(s) (pre-restore backup #{backup_id} was created)',
];
