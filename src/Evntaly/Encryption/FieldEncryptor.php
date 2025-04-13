<?php

namespace Evntaly\Encryption;

use Exception;

/**
 * Field-level encryption for Evntaly events.
 */
class FieldEncryptor
{
    /**
     * @var EncryptionInterface The encryption provider
     */
    private EncryptionInterface $encryptor;

    /**
     * @var array<string> List of fields to encrypt
     */
    private array $sensitiveFields = [];

    /**
     * @var string Prefix for encrypted values
     */
    private string $encryptedPrefix = '__ENC__:';

    /**
     * Constructor.
     *
     * @param EncryptionInterface $encryptor       The encryption provider
     * @param array<string>       $sensitiveFields List of fields to encrypt
     */
    public function __construct(
        EncryptionInterface $encryptor,
        array $sensitiveFields = ['password', 'credit_card', 'ssn', 'secret']
    ) {
        $this->encryptor = $encryptor;
        $this->sensitiveFields = $sensitiveFields;
    }

    /**
     * Add a sensitive field to the list.
     *
     * @param  string $fieldName Field name to encrypt
     * @return self
     */
    public function addSensitiveField(string $fieldName): self
    {
        if (!in_array($fieldName, $this->sensitiveFields)) {
            $this->sensitiveFields[] = $fieldName;
        }

        return $this;
    }

    /**
     * Remove a field from the sensitive fields list.
     *
     * @param  string $fieldName Field name to remove
     * @return self
     */
    public function removeSensitiveField(string $fieldName): self
    {
        $this->sensitiveFields = array_filter(
            $this->sensitiveFields,
            fn ($field) => $field !== $fieldName
        );

        return $this;
    }

    /**
     * Set the list of sensitive fields.
     *
     * @param  array<string> $fields List of field names to encrypt
     * @return self
     */
    public function setSensitiveFields(array $fields): self
    {
        $this->sensitiveFields = $fields;
        return $this;
    }

    /**
     * Get the list of sensitive fields.
     *
     * @return array<string> List of sensitive fields
     */
    public function getSensitiveFields(): array
    {
        return $this->sensitiveFields;
    }

    /**
     * Process an event array and encrypt sensitive fields.
     *
     * @param  array<string, mixed> $eventData Event data array
     * @return array<string, mixed> Event data with encrypted fields
     */
    public function processEvent(array $eventData): array
    {
        if (!$this->encryptor->isReady()) {
            return $eventData;
        }

        // Process the data recursively
        return $this->encryptFields($eventData);
    }

    /**
     * Recursively encrypt fields in an array.
     *
     * @param  array<string, mixed> $data Data array to process
     * @return array<string, mixed> Processed data array
     */
    private function encryptFields(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively process nested arrays
                $result[$key] = $this->encryptFields($value);
            } else {
                // Check if this is a sensitive field
                $isSensitive = false;
                foreach ($this->sensitiveFields as $field) {
                    if (stripos($key, $field) !== false) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive && $value !== null && $value !== '') {
                    try {
                        // Make sure it's not already encrypted
                        if (is_string($value) && strpos($value, $this->encryptedPrefix) === 0) {
                            $result[$key] = $value;
                        } else {
                            $encrypted = $this->encryptor->encrypt($value);
                            $result[$key] = $this->encryptedPrefix . $encrypted;
                        }
                    } catch (Exception $e) {
                        // If encryption fails, keep the original value
                        $result[$key] = $value;
                    }
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Process an event array and decrypt sensitive fields.
     *
     * @param  array<string, mixed> $eventData Event data array
     * @return array<string, mixed> Event data with decrypted fields
     */
    public function decryptEvent(array $eventData): array
    {
        if (!$this->encryptor->isReady()) {
            return $eventData;
        }

        // Process the data recursively
        return $this->decryptFields($eventData);
    }

    /**
     * Recursively decrypt fields in an array.
     *
     * @param  array<string, mixed> $data Data array to process
     * @return array<string, mixed> Processed data array
     */
    private function decryptFields(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively process nested arrays
                $result[$key] = $this->decryptFields($value);
            } elseif (is_string($value) && strpos($value, $this->encryptedPrefix) === 0) {
                try {
                    // Remove the prefix and decrypt
                    $encryptedValue = substr($value, strlen($this->encryptedPrefix));
                    $result[$key] = $this->encryptor->decrypt($encryptedValue);
                } catch (Exception $e) {
                    // If decryption fails, keep the original value
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if a value is encrypted.
     *
     * @param  mixed $value Value to check
     * @return bool  True if the value is encrypted
     */
    public function isEncrypted($value): bool
    {
        return is_string($value) && strpos($value, $this->encryptedPrefix) === 0;
    }
}
