<?php

namespace Evntaly\Encryption;

use Exception;

/**
 * Asymmetric (public/private key) encryption provider.
 *
 * Uses RSA encryption for better security in certain scenarios.
 * Allows encrypting with a public key and decrypting with a private key.
 */
class AsymmetricEncryption implements EncryptionInterface
{
    /**
     * @var string|resource The public key for encryption
     */
    private $publicKey;

    /**
     * @var string|resource The private key for decryption
     */
    private $privateKey;

    /**
     * @var bool Whether both keys are loaded and ready
     */
    private bool $isReady = false;

    /**
     * Constructor.
     *
     * @param  string      $publicKeyPath        Path to public key file or PEM content
     * @param  string|null $privateKeyPath       Path to private key file or PEM content (optional for encrypt-only usage)
     * @param  string|null $privateKeyPassphrase Passphrase for the private key (if encrypted)
     * @throws Exception   If OpenSSL extension is not loaded
     */
    public function __construct(
        string $publicKeyPath,
        ?string $privateKeyPath = null,
        ?string $privateKeyPassphrase = null
    ) {
        if (!extension_loaded('openssl')) {
            throw new Exception('OpenSSL extension is required for asymmetric encryption');
        }

        // Load public key
        if (file_exists($publicKeyPath)) {
            $this->publicKey = openssl_pkey_get_public('file://' . $publicKeyPath);
        } else {
            $this->publicKey = openssl_pkey_get_public($publicKeyPath);
        }

        if ($this->publicKey === false) {
            throw new Exception('Failed to load public key: ' . openssl_error_string());
        }

        // Load private key if provided
        if ($privateKeyPath !== null) {
            if (file_exists($privateKeyPath)) {
                $this->privateKey = openssl_pkey_get_private(
                    'file://' . $privateKeyPath,
                    $privateKeyPassphrase
                );
            } else {
                $this->privateKey = openssl_pkey_get_private(
                    $privateKeyPath,
                    $privateKeyPassphrase
                );
            }

            if ($this->privateKey === false) {
                throw new Exception('Failed to load private key: ' . openssl_error_string());
            }
        }

        $this->isReady = true;
    }

    /**
     * {@inheritdoc}
     */
    public function encrypt($value): string
    {
        if (!$this->isReady || $this->publicKey === null) {
            throw new Exception('Encryption provider is not ready');
        }

        // Convert value to string if it's not already
        if (!is_string($value)) {
            $value = json_encode($value);
            if ($value === false) {
                throw new Exception('Failed to convert value to string for encryption');
            }
        }

        $encrypted = '';
        $result = openssl_public_encrypt($value, $encrypted, $this->publicKey);

        if ($result === false) {
            throw new Exception('Failed to encrypt data: ' . openssl_error_string());
        }

        // Return base64 encoded string for easy storage
        return base64_encode($encrypted);
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt(string $encryptedValue)
    {
        if (!$this->isReady || $this->privateKey === null) {
            throw new Exception('Decryption is not available (no private key provided)');
        }

        // Decode from base64
        $encryptedBinary = base64_decode($encryptedValue, true);
        if ($encryptedBinary === false) {
            throw new Exception('Invalid encrypted data format');
        }

        $decrypted = '';
        $result = openssl_private_decrypt($encryptedBinary, $decrypted, $this->privateKey);

        if ($result === false) {
            throw new Exception('Failed to decrypt data: ' . openssl_error_string());
        }

        // Try to decode as JSON in case it was a structured value
        $jsonDecoded = json_decode($decrypted, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $jsonDecoded;
        }

        return $decrypted;
    }

    /**
     * {@inheritdoc}
     */
    public function isReady(): bool
    {
        return $this->isReady;
    }

    /**
     * Check if decryption is available.
     *
     * @return bool True if private key is loaded and decryption is available
     */
    public function canDecrypt(): bool
    {
        return $this->isReady && $this->privateKey !== null;
    }

    /**
     * Destructor to clean up OpenSSL resources.
     */
    public function __destruct()
    {
        if (is_resource($this->publicKey)) {
            openssl_free_key($this->publicKey);
        }

        if (is_resource($this->privateKey)) {
            openssl_free_key($this->privateKey);
        }
    }
}
