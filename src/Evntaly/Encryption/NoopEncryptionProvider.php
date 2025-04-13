<?php

namespace Evntaly\Encryption;

/**
 * No-operation encryption provider for development/testing
 * This provider does not perform actual encryption and should NOT be used in production.
 */
class NoopEncryptionProvider implements EncryptionProviderInterface
{
    /**
     * @var string Prefix to identify values "encrypted" with this provider
     */
    private $prefix = 'NOOP_ENC:';

    /**
     * {@inheritdoc}
     */
    public function encrypt(string $value): string
    {
        return $this->prefix . $value;
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt(string $encryptedValue): string
    {
        if (strpos($encryptedValue, $this->prefix) !== 0) {
            return $encryptedValue;
        }

        return substr($encryptedValue, strlen($this->prefix));
    }
}
