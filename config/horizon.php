<?php

use Illuminate\Support\Str;
use Linfo\Linfo;

$lInfo = new Linfo();
$parser = $lInfo->getParser();

$maxProcesses = (int)ceil($parser->getRam()['total'] / 1024 / 1024 / 1024 * 6);

$workerConfig = [
    'V2board' => [
        'connection' => 'redis',
        'queue' => [
            'order_handle',
            'traffic_fetch',
            'stat',
            'send_email',
            'send_email_mass',
            'send_telegram',
        ],
        'balance' => 'auto',
        'minProcesses' => 1,
        'maxProcesses' => $maxProcesses,
        'tries' => 1,
        'balanceCooldown' => 3,
    ],
];

return [
    'domain' => null,
    'path' => 'monitor',
    'use' => 'default',
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),
    'middleware' => ['admin'],
    'waits' => [
        'redis:default' => 60,
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],
    'fast_termination' => false,
    'memory_limit' => 32,
    'environments' => [
<<<<<<< HEAD
        'production' => $workerConfig,
        'local' => $workerConfig,
=======
        'local' => [
            'V2board' => [
                'connection' => 'redis',
                'queue' => [
                    'order_handle',
                    'traffic_fetch',
                    'stat',
                    'send_email',
                    'send_email_mass',
                    'send_telegram',
                ],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => min(
                    (int)ceil($parser->getRam()['total'] / 1024 / 1024 / 1024 * 6),
                    (int)env('HORIZON_MAX_PROCESSES', 128)
                ),
                'tries' => 1,
                'balanceCooldown' => 3,
            ],
        ],
>>>>>>> upstream/master
    ],
];
