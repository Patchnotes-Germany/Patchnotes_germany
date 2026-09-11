<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Doctrine;

use App\User\Doctrine\EncryptedJsonType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncryptedJsonType::class)]
final class EncryptedJsonTypeTest extends TestCase
{
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        if (!Type::hasType(EncryptedJsonType::NAME)) {
            Type::addType(EncryptedJsonType::NAME, EncryptedJsonType::class);
        }

        // A stub is enough: the conversion never touches the platform.
        $this->platform = self::createStub(AbstractPlatform::class);
        EncryptedJsonType::setEncryptionKey(base64_encode(sodium_crypto_secretbox_keygen()));
    }

    protected function tearDown(): void
    {
        EncryptedJsonType::setEncryptionKey(null);
    }

    public function testProfileTagsSurviveARoundTrip(): void
    {
        $type = $this->type();
        $tags = ['blue_card', 'married', 'children_under_3'];

        $stored = $type->convertToDatabaseValue($tags, $this->platform);

        self::assertIsString($stored);
        self::assertSame($tags, $type->convertToPHPValue($stored, $this->platform));
    }

    public function testTheStoredValueRevealsNothing(): void
    {
        $stored = (string) $this->type()->convertToDatabaseValue(['duldung', 'asylum_procedure'], $this->platform);

        self::assertStringNotContainsString('duldung', $stored);
        self::assertStringNotContainsString('asylum_procedure', $stored);
    }

    public function testEveryWriteUsesAFreshNonce(): void
    {
        $type = $this->type();
        $tags = ['blue_card'];

        // Identical profiles must not produce identical ciphertexts, otherwise the database would
        // leak which users share a residence status.
        self::assertNotSame(
            $type->convertToDatabaseValue($tags, $this->platform),
            $type->convertToDatabaseValue($tags, $this->platform),
        );
    }

    public function testNullStaysNull(): void
    {
        $type = $this->type();

        self::assertNull($type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function testAMissingKeyIsAnExplicitFailure(): void
    {
        EncryptedJsonType::setEncryptionKey(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/APP_ENCRYPTION_KEY/');

        $this->type()->convertToDatabaseValue(['blue_card'], $this->platform);
    }

    public function testDataEncryptedWithAnotherKeyIsNotSilentlyLost(): void
    {
        $type = $this->type();
        $stored = (string) $type->convertToDatabaseValue(['blue_card'], $this->platform);

        EncryptedJsonType::setEncryptionKey(base64_encode(sodium_crypto_secretbox_keygen()));

        $this->expectException(\Doctrine\DBAL\Types\ConversionException::class);

        $type->convertToPHPValue($stored, $this->platform);
    }

    private function type(): EncryptedJsonType
    {
        $type = Type::getType(EncryptedJsonType::NAME);
        self::assertInstanceOf(EncryptedJsonType::class, $type);

        return $type;
    }
}
