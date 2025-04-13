<?php

namespace Evntaly\Encryption;

use Exception;

/**
 * Utility class for generating encryption keys.
 */
class KeyGenerator
{
    /**
     * Generate an RSA key pair.
     *
     * @param  int                                    $bits       Key size in bits (default: 2048)
     * @param  string|null                            $passphrase Optional passphrase to encrypt the private key
     * @param  array                                  $options    Additional OpenSSL options
     * @return array{private: string, public: string} Array containing the private and public key
     * @throws Exception                              If key generation fails
     */
    public static function generateRsaKeyPair(
        int $bits = 2048,
        ?string $passphrase = null,
        array $options = []
    ): array {
        if (!extension_loaded('openssl')) {
            throw new Exception('OpenSSL extension is required for key generation');
        }

        // Default configuration
        $defaultOptions = [
            'digest_alg' => 'sha256',
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $config = array_merge($defaultOptions, $options);

        // Generate private key
        $privateKey = openssl_pkey_new($config);
        if ($privateKey === false) {
            throw new Exception('Failed to generate private key: ' . openssl_error_string());
        }

        // Export private key (PEM format)
        $privateKeyPem = '';
        $result = openssl_pkey_export($privateKey, $privateKeyPem, $passphrase);

        if ($result === false) {
            throw new Exception('Failed to export private key: ' . openssl_error_string());
        }

        // Generate public key
        $keyDetails = openssl_pkey_get_details($privateKey);
        if ($keyDetails === false) {
            throw new Exception('Failed to get key details: ' . openssl_error_string());
        }

        $publicKeyPem = $keyDetails['key'];

        // Clean up the resource
        openssl_free_key($privateKey);

        return [
            'private' => $privateKeyPem,
            'public' => $publicKeyPem,
        ];
    }

    /**
     * Generate a strong symmetric encryption key.
     *
     * @param  int       $length Key length in bytes (default: 32 for AES-256)
     * @return string    The generated key
     * @throws Exception If key generation fails
     */
    public static function generateSymmetricKey(int $length = 32): string
    {
        if (!function_exists('random_bytes')) {
            throw new Exception('The random_bytes function is required for secure key generation');
        }

        try {
            return random_bytes($length);
        } catch (\Throwable $e) {
            throw new Exception('Failed to generate secure random key: ' . $e->getMessage());
        }
    }

    /**
     * Generate a strong symmetric encryption key as a hex string.
     *
     * @param  int       $length Key length in bytes (default: 32 for AES-256)
     * @return string    The generated key as a hex string
     * @throws Exception If key generation fails
     */
    public static function generateSymmetricKeyHex(int $length = 32): string
    {
        return bin2hex(self::generateSymmetricKey($length));
    }

    /**
     * Save keys to files.
     *
     * @param  array{private: string, public: string} $keyPair        Key pair from generateRsaKeyPair
     * @param  string                                 $privateKeyPath Path to save the private key
     * @param  string                                 $publicKeyPath  Path to save the public key
     * @return bool                                   True if both keys were saved successfully
     * @throws Exception                              If saving keys fails
     */
    public static function saveKeyPairToFiles(
        array $keyPair,
        string $privateKeyPath,
        string $publicKeyPath
    ): bool {
        $privateResult = file_put_contents($privateKeyPath, $keyPair['private']);
        if ($privateResult === false) {
            throw new Exception("Failed to save private key to {$privateKeyPath}");
        }

        $publicResult = file_put_contents($publicKeyPath, $keyPair['public']);
        if ($publicResult === false) {
            throw new Exception("Failed to save public key to {$publicKeyPath}");
        }

        // Set proper permissions for private key (readable only by owner)
        if (function_exists('chmod') && PHP_OS !== 'WINNT') {
            chmod($privateKeyPath, 0600);
        }

        return true;
    }
}
