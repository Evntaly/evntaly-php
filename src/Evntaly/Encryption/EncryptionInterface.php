<?php

namespace Evntaly\Encryption;

/**
 * Interface for encryption providers used in Evntaly SDK.
 */
interface EncryptionInterface
{
    /**
     * Encrypt a value.
     *
     * @param  mixed  $value The value to encrypt
     * @return string The encrypted value as a string
     */
    public function encrypt($value): string;

    /**
     * Decrypt an encrypted value.
     *
     * @param  string $encryptedValue The encrypted value
     * @return mixed  The decrypted value
     */
    public function decrypt(string $encryptedValue);

    /**
     * Check if the provider is properly configured.
     *
     * @return bool True if the provider is ready to use
     */
    public function isReady(): bool;
}
