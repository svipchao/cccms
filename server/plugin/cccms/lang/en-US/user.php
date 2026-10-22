<?php

declare(strict_types=1);

/** User module messages. */
return [
    // Validation / error messages
    'not_found'                 => 'User not found',
    'no_permission_view'        => 'No permission to view this user',
    'no_permission_operate'     => 'No permission to operate this user',
    'username_required'         => 'Username cannot be empty',
    // Never confirm existence: the username is globally unique, so "already exists" lets tenant A probe tenant B (P2-5)
    'username_exists'           => 'That username is unavailable, please choose another',
    'username_in_trash'         => 'That username is unavailable; if it was deleted by this tenant, restore or permanently delete it from the recycle bin first',
    'password_required'         => 'Password cannot be empty',
    'initial_password_required' => 'Missing initial password',
    'cannot_delete_self'        => 'You cannot delete yourself',
    'cannot_delete_super'       => 'The super administrator account cannot be deleted',
    'assign_target_required'    => 'Please select the role / department / post to assign',

    // Import
    'upload_csv_required'       => 'Please upload a CSV file',
    'csv_missing_username'      => 'The CSV is missing the username column, please download the import template first',
    'import_line_prefix'        => 'Line {line}: ',
    'import_username_empty'     => 'username is empty',

    // Relation labels (used in import error messages and as export column names)
    'label_role'                => 'Role',
    'label_dept'                => 'Department',
    'label_post'                => 'Post',
    'token_not_found'           => '{label} "{token}" does not exist',
    'ref_not_in_tenant'         => 'The selected {label} does not belong to the current tenant',

    // Export / import template
    'export_title'              => 'User List',
    'template_title'            => 'User Import Template',
    'col_username'              => 'Username',
    'col_nickname'              => 'Nickname',
    'col_email'                 => 'Email',
    'col_phone'                 => 'Mobile',
    'col_status'                => 'Status',
    'col_create_time'           => 'Created At',
    'col_remark'                => 'Notes',
    'status_enabled'            => 'Enabled',
    'status_disabled'           => 'Disabled',
    'template_sample_nickname'  => 'John Doe',
    'template_pw_hint'          => 'Initial password (at least 6 characters)',
    'template_sample_role'      => 'Staff',
    'template_sample_dept'      => 'R&D',
    'template_sample_post'      => 'Engineer',
    'template_hint'             => 'Existing usernames will be updated; new users must provide a password; roles/depts/posts are matched by name, multiple values are comma-separated, and leaving them empty means no change on update',
];
