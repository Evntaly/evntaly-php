<?php

namespace Evntaly;

/**
 * Utility class providing helper methods for Evntaly SDK
 */
class EvntalyUtils
{
    /**
     * Generate a unique session ID for tracking purposes
     * 
     * @param string $prefix Prefix for the session ID
     * @return string Unique session ID
     */
    public static function generateSessionId(string $prefix = 'sid_'): string
    {
        return $prefix . bin2hex(random_bytes(12));
    }
    
    /**
     * Generate a unique user ID
     * 
     * @param string $prefix Prefix for the user ID
     * @return string Unique user ID
     */
    public static function generateUserId(string $prefix = 'usr_'): string
    {
        return $prefix . bin2hex(random_bytes(12));
    }
    
    /**
     * Validate event data structure and return issues
     * 
     * @param array $eventData Event data array to validate
     * @return array Array of validation issues or empty array if valid
     */
    public static function validateEventData(array $eventData): array
    {
        $issues = [];
        
        if (!isset($eventData['title']) || empty($eventData['title'])) {
            $issues[] = 'Missing required field: title';
        }
        
        if (isset($eventData['data']) && !is_array($eventData['data'])) {
            $issues[] = 'Field "data" must be an array';
        }
        
        if (isset($eventData['tags']) && !is_array($eventData['tags'])) {
            $issues[] = 'Field "tags" must be an array';
        }
        
        if (isset($eventData['user']) && !is_array($eventData['user'])) {
            $issues[] = 'Field "user" must be an array';
        }
        
        if (isset($eventData['notify']) && !is_bool($eventData['notify'])) {
            $issues[] = 'Field "notify" must be a boolean';
        }
        
        return $issues;
    }
    
    /**
     * Validate user data structure and return issues
     * 
     * @param array $userData User data array to validate
     * @return array Array of validation issues or empty array if valid
     */
    public static function validateUserData(array $userData): array
    {
        $issues = [];
        
        if (!isset($userData['id']) || empty($userData['id'])) {
            $issues[] = 'Missing required field: id';
        }
        
        if (isset($userData['data']) && !is_array($userData['data'])) {
            $issues[] = 'Field "data" must be an array';
        }
        
        return $issues;
    }
    
    /**
     * Create an event data structure with defaults and custom fields
     * 
     * @param string $title Event title
     * @param string $description Event description
     * @param array $data Additional event data
     * @param array $options Additional event options
     * @return array Complete event data structure
     */
    public static function createEvent(
        string $title,
        string $description = '',
        array $data = [],
        array $options = []
    ): array {
        $event = [
            'title' => $title,
            'description' => $description,
            'data' => $data,
            'sessionID' => $options['sessionID'] ?? self::generateSessionId(),
            'timestamp' => $options['timestamp'] ?? date('c'),
        ];
        
        $optionalFields = [
            'message', 'tags', 'notify', 'icon', 'apply_rule_only',
            'user', 'type', 'feature', 'topic'
        ];
        
        foreach ($optionalFields as $field) {
            if (isset($options[$field])) {
                $event[$field] = $options[$field];
            }
        }
        
        return $event;
    }
    
    /**
     * Log a debug message to error log
     * 
     * @param string $message Message to log
     * @param array $context Additional context data
     * @param bool $includeTimestamp Whether to include timestamp
     * @return void
     */
    public static function debug(string $message, array $context = [], bool $includeTimestamp = true): void
    {
        $logMessage = '[Evntaly Debug] ';
        
        if ($includeTimestamp) {
            $logMessage .= '[' . date('Y-m-d H:i:s') . '] ';
        }
        
        $logMessage .= $message;
        
        if (!empty($context)) {
            $logMessage .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        
        error_log($logMessage);
    }
    
    /**
     * Format data for readable output 
     * 
     * @param array $data Data to format
     * @return string Formatted output
     */
    public static function formatForDisplay(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Redact sensitive information from data for logging
     * 
     * @param array $data Data to redact
     * @param array $sensitiveKeys Keys to redact
     * @return array Redacted data
     */
    public static function redactSensitiveData(array $data, array $sensitiveKeys = ['password', 'secret', 'token', 'key']): array
    {
        $redacted = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = self::redactSensitiveData($value, $sensitiveKeys);
            } else {
                $isMatch = false;
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (stripos($key, $sensitiveKey) !== false) {
                        $isMatch = true;
                        break;
                    }
                }
                
                $redacted[$key] = $isMatch ? '***REDACTED***' : $value;
            }
        }
        
        return $redacted;
    }
} 