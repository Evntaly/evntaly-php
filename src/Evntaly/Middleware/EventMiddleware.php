<?php

namespace Evntaly\Middleware;

/**
 * Collection of standard middleware functions for transforming events.
 */
class EventMiddleware
{
    /**
     * Create a middleware that adds application context to all events.
     *
     * @param  array    $context Context information to add to every event
     * @return callable The middleware function
     */
    public static function addContext(array $context): callable
    {
        return function (array $event) use ($context) {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($event['data']['context'])) {
                $event['data']['context'] = [];
            }

            $event['data']['context'] = array_merge(
                $event['data']['context'],
                $context
            );

            return $event;
        };
    }

    /**
     * Create a middleware that adds environment information (app version, platform, etc.).
     *
     * @param  string   $appVersion     Current application version
     * @param  string   $environment    Environment name (production, staging, etc.)
     * @param  array    $additionalInfo Additional environment information
     * @return callable The middleware function
     */
    public static function addEnvironmentInfo(
        string $appVersion,
        string $environment = 'production',
        array $additionalInfo = []
    ): callable {
        return function (array $event) use ($appVersion, $environment, $additionalInfo) {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($event['data']['environment'])) {
                $event['data']['environment'] = [];
            }

            $event['data']['environment'] = array_merge(
                $event['data']['environment'],
                [
                    'app_version' => $appVersion,
                    'environment' => $environment,
                ],
                $additionalInfo
            );

            return $event;
        };
    }

    /**
     * Create a middleware that redacts sensitive fields from events.
     *
     * @param  array    $fieldsToRedact List of field names to redact (supports dot notation)
     * @param  string   $replacement    Replacement string for redacted values
     * @return callable The middleware function
     */
    public static function redactSensitiveData(
        array $fieldsToRedact = ['password', 'token', 'secret', 'key', 'credit_card', 'ssn'],
        string $replacement = '[REDACTED]'
    ): callable {
        return function (array $event) use ($fieldsToRedact, $replacement) {
            // Helper function to recursively redact fields
            $redactFields = function ($data, $fields, $repl) use (&$redactFields) {
                if (!is_array($data)) {
                    return $data;
                }

                foreach ($data as $key => $value) {
                    // Check if this key should be redacted
                    foreach ($fields as $field) {
                        if (stripos($key, $field) !== false) {
                            $data[$key] = $repl;
                            continue 2; // Skip to next key in $data
                        }
                    }

                    // Recursively process arrays
                    if (is_array($value)) {
                        $data[$key] = $redactFields($value, $fields, $repl);
                    }
                }

                return $data;
            };

            // Apply redaction to all data in the event
            return $redactFields($event, $fieldsToRedact, $replacement);
        };
    }

    /**
     * Create a middleware that enforces a schema for events.
     *
     * @param  array    $requiredFields List of required field paths (supports dot notation)
     * @return callable The middleware function
     */
    public static function enforceSchema(array $requiredFields): callable
    {
        return function (array $event) use ($requiredFields) {
            // Helper function to check if a field exists using dot notation
            $hasField = function ($data, $path) use (&$hasField) {
                $parts = explode('.', $path);
                $first = array_shift($parts);

                if (!isset($data[$first])) {
                    return false;
                }

                if (empty($parts)) {
                    return true;
                }

                if (!is_array($data[$first])) {
                    return false;
                }

                return $hasField($data[$first], implode('.', $parts));
            };

            // Check all required fields
            foreach ($requiredFields as $field) {
                if (!$hasField($event, $field)) {
                    // Add a warning but don't block the event
                    if (!isset($event['data'])) {
                        $event['data'] = [];
                    }

                    if (!isset($event['data']['schema_warnings'])) {
                        $event['data']['schema_warnings'] = [];
                    }

                    $event['data']['schema_warnings'][] = "Missing required field: {$field}";
                }
            }

            return $event;
        };
    }

    /**
     * Create a middleware that adds a standardized set of timestamps to events.
     *
     * @return callable The middleware function
     */
    public static function addTimestamps(): callable
    {
        return function (array $event) {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            $now = time();
            $dateTime = new \DateTime();

            $event['data']['timestamps'] = [
                'unix' => $now,
                'iso8601' => date('c', $now),
                'date' => date('Y-m-d', $now),
                'time' => date('H:i:s', $now),
                'timezone' => $dateTime->getTimezone()->getName(),
            ];

            return $event;
        };
    }

    /**
     * Create a middleware that adds user agent information to events.
     *
     * @return callable The middleware function
     */
    public static function addUserAgentInfo(): callable
    {
        return function (array $event) {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($_SERVER['HTTP_USER_AGENT'])) {
                return $event;
            }

            $userAgent = $_SERVER['HTTP_USER_AGENT'];

            // Basic UA parsing
            $isMobile = (bool) preg_match('/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i', $userAgent);
            $isBot = (bool) preg_match('/(bot|crawler|spider|crawling)/i', $userAgent);

            $event['data']['user_agent'] = [
                'raw' => $userAgent,
                'is_mobile' => $isMobile,
                'is_bot' => $isBot,
            ];

            // Add IP address if available
            if (isset($_SERVER['REMOTE_ADDR'])) {
                $event['data']['user_agent']['ip'] = $_SERVER['REMOTE_ADDR'];
            }

            return $event;
        };
    }

    /**
     * Create a middleware that encrypts sensitive fields in events.
     *
     * @param  string   $encryptionKey   Encryption key (must be 32 characters for AES-256)
     * @param  array    $fieldsToEncrypt List of field paths to encrypt (supports dot notation)
     * @return callable The middleware function
     */
    public static function encryptSensitiveData(string $encryptionKey, array $fieldsToEncrypt): callable
    {
        return function (array $event) use ($encryptionKey, $fieldsToEncrypt) {
            // Helper function to encrypt a string value
            $encrypt = function ($value) use ($encryptionKey) {
                if (!is_string($value)) {
                    $value = json_encode($value);
                }

                $iv = random_bytes(16); // AES block size in CBC mode
                $encrypted = openssl_encrypt(
                    $value,
                    'AES-256-CBC',
                    $encryptionKey,
                    0,
                    $iv
                );

                if ($encrypted === false) {
                    throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
                }

                // Return encrypted data with IV prepended (encoded in base64)
                return base64_encode($iv . $encrypted);
            };

            // Helper function to set a value at a path using dot notation
            $setByPath = function (&$array, $path, $value) use (&$setByPath) {
                $keys = explode('.', $path);
                $key = array_shift($keys);

                if (empty($keys)) {
                    $array[$key] = $value;
                    return;
                }

                if (!isset($array[$key]) || !is_array($array[$key])) {
                    $array[$key] = [];
                }

                $setByPath($array[$key], implode('.', $keys), $value);
            };

            // Helper function to get a value by path using dot notation
            $getByPath = function ($array, $path) use (&$getByPath) {
                $keys = explode('.', $path);
                $key = array_shift($keys);

                if (!isset($array[$key])) {
                    return null;
                }

                if (empty($keys)) {
                    return $array[$key];
                }

                if (!is_array($array[$key])) {
                    return null;
                }

                return $getByPath($array[$key], implode('.', $keys));
            };

            // Encrypt specified fields
            foreach ($fieldsToEncrypt as $field) {
                $value = $getByPath($event, $field);

                if ($value !== null) {
                    try {
                        $encryptedValue = $encrypt($value);
                        $setByPath($event, $field, [
                            '_encrypted' => true,
                            'data' => $encryptedValue,
                        ]);
                    } catch (\Exception $e) {
                        // Log error but continue processing
                        error_log('Failed to encrypt field ' . $field . ': ' . $e->getMessage());
                    }
                }
            }

            return $event;
        };
    }
}
