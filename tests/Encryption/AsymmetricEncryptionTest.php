<?php

namespace Evntaly\Tests\Encryption;

use Evntaly\Encryption\AsymmetricEncryption;
use Evntaly\Encryption\KeyGenerator;
use PHPUnit\Framework\TestCase;

class AsymmetricEncryptionTest extends TestCase
{
    private $keyPair;
    private $tempDir;
    private $publicKeyPath;
    private $privateKeyPath;

    protected function setUp(): void
    {
        // Skip tests if OpenSSL is not available
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        // Generate a temporary directory for the keys
        $this->tempDir = sys_get_temp_dir() . '/evntaly_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->publicKeyPath = $this->tempDir . '/public.pem';
        $this->privateKeyPath = $this->tempDir . '/private.pem';

        // Generate a key pair for testing
        $this->keyPair = KeyGenerator::generateRsaKeyPair(2048);

        // Save the keys to files
        KeyGenerator::saveKeyPairToFiles(
            $this->keyPair,
            $this->privateKeyPath,
            $this->publicKeyPath
        );
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (file_exists($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }

        if (file_exists($this->privateKeyPath)) {
            unlink($this->privateKeyPath);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testConstructFromPemContent()
    {
        $encryptor = new AsymmetricEncryption(
            $this->keyPair['public'],
            $this->keyPair['private']
        );

        $this->assertTrue($encryptor->isReady());
        $this->assertTrue($encryptor->canDecrypt());
    }

    public function testConstructFromFiles()
    {
        $encryptor = new AsymmetricEncryption(
            $this->publicKeyPath,
            $this->privateKeyPath
        );

        $this->assertTrue($encryptor->isReady());
        $this->assertTrue($encryptor->canDecrypt());
    }

    public function testConstructWithPublicKeyOnly()
    {
        $encryptor = new AsymmetricEncryption($this->publicKeyPath);

        $this->assertTrue($encryptor->isReady());
        $this->assertFalse($encryptor->canDecrypt());
    }

    public function testEncryptDecryptString()
    {
        $encryptor = new AsymmetricEncryption(
            $this->publicKeyPath,
            $this->privateKeyPath
        );

        $original = 'Test string with special characters: äöü@€²³';
        $encrypted = $encryptor->encrypt($original);

        // Encrypted value should be different from original
        $this->assertNotEquals($original, $encrypted);

        // Should be a base64 encoded string
        $this->assertTrue(
            base64_decode($encrypted, true) !== false,
            'Encrypted value should be a valid base64 string'
        );

        // Decrypt and compare with original
        $decrypted = $encryptor->decrypt($encrypted);
        $this->assertEquals($original, $decrypted);
    }

    public function testEncryptDecryptArray()
    {
        $encryptor = new AsymmetricEncryption(
            $this->publicKeyPath,
            $this->privateKeyPath
        );

        $original = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30,
            'data' => [
                'nested' => 'value',
                'numbers' => [1, 2, 3],
            ],
        ];

        $encrypted = $encryptor->encrypt($original);

        // Should be a base64 encoded string
        $this->assertTrue(
            base64_decode($encrypted, true) !== false,
            'Encrypted value should be a valid base64 string'
        );

        // Decrypt and compare with original
        $decrypted = $encryptor->decrypt($encrypted);
        $this->assertEquals($original, $decrypted);
    }

    public function testEncryptWithPublicKeyOnly()
    {
        $encryptor = new AsymmetricEncryption($this->publicKeyPath);

        $original = 'Test string for public key only';
        $encrypted = $encryptor->encrypt($original);

        // Encrypted value should be different from original
        $this->assertNotEquals($original, $encrypted);

        // Should be a base64 encoded string
        $this->assertTrue(
            base64_decode($encrypted, true) !== false,
            'Encrypted value should be a valid base64 string'
        );

        // Should not be able to decrypt
        $this->expectException(\Exception::class);
        $encryptor->decrypt($encrypted);
    }

    public function testDecryptWithFullEncryptor()
    {
        // First encrypt with public key only
        $encryptOnly = new AsymmetricEncryption($this->publicKeyPath);
        $original = 'Test string for cross-encryption test';
        $encrypted = $encryptOnly->encrypt($original);

        // Then decrypt with full encryptor
        $fullEncryptor = new AsymmetricEncryption(
            $this->publicKeyPath,
            $this->privateKeyPath
        );

        $decrypted = $fullEncryptor->decrypt($encrypted);
        $this->assertEquals($original, $decrypted);
    }
}
