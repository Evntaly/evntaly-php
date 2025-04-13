<?php

namespace Evntaly\Tests\Encryption;

use Evntaly\Encryption\OpenSSLEncryption;
use PHPUnit\Framework\TestCase;

class OpenSSLEncryptionTest extends TestCase
{
    private string $key;
    private OpenSSLEncryption $encryptor;

    protected function setUp(): void
    {
        // Skip tests if OpenSSL is not available
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        $this->key = hash('sha256', 'test-encryption-key', true);
        $this->encryptor = new OpenSSLEncryption($this->key);
    }

    public function testIsReady(): void
    {
        $this->assertTrue($this->encryptor->isReady());
    }

    public function testEncryptDecryptString(): void
    {
        $original = 'This is a test string';
        $encrypted = $this->encryptor->encrypt($original);

        // Encrypted value should be different from original
        $this->assertNotEquals($original, $encrypted);

        // Encrypted value should be base64 encoded
        $this->assertTrue(base64_decode($encrypted, true) !== false);

        // Decrypting should give us back the original
        $decrypted = $this->encryptor->decrypt($encrypted);
        $this->assertEquals($original, $decrypted);
    }

    public function testEncryptDecryptInteger(): void
    {
        $original = 12345;
        $encrypted = $this->encryptor->encrypt($original);
        $decrypted = $this->encryptor->decrypt($encrypted);

        $this->assertSame($original, $decrypted);
        $this->assertIsInt($decrypted);
    }

    public function testEncryptDecryptFloat(): void
    {
        $original = 123.45;
        $encrypted = $this->encryptor->encrypt($original);
        $decrypted = $this->encryptor->decrypt($encrypted);

        $this->assertSame($original, $decrypted);
        $this->assertIsFloat($decrypted);
    }

    public function testEncryptDecryptBoolean(): void
    {
        $original = true;
        $encrypted = $this->encryptor->encrypt($original);
        $decrypted = $this->encryptor->decrypt($encrypted);

        $this->assertSame($original, $decrypted);
        $this->assertIsBool($decrypted);
    }

    public function testEncryptDecryptArray(): void
    {
        $original = ['key1' => 'value1', 'key2' => 'value2'];
        $encrypted = $this->encryptor->encrypt($original);
        $decrypted = $this->encryptor->decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
        $this->assertIsArray($decrypted);
    }

    public function testEncryptDecryptNull(): void
    {
        $original = null;
        $encrypted = $this->encryptor->encrypt($original);
        $decrypted = $this->encryptor->decrypt($encrypted);

        $this->assertNull($decrypted);
    }

    public function testDifferentKeysProduceDifferentResults(): void
    {
        $data = 'test data';
        $key1 = hash('sha256', 'key1', true);
        $key2 = hash('sha256', 'key2', true);

        $encryptor1 = new OpenSSLEncryption($key1);
        $encryptor2 = new OpenSSLEncryption($key2);

        $encrypted1 = $encryptor1->encrypt($data);
        $encrypted2 = $encryptor2->encrypt($data);

        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function testInvalidMethodThrowsException(): void
    {
        $this->expectException(\Exception::class);
        new OpenSSLEncryption($this->key, 'invalid-method');
    }

    public function testDecryptInvalidDataThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->encryptor->decrypt('invalid-base64-data');
    }
}
