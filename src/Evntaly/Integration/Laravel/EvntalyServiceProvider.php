<?php

namespace Evntaly\Integration\Laravel;

use Evntaly\EvntalySDK;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EvntalyServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register()
    {
        $laravelVersion = app()->version();
        if (version_compare($laravelVersion, '8.0.0', '<')) {
            throw new \RuntimeException('Evntaly Laravel integration requires Laravel 8.0 or higher');
        }

        $this->mergeConfigFrom(
            __DIR__ . '/config/evntaly.php',
            'evntaly'
        );

        $this->app->singleton(EvntalySDK::class, function ($app) {
            $config = $app['config']['evntaly'];

            return new EvntalySDK(
                $config['developer_secret'],
                $config['project_token'],
                [
                    'verboseLogging' => $config['verbose_logging'] ?? false,
                    'maxBatchSize' => $config['max_batch_size'] ?? 10,
                    'autoContext' => $config['auto_context'] ?? true,
                    'sampling' => $config['sampling'] ?? [],
                ]
            );
        });

        $this->app->alias(EvntalySDK::class, 'evntaly');
    }

    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/evntaly.php' => config_path('evntaly.php'),
        ], 'config');

        if ($this->app['config']['evntaly']['auto_instrument'] ?? false) {
            $this->registerEventListeners();
        }
    }

    /**
     * Register event listeners for automatic tracking.
     */
    protected function registerEventListeners()
    {
        $sdk = $this->app[EvntalySDK::class];

        // Track database queries
        if ($this->app['config']['evntaly']['track_queries'] ?? false) {
            Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($sdk) {
                if ($query->time >= ($this->app['config']['evntaly']['min_query_time'] ?? 100)) {
                    $sdk->track([
                        'title' => 'Database Query',
                        'description' => $this->sanitizeQuery($query->sql),
                        'data' => [
                            'time_ms' => $query->time,
                            'connection' => $query->connectionName,
                            'bindings' => $this->sanitizeBindings($query->bindings),
                        ],
                        'type' => 'query',
                        'tags' => ['database', 'query', 'performance'],
                    ]);
                }
            });
        }

        // Track route matches
        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($sdk) {
            $sdk->track([
                'title' => 'Route Matched',
                'description' => 'Request matched route: ' . $event->route->getName(),
                'data' => [
                    'route' => $event->route->getName() ?? 'unnamed',
                    'action' => $event->route->getActionName(),
                    'uri' => $event->request->getPathInfo(),
                    'method' => $event->request->method(),
                ],
                'type' => 'route',
                'tags' => ['routing', 'http'],
            ]);
        });

        // Track authentication events
        Event::listen(Login::class, function (Login $event) use ($sdk) {
            $sdk->track([
                'title' => 'User Login',
                'description' => 'User logged in successfully',
                'data' => [
                    'user_id' => $event->user->getAuthIdentifier(),
                    'guard' => $event->guard,
                ],
                'type' => 'auth',
                'tags' => ['auth', 'login'],
            ]);
        });

        Event::listen(Logout::class, function (Logout $event) use ($sdk) {
            $sdk->track([
                'title' => 'User Logout',
                'description' => 'User logged out',
                'data' => [
                    'user_id' => $event->user->getAuthIdentifier(),
                    'guard' => $event->guard,
                ],
                'type' => 'auth',
                'tags' => ['auth', 'logout'],
            ]);
        });

        Event::listen(Registered::class, function (Registered $event) use ($sdk) {
            $sdk->track([
                'title' => 'User Registered',
                'description' => 'New user registered',
                'data' => [
                    'user_id' => $event->user->getAuthIdentifier(),
                    'email' => $event->user->email ?? null,
                ],
                'type' => 'auth',
                'tags' => ['auth', 'registration'],
            ]);
        });

        // Track queue events
        Event::listen(JobProcessed::class, function (JobProcessed $event) use ($sdk) {
            $sdk->track([
                'title' => 'Queue Job Processed',
                'description' => 'Queue job processed successfully',
                'data' => [
                    'connection' => $event->connectionName,
                    'job' => get_class($event->job),
                    'queue' => $event->job->getQueue(),
                ],
                'type' => 'queue',
                'tags' => ['queue', 'job', 'success'],
            ]);
        });

        Event::listen(JobFailed::class, function (JobFailed $event) use ($sdk) {
            $sdk->track([
                'title' => 'Queue Job Failed',
                'description' => 'Queue job failed: ' . $event->exception->getMessage(),
                'data' => [
                    'connection' => $event->connectionName,
                    'job' => get_class($event->job),
                    'queue' => $event->job->getQueue(),
                    'exception' => [
                        'message' => $event->exception->getMessage(),
                        'class' => get_class($event->exception),
                        'trace' => $event->exception->getTraceAsString(),
                    ],
                ],
                'type' => 'queue',
                'tags' => ['queue', 'job', 'error'],
            ]);
        });
    }

    /**
     * Sanitize SQL query by removing sensitive data.
     */
    protected function sanitizeQuery(string $sql): string
    {
        return preg_replace('/password\s*=\s*[^\s,)]+/i', 'password=?', $sql);
    }

    /**
     * Sanitize query bindings to remove sensitive data.
     */
    protected function sanitizeBindings(array $bindings): array
    {
        $sensitiveFields = ['password', 'secret', 'token', 'key'];

        return array_map(function ($value, $key) use ($sensitiveFields) {
            foreach ($sensitiveFields as $field) {
                if (is_string($key) && stripos($key, $field) !== false) {
                    return '[REDACTED]';
                }
            }
            return $value;
        }, $bindings, array_keys($bindings));
    }
}
