<?php

namespace Evntaly\Encryption;

/**
 * Interface for encryption providers.
 */
interface EncryptionProviderInterface
{
    /**
     * Encrypt a string value.
     *
     * @param  string $value The value to encrypt
     * @return string The encrypted value
     */
    public function encrypt(string $value): string;

    /**
     * Decrypt an encrypted value.
     *
     * @param  string $encryptedValue The encrypted value to decrypt
     * @return string The decrypted value
     */
    public function decrypt(string $encryptedValue): string;
}
