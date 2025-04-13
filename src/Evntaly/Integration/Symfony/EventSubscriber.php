<?php

namespace Evntaly\Integration\Symfony;

use Doctrine\DBAL\Logging\DebugStack;
use Evntaly\EvntalySDK;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

class EventSubscriber implements EventSubscriberInterface
{
    private $sdk;
    private $config;
    private $queryLogger;
    private $requestStartTime;
    private $tokenStorage;

    public function __construct(EvntalySDK $sdk, array $config, TokenStorageInterface $tokenStorage = null)
    {
        $this->sdk = $sdk;
        $this->config = $config;
        $this->tokenStorage = $tokenStorage;

        if ($config['track_queries'] ?? false) {
            $this->queryLogger = new DebugStack();
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', -128],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
            SecurityEvents::INTERACTIVE_LOGIN => ['onInteractiveLogin', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestStartTime = microtime(true);

        $request = $event->getRequest();

        if ($this->config['track_routes'] ?? false) {
            $this->sdk->track([
                'title' => 'Request Started',
                'description' => 'Started processing ' . $request->getMethod() . ' ' . $request->getPathInfo(),
                'data' => [
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo(),
                    'query' => $request->query->all(),
                    'client_ip' => $request->getClientIp(),
                    'user_agent' => $request->headers->get('User-Agent'),
                ],
                'type' => 'request',
                'tags' => ['request', 'symfony', strtolower($request->getMethod())],
            ]);
        }
    }

    public function onKernelResponse(ResponseEvent $event)
    {
        if (!$event->isMainRequest() || !$this->requestStartTime) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $duration = (microtime(true) - $this->requestStartTime) * 1000;

        if ($this->config['track_routes'] ?? false) {
            $this->sdk->track([
                'title' => 'Request Completed',
                'description' => 'Completed ' . $request->getMethod() . ' ' . $request->getPathInfo(),
                'data' => [
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo(),
                    'status_code' => $response->getStatusCode(),
                    'duration_ms' => $duration,
                    'route' => $request->attributes->get('_route'),
                    'controller' => $request->attributes->get('_controller'),
                ],
                'type' => 'request',
                'tags' => ['request', 'symfony', 'response', strtolower($request->getMethod())],
            ]);
        }

        // Track slow database queries
        if (($this->config['track_queries'] ?? false) && $this->queryLogger) {
            $minQueryTime = $this->config['min_query_time'] ?? 100;

            foreach ($this->queryLogger->queries as $query) {
                if ($query['executionMS'] * 1000 >= $minQueryTime) {
                    $this->sdk->track([
                        'title' => 'Slow Database Query',
                        'description' => $this->sanitizeQuery($query['sql']),
                        'data' => [
                            'query' => $this->sanitizeQuery($query['sql']),
                            'params' => $this->sanitizeParams($query['params'] ?? []),
                            'duration_ms' => $query['executionMS'] * 1000,
                        ],
                        'type' => 'query',
                        'tags' => ['database', 'query', 'performance', 'slow'],
                    ]);
                }
            }
        }
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        $this->sdk->track([
            'title' => 'Exception: ' . get_class($exception),
            'description' => $exception->getMessage(),
            'data' => [
                'exception_class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'request' => [
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo(),
                    'query' => $request->query->all(),
                ],
            ],
            'type' => 'exception',
            'tags' => ['exception', 'error', 'symfony'],
        ]);
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event)
    {
        if (!($this->config['track_auth'] ?? false) || !$this->tokenStorage) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user) {
            return;
        }

        $username = method_exists($user, 'getUserIdentifier')
            ? $user->getUserIdentifier()
            : (method_exists($user, 'getUsername') ? $user->getUsername() : 'unknown');

        $this->sdk->track([
            'title' => 'User Login',
            'description' => 'User logged in successfully',
            'data' => [
                'username' => $username,
                'roles' => $token->getRoleNames(),
                'provider' => get_class($token),
            ],
            'type' => 'auth',
            'tags' => ['auth', 'login', 'symfony', 'security'],
        ]);
    }

    /**
     * Sanitize SQL query by removing sensitive data.
     */
    private function sanitizeQuery(string $sql): string
    {
        return preg_replace('/password\s*=\s*[^\s,)]+/i', 'password=?', $sql);
    }

    /**
     * Sanitize query params to remove sensitive data.
     */
    private function sanitizeParams(array $params): array
    {
        $sensitiveFields = ['password', 'secret', 'token', 'key'];

        foreach ($params as $key => $value) {
            foreach ($sensitiveFields as $field) {
                if (is_string($key) && stripos($key, $field) !== false) {
                    $params[$key] = '[REDACTED]';
                    continue 2;
                }
            }
        }

        return $params;
    }
}
