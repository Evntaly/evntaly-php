<?php

namespace Evntaly\Middleware;

use Evntaly\Context\CorrelationIdManager;
use Evntaly\Context\EnvironmentDetector;

/**
 * Middleware for adding contextual information to events.
 */
class ContextualMiddleware
{
    /**
     * Create contextual middleware function for environment detection.
     *
     * @return callable Middleware function
     */
    public static function addEnvironmentContext(): callable
    {
        $detector = new EnvironmentDetector();

        return function (array $event) use ($detector): array {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($event['data']['environment'])) {
                $event['data']['environment'] = [
                    'name' => $detector->getEnvironment(),
                    'type' => $detector->getEnvironment(),
                    'is_production' => $detector->isProduction(),
                ];

                // Add hostname if available
                $data = $detector->getEnvironmentData();
                if (isset($data['hostname'])) {
                    $event['data']['environment']['hostname'] = $data['hostname'];
                }

                // Add framework information if available
                if (isset($data['framework'])) {
                    $event['data']['environment']['framework'] = $data['framework'];
                }
            }

            // Add environment tag automatically
            if (!isset($event['tags'])) {
                $event['tags'] = [];
            }

            // Add environment tag if not already present
            $envTag = 'env:' . $detector->getEnvironment();
            if (!in_array($envTag, $event['tags'])) {
                $event['tags'][] = $envTag;
            }

            return $event;
        };
    }

    /**
     * Create contextual middleware function for correlation ID tracking.
     *
     * @return callable Middleware function
     */
    public static function addCorrelationContext(): callable
    {
        return function (array $event): array {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($event['data']['correlation'])) {
                $event['data']['correlation'] = CorrelationIdManager::getCorrelationContext();
            }

            if (!isset($event['sessionID']) && !isset($event['session_id'])) {
                $event['sessionID'] = CorrelationIdManager::getRequestId();
            }

            return $event;
        };
    }

    /**
     * Create combined contextual middleware that adds both environment and correlation context.
     *
     * @return callable Middleware function
     */
    public static function addFullContext(): callable
    {
        $environmentMiddleware = self::addEnvironmentContext();
        $correlationMiddleware = self::addCorrelationContext();

        return function (array $event) use ($environmentMiddleware, $correlationMiddleware): array {
            $event = $environmentMiddleware($event);
            $event = $correlationMiddleware($event);
            return $event;
        };
    }
}
