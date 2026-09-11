<?php

declare(strict_types=1);

namespace App\User\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * Application-level encryption for sensitive profile data (SPEC.md § 10, § 24.8).
 *
 * Residence status and the other profile tags say a lot about a person, so they are stored
 * encrypted with libsodium (XSalsa20-Poly1305) and are never logged, never sent to AI providers and
 * never written to git. Matching therefore happens in PHP over decrypted batches, while only land,
 * language and topics stay in clear columns for SQL pre-filtering.
 *
 * The key (APP_ENCRYPTION_KEY) is injected once at boot by App\Core\PatchnotesBundle.
 */
final class EncryptedJsonType extends Type
{
    public const string NAME = 'encrypted_json';

    private static ?string $key = null;

    /**
     * @param non-empty-string|null $key
     */
    public static function setEncryptionKey(?string $key): void
    {
        self::$key = $key;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $plaintext = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        // The nonce must travel with the ciphertext; it is not secret.
        return $nonce.$ciphertext;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if (null === $value) {
            return null;
        }

        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        $value = (string) $value;
        if ('' === $value) {
            return null;
        }

        if (\strlen($value) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw ValueNotConvertible::new($value, self::NAME, 'the stored value is too short to be an encrypted payload');
        }

        $nonce = substr($value, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($value, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key());
        if (false === $plaintext) {
            // Wrong or rotated key: fail loudly instead of silently losing a profile.
            throw ValueNotConvertible::new('***', self::NAME, 'the value cannot be decrypted with the configured APP_ENCRYPTION_KEY');
        }

        /** @var array<string, mixed>|list<mixed> $decoded */
        $decoded = json_decode($plaintext, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return non-empty-string
     */
    private function key(): string
    {
        if (null === self::$key || '' === self::$key) {
            throw new \LogicException('APP_ENCRYPTION_KEY is not configured: run "make install" (patchnotes:secrets:generate).');
        }

        $key = base64_decode(self::$key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \LogicException(\sprintf('APP_ENCRYPTION_KEY must be base64 of %d bytes.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        return $key;
    }
}
