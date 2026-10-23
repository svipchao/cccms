<?php

declare(strict_types=1);

/** Data permission rule messages. */
return [
    'not_found'                    => 'Rule not found',
    'field_required'               => 'Please select a field',
    'csv_required'                 => 'Please upload a CSV file',
    'csv_missing_name_column'      => 'The CSV is missing the "name" column, please download the import template first',
    'row_name_required'            => 'Row {line}: the rule name is required',
    'row_failed'                   => 'Row {line}: {message}',
    'bound_user_not_found'         => 'Bound user ID {id} does not exist',
    'bound_post_not_found'         => 'Bound post ID {id} does not exist',
    'bound_role_not_found'         => 'Bound role ID {id} does not exist',
    'bound_dept_not_found'         => 'Bound department ID {id} does not exist',
    'unknown_action'               => 'Unknown rule action: {action}',
    'invalid_table_name'           => 'Invalid target table name',
    'target_table_not_controlled'  => 'Target table {table} is not a controlled table, please register it under "Controlled Tables" first',
    'target_table_not_registered'  => 'Target table {table} is not a controlled table',
    'invalid_field_name'           => 'Field names may contain only letters, digits and underscores, and cannot start with a digit',
    'unknown_operator'             => 'Unknown operator: {operator}',
    'unknown_value_type'           => 'Unknown value type: {value_type}',
    'unknown_bind_mode'            => 'Unknown bind mode: {mode} (only or / and are allowed)',
    'field_not_in_table'           => 'Field {field} does not exist in table {table}, please select again',
    'duplicate'                    => 'An identical rule already exists (same binding targets, table, field and action)',
];
