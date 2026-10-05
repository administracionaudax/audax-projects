<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    // Como el resto de la app interna: sesión iniciada, usuario activo y 2FA si require_2fa está
    // activo (SPEC §14). La gate viewHorizon (solo admin) se aplica además en HorizonServiceProvider.
    'middleware' => ['web', 'auth', 'active', '2fa'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
    | Reparto de memoria (D-141, docs/DEPLOY.md §1): todo cabe en system-audax.slice (1280 MiB).
    | - supervisor-1 (cola `default`): como siempre, hasta 2 procesos de 128 MB → 256 MB.
    | - supervisor-mail (cola `mail`): los correos y, desde la Fase 9, el PDF y el Excel de los
    |   informes que se envían (SendReportDelivery). Un solo proceso de 256 MB, que es también el
    |   memory_limit que se pone el envío al generarlo (la CLI del servidor trae 128M).
    | - supervisor-ai (cola `ai`, Fase 10, D-146): las llamadas a Gemini y a Google TTS de la Weekly
    |   (informe, satisfacción, audio, resúmenes y asistente). Un solo proceso de 128 MB: son
    |   llamadas HTTP de uno en uno (cuota de Gemini) y cada Job dura como mucho 600 s (AiQueue).
    | - Workers: 2 × 128 + 1 × 256 + 1 × 128 = 640 MB, el MemoryLimit de audax-horizon.service
    |   (antes 512; se aplica en el servidor al desplegar la Fase 10, SERVIDOR-CAMBIOS); más el
    |   maestro (memory_limit, 64 MB), el transcriptor (256 MB) y Reverb (256 MB) suman 1216 MB,
    |   dentro de los 1280 del slice. QueueConfigTest lo comprueba.
    | `memory` es el umbral a partir del cual Horizon reinicia el worker al acabar un job.
    */
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 5,
        ],
        'supervisor-mail' => [
            'connection' => 'redis',
            'queue' => ['mail'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            // El envío de un informe (SendReportDelivery) puede tardar hasta 300 s.
            'timeout' => 300,
            'nice' => 5,
        ],
        'supervisor-ai' => [
            'connection' => 'redis',
            'queue' => ['ai'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // Los reintentos los hace cada Job (GeminiClient reintenta 429 y 5xx dentro del intento).
            'tries' => 1,
            // App\Domain\Weeklies\Ai\AiQueue::TIMEOUT, por debajo del retry_after de redis (660 s).
            'timeout' => 600,
            'nice' => 10,
        ],
    ],

    // Servidor compartido: pocos procesos y con límites (unidad systemd audax-horizon, RUNBOOK paso 9).
    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-mail' => [
                'maxProcesses' => 1,
            ],
            'supervisor-ai' => [
                'maxProcesses' => 1,
            ],
        ],

        'staging' => [
            'supervisor-1' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-mail' => [
                'maxProcesses' => 1,
            ],
            'supervisor-ai' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 2,
            ],
            'supervisor-mail' => [
                'maxProcesses' => 1,
            ],
            'supervisor-ai' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
