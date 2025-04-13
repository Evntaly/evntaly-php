<?php

namespace Evntaly\Encryption;

use Exception;

/**
 * AES-256 encryption provider using PHP's OpenSSL extension.
 */
class AesEncryptionProvider implements EncryptionProviderInterface
{
    /**
     * @var string Encryption key
     */
    private $key;

    /**
     * @var string Encryption method
     */
    private $method = 'aes-256-cbc';

    /**
     * Constructor.
     *
     * @param  string    $key    Encryption key
     * @param  string    $method Encryption method (default: aes-256-cbc)
     * @throws Exception If OpenSSL extension is not available
     */
    public function __construct(string $key, string $method = null)
    {
        if (!extension_loaded('openssl')) {
            throw new Exception('OpenSSL extension is required for AesEncryptionProvider');
        }

        $this->key = $key;

        if ($method !== null) {
            if (!in_array($method, openssl_get_cipher_methods())) {
                throw new Exception("Unsupported encryption method: {$method}");
            }
            $this->method = $method;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function encrypt(string $value): string
    {
        $ivLength = openssl_cipher_iv_length($this->method);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt(
            $value,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception('Encryption failed: ' . openssl_error_string());
        }

        // Prepend IV to the encrypted data and base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt(string $encryptedValue): string
    {
        $data = base64_decode($encryptedValue);
        if ($data === false) {
            throw new Exception('Invalid base64 encoding');
        }

        $ivLength = openssl_cipher_iv_length($this->method);
        if (strlen($data) <= $ivLength) {
            throw new Exception('Encrypted data is too short');
        }

        // Extract IV and ciphertext
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt(
            $encrypted,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('Decryption failed: ' . openssl_error_string());
        }

        return $decrypted;
    }
}
