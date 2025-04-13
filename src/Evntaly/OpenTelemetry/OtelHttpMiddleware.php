<?php

namespace Evntaly\OpenTelemetry;

use Exception;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware for OpenTelemetry trace context propagation.
 */
class OtelHttpMiddleware implements MiddlewareInterface
{
    /**
     * @var OtelBridge The OpenTelemetry bridge
     */
    private $otelBridge;

    /**
     * @var string Application name
     */
    private $appName;

    /**
     * Constructor.
     *
     * @param OtelBridge $otelBridge The OpenTelemetry bridge
     * @param string     $appName    The application name
     */
    public function __construct(OtelBridge $otelBridge, string $appName = 'evntaly-application')
    {
        $this->otelBridge = $otelBridge;
        $this->appName = $appName;
    }

    /**
     * Process an incoming server request.
     *
     * @param  ServerRequestInterface  $request The request
     * @param  RequestHandlerInterface $handler The handler
     * @return ResponseInterface       The response
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Extract trace context from request headers
        $carrier = $this->extractHeadersFromRequest($request);
        $context = $this->otelBridge->extractContext($carrier);

        // Create new context scope
        $scope = $context->activate();

        // Create server span for this request
        $uri = $request->getUri();
        $path = $uri->getPath();
        $method = $request->getMethod();

        $span = $this->otelBridge->startSpan(
            "{$method} {$path}",
            [
                'http.method' => $method,
                'http.url' => (string) $uri,
                'http.host' => $uri->getHost(),
                'http.scheme' => $uri->getScheme() ?: 'http',
                'http.target' => $path,
                'http.user_agent' => $request->getHeaderLine('User-Agent'),
                'net.host.name' => $uri->getHost(),
                'net.host.port' => $uri->getPort(),
                'service.name' => $this->appName,
            ],
            SpanKind::SERVER
        );

        try {
            // Handle the request
            $response = $handler->handle($request);

            // Add response attributes to span
            $span->setAttribute('http.status_code', $response->getStatusCode());

            // Set span status based on response status code
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $span->setStatus(
                    StatusCode::ERROR,
                    "HTTP status code {$statusCode}"
                );
            } else {
                $span->setStatus(StatusCode::OK);
            }

            $this->otelBridge->endSpan($span);
            return $response;
        } catch (Exception $e) {
            // Record error
            $this->otelBridge->setSpanError($span, $e);
            $this->otelBridge->endSpan($span);

            // Re-throw the exception
            throw $e;
        } finally {
            // Close the scope
            if (isset($scope)) {
                $scope->detach();
            }
        }
    }

    /**
     * Extract headers from a PSR-7 request.
     *
     * @param  ServerRequestInterface $request The request
     * @return array                  The headers as a simple array
     */
    private function extractHeadersFromRequest(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = $request->getHeaderLine($name);
        }
        return $headers;
    }
}
