<?php

namespace Evntaly\Encryption;

use Exception;

/**
 * Factory for creating encryption providers.
 */
class EncryptionFactory
{
    /**
     * Create an AES encryption provider.
     *
     * @param  string                $key    Encryption key
     * @param  string|null           $method Encryption method (default: aes-256-cbc)
     * @return AesEncryptionProvider
     */
    public static function createAesProvider(string $key, ?string $method = null): AesEncryptionProvider
    {
        return new AesEncryptionProvider($key, $method);
    }

    /**
     * Create a no-operation encryption provider (for development/testing).
     *
     * @return NoopEncryptionProvider
     */
    public static function createNoopProvider(): NoopEncryptionProvider
    {
        return new NoopEncryptionProvider();
    }

    /**
     * Create an encryption provider based on configuration.
     *
     * @param  array                       $config Configuration array containing 'type' and provider-specific settings
     * @return EncryptionProviderInterface
     * @throws Exception                   If the provider type is not supported
     */
    public static function createFromConfig(array $config): EncryptionProviderInterface
    {
        if (!isset($config['type'])) {
            throw new Exception('Encryption provider type must be specified');
        }

        switch (strtolower($config['type'])) {
            case 'aes':
                if (!isset($config['key'])) {
                    throw new Exception('Encryption key must be specified for AES provider');
                }

                return self::createAesProvider(
                    $config['key'],
                    $config['method'] ?? null
                );

            case 'noop':
                return self::createNoopProvider();

            default:
                throw new Exception("Unsupported encryption provider type: {$config['type']}");
        }
    }
}
