<?php

declare(strict_types=1);

/** Code generator messages. */
return [
    'table_required'   => 'Please select a data table',
    'module_required'  => 'Unable to derive the module name, please specify it manually',
    'table_not_found'  => 'The data table does not exist or has no columns: {table}',
    'dir_create_failed' => 'Failed to create directory: {dir}',
    'file_exists'      => 'The file already exists and was not overwritten: {path}',
    'file_write_failed' => 'Failed to write the file: {path}',
    'disabled_production' => 'The code generator is only available in a local development environment',
    'disabled_switch'     => 'The code generator is disabled (plugin.cccms.generator.enable = false)',
];
