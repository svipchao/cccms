<?php

declare(strict_types=1);

/** Post module messages. */
return [
    'not_found'     => 'Post not found',
    'code_required' => 'Post code cannot be empty',
    // Same as user.username_exists: never confirm existence
    'code_exists'   => 'That post code is unavailable, please choose another',
    'code_in_trash' => 'That post code is unavailable; if it was deleted by this tenant, restore or permanently delete it from the recycle bin first',
    'has_users'     => 'This post has users and cannot be deleted',
];
