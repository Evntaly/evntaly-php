<?php

namespace Evntaly\Tests\Encryption;

use Evntaly\Encryption\EncryptionInterface;
use Evntaly\Encryption\FieldEncryptor;
use PHPUnit\Framework\TestCase;

class FieldEncryptorTest extends TestCase
{
    /**
     * @var EncryptionInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $encryptor;

    /**
     * @var FieldEncryptor
     */
    private $fieldEncryptor;

    protected function setUp(): void
    {
        $this->encryptor = $this->createMock(EncryptionInterface::class);
        $this->encryptor->method('isReady')->willReturn(true);
        $this->encryptor->method('encrypt')->willReturnCallback(function ($value) {
            return 'encrypted_' . $value;
        });
        $this->encryptor->method('decrypt')->willReturnCallback(function ($value) {
            return str_replace('encrypted_', '', $value);
        });

        $this->fieldEncryptor = new FieldEncryptor($this->encryptor, ['password', 'secret', 'credit_card']);
    }

    public function testAddSensitiveField(): void
    {
        $this->fieldEncryptor->addSensitiveField('email');
        $this->assertContains('email', $this->fieldEncryptor->getSensitiveFields());
    }

    public function testRemoveSensitiveField(): void
    {
        $this->fieldEncryptor->removeSensitiveField('password');
        $this->assertNotContains('password', $this->fieldEncryptor->getSensitiveFields());
    }

    public function testSetSensitiveFields(): void
    {
        $this->fieldEncryptor->setSensitiveFields(['email', 'phone']);
        $this->assertEquals(['email', 'phone'], $this->fieldEncryptor->getSensitiveFields());
    }

    public function testProcessEvent(): void
    {
        $event = [
            'title' => 'User Registration',
            'data' => [
                'username' => 'johndoe',
                'password' => 'secret123',
                'credit_card' => '4111-1111-1111-1111',
                'age' => 30,
                'nested' => [
                    'secret_key' => 'xyz456',
                    'public_info' => 'public',
                ],
            ],
        ];

        $processed = $this->fieldEncryptor->processEvent($event);

        // Check that sensitive fields are encrypted
        $this->assertStringStartsWith('__ENC__:', $processed['data']['password']);
        $this->assertStringStartsWith('__ENC__:', $processed['data']['credit_card']);
        $this->assertStringStartsWith('__ENC__:', $processed['data']['nested']['secret_key']);

        // Check that non-sensitive fields are untouched
        $this->assertEquals('johndoe', $processed['data']['username']);
        $this->assertEquals(30, $processed['data']['age']);
        $this->assertEquals('public', $processed['data']['nested']['public_info']);
    }

    public function testDecryptEvent(): void
    {
        $event = [
            'title' => 'User Registration',
            'data' => [
                'username' => 'johndoe',
                'password' => '__ENC__:encrypted_secret123',
                'credit_card' => '__ENC__:encrypted_4111-1111-1111-1111',
                'age' => 30,
            ],
        ];

        $decrypted = $this->fieldEncryptor->decryptEvent($event);

        $this->assertEquals('secret123', $decrypted['data']['password']);
        $this->assertEquals('4111-1111-1111-1111', $decrypted['data']['credit_card']);
        $this->assertEquals('johndoe', $decrypted['data']['username']);
        $this->assertEquals(30, $decrypted['data']['age']);
    }

    public function testIsEncrypted(): void
    {
        $this->assertTrue($this->fieldEncryptor->isEncrypted('__ENC__:somevalue'));
        $this->assertFalse($this->fieldEncryptor->isEncrypted('plaintext'));
        $this->assertFalse($this->fieldEncryptor->isEncrypted(123));
        $this->assertFalse($this->fieldEncryptor->isEncrypted(null));
    }

    public function testEncryptorNotReady(): void
    {
        $notReadyEncryptor = $this->createMock(EncryptionInterface::class);
        $notReadyEncryptor->method('isReady')->willReturn(false);

        $fieldEncryptor = new FieldEncryptor($notReadyEncryptor);

        $event = [
            'data' => [
                'password' => 'secret123',
            ],
        ];

        $processed = $fieldEncryptor->processEvent($event);

        // Should leave data unchanged when encryptor is not ready
        $this->assertEquals($event, $processed);
    }
}
