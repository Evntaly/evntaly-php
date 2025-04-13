<?php

namespace Evntaly\Validator;

/**
 * JSON Schema validator for Evntaly events.
 */
class SchemaValidator
{
    /**
     * @var array
     */
    private $schemas = [];

    /**
     * @var bool
     */
    private $strictMode = false;

    /**
     * Create a new schema validator.
     *
     * @param bool $strictMode Whether to throw exceptions on validation failures
     */
    public function __construct(bool $strictMode = false)
    {
        $this->strictMode = $strictMode;
    }

    /**
     * Register a JSON schema for a specific event type.
     *
     * @param  string                    $eventType The event type this schema applies to
     * @param  array|string              $schema    JSON Schema as array or file path
     * @return self
     * @throws \InvalidArgumentException If schema is invalid
     */
    public function registerSchema(string $eventType, $schema): self
    {
        if (is_string($schema) && file_exists($schema)) {
            $schema = $this->loadSchemaFromFile($schema);
        }

        if (!is_array($schema)) {
            throw new \InvalidArgumentException('Schema must be an array or a valid file path');
        }

        $this->schemas[$eventType] = $schema;
        return $this;
    }

    /**
     * Register a directory of JSON schema files.
     *
     * @param  string                    $directory The directory containing schema files
     * @param  string                    $suffix    The file suffix to look for (default: .schema.json)
     * @return self
     * @throws \InvalidArgumentException If directory doesn't exist
     */
    public function registerSchemaDirectory(string $directory, string $suffix = '.schema.json'): self
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        $files = glob("{$directory}/*{$suffix}");

        foreach ($files as $file) {
            $eventType = basename($file, $suffix);
            $this->registerSchema($eventType, $file);
        }

        return $this;
    }

    /**
     * Validate an event against its schema.
     *
     * @param  array             $event The event to validate
     * @return array             Empty array if valid, array of error messages if invalid
     * @throws \RuntimeException If strict mode is enabled and validation fails
     */
    public function validate(array $event): array
    {
        $eventType = $event['type'] ?? 'default';

        // If no schema exists for this type, check for default schema
        if (!isset($this->schemas[$eventType]) && isset($this->schemas['default'])) {
            $eventType = 'default';
        }

        // If no schema exists, consider valid
        if (!isset($this->schemas[$eventType])) {
            return [];
        }

        $schema = $this->schemas[$eventType];
        $errors = $this->validateAgainstSchema($event, $schema);

        if (!empty($errors) && $this->strictMode) {
            throw new \RuntimeException('Event validation failed: ' . implode(', ', $errors));
        }

        return $errors;
    }

    /**
     * Create middleware for validating events.
     *
     * @return callable The middleware function
     */
    public function createMiddleware(): callable
    {
        return function (array $event) {
            $errors = $this->validate($event);

            if (!empty($errors)) {
                if (!isset($event['data'])) {
                    $event['data'] = [];
                }

                if (!isset($event['data']['validation_errors'])) {
                    $event['data']['validation_errors'] = [];
                }

                $event['data']['validation_errors'] = array_merge(
                    $event['data']['validation_errors'],
                    $errors
                );
            }

            return $event;
        };
    }

    /**
     * Load a schema from a file.
     *
     * @param  string                    $filePath Path to the schema file
     * @return array                     The loaded schema
     * @throws \InvalidArgumentException If file cannot be read or decoded
     */
    private function loadSchemaFromFile(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \InvalidArgumentException("Could not read schema file: {$filePath}");
        }

        $schema = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON in schema file: ' . json_last_error_msg());
        }

        return $schema;
    }

    /**
     * Validate an event against a JSON schema.
     *
     * @param  array $event  The event to validate
     * @param  array $schema The JSON schema to validate against
     * @return array Array of error messages
     */
    private function validateAgainstSchema(array $event, array $schema): array
    {
        // Check if JSON Schema library is available
        if (class_exists('\JsonSchema\Validator')) {
            return $this->validateWithJsonSchema($event, $schema);
        }

        // Fallback to basic validation
        return $this->validateBasic($event, $schema);
    }

    /**
     * Validate using the JsonSchema library if available.
     *
     * @param  array $event  The event to validate
     * @param  array $schema The JSON schema to validate against
     * @return array Array of error messages
     */
    private function validateWithJsonSchema(array $event, array $schema): array
    {
        $validator = new \JsonSchema\Validator();
        $data = json_decode(json_encode($event));
        $validator->validate($data, $schema);

        if ($validator->isValid()) {
            return [];
        }

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = sprintf(
                '[%s] %s',
                $error['property'],
                $error['message']
            );
        }

        return $errors;
    }

    /**
     * Basic validation without JsonSchema library.
     *
     * @param  array $event  The event to validate
     * @param  array $schema The JSON schema to validate against
     * @return array Array of error messages
     */
    private function validateBasic(array $event, array $schema): array
    {
        $errors = [];

        // Check required properties
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $required) {
                if (!isset($event[$required])) {
                    $errors[] = "Missing required property: {$required}";
                }
            }
        }

        // Check property types if properties are defined
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $prop => $propSchema) {
                if (isset($event[$prop]) && isset($propSchema['type'])) {
                    $valid = $this->validateType($event[$prop], $propSchema['type']);
                    if (!$valid) {
                        $errors[] = "Property {$prop} has invalid type. Expected {$propSchema['type']}.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a value against a JSON Schema type.
     *
     * @param  mixed  $value The value to validate
     * @param  string $type  The expected type
     * @return bool   True if valid
     */
    private function validateType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'number':
                return is_numeric($value);
            case 'integer':
                return is_int($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value) && array_keys($value) === range(0, count($value) - 1);
            case 'object':
                return is_array($value) && array_keys($value) !== range(0, count($value) - 1);
            case 'null':
                return $value === null;
            default:
                return false;
        }
    }
}
