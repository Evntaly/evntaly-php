<?php

namespace Evntaly\Tests\Encryption;

use Evntaly\Encryption\KeyGenerator;
use PHPUnit\Framework\TestCase;

class KeyGeneratorTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        // Skip tests if OpenSSL is not available
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        // Create a temporary directory for key files
        $this->tempDir = sys_get_temp_dir() . '/evntaly_keygen_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testGenerateRsaKeyPair()
    {
        $keyPair = KeyGenerator::generateRsaKeyPair();

        // Verify the structure
        $this->assertArrayHasKey('private', $keyPair);
        $this->assertArrayHasKey('public', $keyPair);

        // Verify content format
        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $keyPair['private']);
        $this->assertStringContainsString('-----END PRIVATE KEY-----', $keyPair['private']);

        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $keyPair['public']);
        $this->assertStringContainsString('-----END PUBLIC KEY-----', $keyPair['public']);
    }

    public function testGenerateRsaKeyPairWithPassphrase()
    {
        $passphrase = 'test-passphrase';
        $keyPair = KeyGenerator::generateRsaKeyPair(2048, $passphrase);

        // Verify structure
        $this->assertArrayHasKey('private', $keyPair);
        $this->assertArrayHasKey('public', $keyPair);

        // Verify that the private key is encrypted (contains ENCRYPTED in the header)
        $this->assertStringContainsString('ENCRYPTED', $keyPair['private']);
    }

    public function testGenerateRsaKeyPairWithCustomBits()
    {
        $keyPair1024 = KeyGenerator::generateRsaKeyPair(1024);
        $keyPair2048 = KeyGenerator::generateRsaKeyPair(2048);
        $keyPair4096 = KeyGenerator::generateRsaKeyPair(4096);

        // Higher bit count should result in longer key strings
        $this->assertLessThan(
            strlen($keyPair2048['private']),
            strlen($keyPair4096['private']),
            '4096-bit key should be longer than 2048-bit key'
        );

        $this->assertLessThan(
            strlen($keyPair1024['private']),
            strlen($keyPair2048['private']),
            '2048-bit key should be longer than 1024-bit key'
        );
    }

    public function testGenerateSymmetricKey()
    {
        $key = KeyGenerator::generateSymmetricKey();

        // Default length should be 32 bytes (256 bits)
        $this->assertEquals(32, strlen($key));

        // Test custom length
        $key16 = KeyGenerator::generateSymmetricKey(16);
        $this->assertEquals(16, strlen($key16));

        $key64 = KeyGenerator::generateSymmetricKey(64);
        $this->assertEquals(64, strlen($key64));
    }

    public function testGenerateSymmetricKeyHex()
    {
        $key = KeyGenerator::generateSymmetricKeyHex();

        // Default length should be 32 bytes, which is 64 hex characters
        $this->assertEquals(64, strlen($key));

        // Verify it's a valid hex string
        $this->assertTrue(ctype_xdigit($key), 'Key should contain only hex characters');

        // Test custom length
        $key16 = KeyGenerator::generateSymmetricKeyHex(16);
        $this->assertEquals(32, strlen($key16)); // 16 bytes = 32 hex chars

        $key64 = KeyGenerator::generateSymmetricKeyHex(64);
        $this->assertEquals(128, strlen($key64)); // 64 bytes = 128 hex chars
    }

    public function testSaveKeyPairToFiles()
    {
        $keyPair = KeyGenerator::generateRsaKeyPair();
        $privateKeyPath = $this->tempDir . '/private_test.pem';
        $publicKeyPath = $this->tempDir . '/public_test.pem';

        $result = KeyGenerator::saveKeyPairToFiles($keyPair, $privateKeyPath, $publicKeyPath);

        $this->assertTrue($result);
        $this->assertFileExists($privateKeyPath);
        $this->assertFileExists($publicKeyPath);

        // Verify file contents
        $privateKeyContents = file_get_contents($privateKeyPath);
        $publicKeyContents = file_get_contents($publicKeyPath);

        $this->assertEquals($keyPair['private'], $privateKeyContents);
        $this->assertEquals($keyPair['public'], $publicKeyContents);

        // Check permissions on non-Windows systems
        if (PHP_OS !== 'WINNT' && function_exists('fileperms')) {
            $privateKeyPerms = fileperms($privateKeyPath) & 0777;
            $this->assertEquals(0600, $privateKeyPerms, 'Private key should have 0600 permissions');
        }
    }
}
