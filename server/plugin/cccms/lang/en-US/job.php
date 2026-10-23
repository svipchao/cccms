<?php

declare(strict_types=1);

/** Async job queue (F5) messages. */
return [
    'target_invalid' => 'Invalid job target: it must be a class implementing JobHandler ({target})',
    'not_found'      => 'Job not found',
    'already_done'   => 'This job is already finished, no need to retry',
    'retried'        => 'Requeued, it will be consumed shortly',
    'removed'        => 'Deleted {count} job(s)',
    'remove_none'    => 'The selected jobs are not finished yet (pending / running) and cannot be deleted',
];
