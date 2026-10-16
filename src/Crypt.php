<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use RuntimeException;
use SensitiveParameter;

/**
 * Cifrado autenticado (AES-256-GCM, con openssl) para guardar secretos en la base de datos: API keys de
 * terceros, tokens. Un valor alterado o cifrado con otra clave no se descifra: lanza excepción.
 *
 *     $guardado = Crypt::encrypt($apiKey);        // texto, número o arreglo
 *     $apiKey = Crypt::decrypt($guardado);
 *
 * La clave es `crypt.key` (32 bytes en base64) o, si falta, una derivada de `session.key`. Cambiar
 * cualquiera de las dos hace ilegible lo ya cifrado.
 */
final class Crypt
{
    private const string PREFIX = 'v1:';

    private const string CIPHER = 'aes-256-gcm';

    private const int NONCE_BYTES = 12;

    private const int TAG_BYTES = 16;

    public static function encrypt(#[SensitiveParameter] mixed $value): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $plain = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES,
        );
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar.');
        }

        return self::PREFIX . rtrim(strtr(base64_encode($nonce . $tag . $cipher), '+/', '-_'), '=');
    }

    public static function decrypt(string $payload): mixed
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('No es un valor cifrado con Crypt.');
        }

        $raw = base64_decode(strtr(substr($payload, strlen(self::PREFIX)), '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= (self::NONCE_BYTES + self::TAG_BYTES)) {
            throw new RuntimeException('No se pudo descifrar: el valor está dañado.');
        }

        $plain = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES),
            substr($raw, self::NONCE_BYTES, self::TAG_BYTES),
        );
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar: el valor fue alterado o se cifró con otra clave.');
        }

        return json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
    }

    /** ¿Lo cifró Crypt? (para migrar valores guardados en texto plano o con otro método) */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Una clave de 32 bytes para cada uso ('crypt', 'url'…), derivada de la del proyecto: así una no
     * sirve para lo otro.
     *
     * @internal
     */
    public static function key(string $purpose = 'crypt'): string
    {
        global $config;

        $configured = (string) ($config['crypt']['key'] ?? '');
        $base = $configured !== '' ? base64_decode($configured, true) : (string) ($config['session']['key'] ?? '');
        if (!is_string($base) || strlen($base) < 32) {
            throw new RuntimeException(
                'Falta una clave: config.json debe tener session.key (o crypt.key, 32 bytes en base64).',
            );
        }

        return hash_hkdf('sha256', $base, 32, 'versawys:' . $purpose);
    }
}
