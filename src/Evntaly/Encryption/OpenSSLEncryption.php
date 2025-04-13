<?php

namespace Evntaly\Encryption;

use Exception;

/**
 * OpenSSL-based encryption provider.
 */
class OpenSSLEncryption implements EncryptionInterface
{
    /**
     * @var string The encryption key
     */
    private string $key;

    /**
     * @var string The encryption method
     */
    private string $method;

    /**
     * @var bool Whether the provider is properly configured
     */
    private bool $isReady = false;

    /**
     * Constructor.
     *
     * @param string $key    The encryption key
     * @param string $method The encryption method (defaults to AES-256-CBC)
     *
     * @throws Exception If OpenSSL extension is not loaded
     */
    public function __construct(string $key, string $method = 'aes-256-cbc')
    {
        if (!extension_loaded('openssl')) {
            throw new Exception('OpenSSL extension is required for encryption');
        }

        $this->key = $key;
        $this->method = $method;

        // Verify the method is supported
        if (!in_array($method, openssl_get_cipher_methods())) {
            throw new Exception("Encryption method '$method' is not supported");
        }

        $this->isReady = true;
    }

    /**
     * {@inheritdoc}
     */
    public function encrypt($value): string
    {
        if (!$this->isReady()) {
            throw new Exception('Encryption provider is not ready');
        }

        $ivLength = openssl_cipher_iv_length($this->method);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // Serialize value to handle mixed data types
        $serialized = serialize($value);

        $encrypted = openssl_encrypt(
            $serialized,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception('Failed to encrypt data: ' . openssl_error_string());
        }

        // Combine IV and encrypted data for storage
        $combined = $iv . $encrypted;

        // Base64 encode for safe storage
        return base64_encode($combined);
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt(string $encryptedValue)
    {
        if (!$this->isReady()) {
            throw new Exception('Encryption provider is not ready');
        }

        // Decode from base64
        $combined = base64_decode($encryptedValue, true);
        if ($combined === false) {
            throw new Exception('Invalid encrypted data format');
        }

        // Extract IV from the beginning of the data
        $ivLength = openssl_cipher_iv_length($this->method);
        if (strlen($combined) <= $ivLength) {
            throw new Exception('Encrypted data is too short');
        }

        $iv = substr($combined, 0, $ivLength);
        $encrypted = substr($combined, $ivLength);

        // Decrypt the data
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('Failed to decrypt data: ' . openssl_error_string());
        }

        // Unserialize to get the original value
        try {
            return unserialize($decrypted);
        } catch (Exception $e) {
            throw new Exception('Failed to unserialize decrypted data: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isReady(): bool
    {
        return $this->isReady;
    }
}
