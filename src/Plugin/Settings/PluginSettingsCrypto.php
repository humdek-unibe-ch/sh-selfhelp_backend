<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the secrets of plugin settings (contract C14).
 *
 * libsodium `secretbox` (XSalsa20-Poly1305). The key is derived from `APP_SECRET`: HKDF-SHA256 with the
 * info string `selfhelp-plugin-settings`, 32 bytes, so the same secret never serves as a key for anything
 * else. Every value gets a fresh random nonce. The stored text is `enc:v1:` followed by the base64 of the
 * nonce and the ciphertext; `v1` names this scheme so a later one can live next to it.
 *
 * A text that cannot be opened, because it is no `enc:v1:` value, was damaged, or was encrypted under
 * another `APP_SECRET` (the database was restored as a new instance), reads as **not set**
 * ({@see decrypt()} returns null); the administrator enters the secret again. Nothing here throws on bad input
 * from the database.
 */
final class PluginSettingsCrypto
{
    public const PREFIX = 'enc:v1:';

    /** The HKDF info string that makes the key specific to this use. */
    public const KEY_INFO = 'selfhelp-plugin-settings';

    private readonly string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET is empty; plugin secrets cannot be encrypted without it.');
        }

        $this->key = hash_hkdf('sha256', $appSecret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KEY_INFO);
    }

    /** Whether a stored text has the shape of an encrypted value (it may still be unreadable). */
    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * @return string `enc:v1:<base64 of nonce and ciphertext>`
     */
    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /**
     * @return string|null the clear text, or null when the stored text cannot be opened with this `APP_SECRET`
     */
    public function decrypt(string $stored): ?string
    {
        if (!self::isEncrypted($stored)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);

        return $plain === false ? null : $plain;
    }
}
