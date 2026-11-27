<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Plugin;

use App\Plugin\Settings\PluginSettingsCrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract C14: a secret a plugin setting holds is encrypted with libsodium `secretbox`, the key is the
 * HKDF-SHA256 of `APP_SECRET` with the info string `selfhelp-plugin-settings`, and the stored text is
 * `enc:v1:<base64 of nonce and ciphertext>`.
 *
 * The test pins the format (so a database written today stays readable), the round trip, and the promise that a
 * text that cannot be opened (another `APP_SECRET`, damage, no prefix) reads as "not set" instead of throwing.
 */
final class PluginSettingsCryptoTest extends TestCase
{
    private const APP_SECRET = 'qa-app-secret-for-plugin-settings';

    public function testTheStoredTextHasTheVersionedPrefixAndNonceCiphertextLayout(): void
    {
        $stored = (new PluginSettingsCrypto(self::APP_SECRET))->encrypt('sk-live-1234567890');

        self::assertStringStartsWith('enc:v1:', $stored);
        $raw = base64_decode(substr($stored, strlen('enc:v1:')), true);
        self::assertIsString($raw, 'the part after the prefix is strict base64');
        self::assertSame(
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + strlen('sk-live-1234567890'),
            strlen($raw),
            'nonce (24) + authentication tag (16) + the cipher text, the same length as the clear text'
        );
    }

    public function testTheKeyIsTheHkdfOfTheAppSecretWithTheDocumentedInfo(): void
    {
        $stored = (new PluginSettingsCrypto(self::APP_SECRET))->encrypt('pinned');

        // Open it with a key derived here, by the documented recipe, not by the class under test.
        $raw = base64_decode(substr($stored, strlen('enc:v1:')), true);
        self::assertIsString($raw);
        $key = hash_hkdf('sha256', self::APP_SECRET, 32, 'selfhelp-plugin-settings');
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        self::assertSame('pinned', sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key));
    }

    public function testAValueEncryptedByTheDocumentedRecipeIsReadBackByTheClass(): void
    {
        $key = hash_hkdf('sha256', self::APP_SECRET, 32, 'selfhelp-plugin-settings');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $stored = 'enc:v1:' . base64_encode($nonce . sodium_crypto_secretbox('written elsewhere', $nonce, $key));

        self::assertSame('written elsewhere', (new PluginSettingsCrypto(self::APP_SECRET))->decrypt($stored));
    }

    /** @return iterable<string, array{string}> */
    public static function plainTexts(): iterable
    {
        yield 'a token' => ['sk-live-1234567890'];
        yield 'unicode' => ['Grüezi 👋 日本語'];
        yield 'an empty text' => [''];
        yield 'a long text' => [str_repeat('0123456789', 400)];
        yield 'binary bytes' => ["\x00\x01\xFE\xFF"];
        yield 'looks like a stored secret' => ['enc:v1:AAAA'];
    }

    #[DataProvider('plainTexts')]
    public function testEveryTextSurvivesTheRoundTrip(string $plain): void
    {
        $crypto = new PluginSettingsCrypto(self::APP_SECRET);

        $stored = $crypto->encrypt($plain);

        self::assertTrue(PluginSettingsCrypto::isEncrypted($stored));
        self::assertSame($plain, $crypto->decrypt($stored));
    }

    public function testTheSameTextEncryptsToADifferentStoredTextEachTime(): void
    {
        $crypto = new PluginSettingsCrypto(self::APP_SECRET);

        self::assertNotSame($crypto->encrypt('same'), $crypto->encrypt('same'), 'a fresh random nonce per value');
    }

    public function testTheClearTextIsNotInTheStoredText(): void
    {
        $stored = (new PluginSettingsCrypto(self::APP_SECRET))->encrypt('sk-live-1234567890');

        self::assertStringNotContainsString('sk-live', $stored);
        self::assertStringNotContainsString('1234567890', $stored);
    }

    public function testAnotherAppSecretReadsTheValueAsNotSet(): void
    {
        $stored = (new PluginSettingsCrypto(self::APP_SECRET))->encrypt('sk-live-1234567890');

        self::assertNull((new PluginSettingsCrypto('another-installation-secret'))->decrypt($stored));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableTexts(): iterable
    {
        yield 'a clear text without the prefix' => ['sk-live-1234567890'];
        yield 'an empty text' => [''];
        yield 'only the prefix' => ['enc:v1:'];
        yield 'another scheme version' => ['enc:v2:' . 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5'];
        yield 'not base64' => ['enc:v1:***not base64***'];
        yield 'shorter than a nonce and a tag' => ['enc:v1:' . 'QUJD'];
    }

    #[DataProvider('unreadableTexts')]
    public function testATextThatCannotBeOpenedReadsAsNotSetAndNeverThrows(string $stored): void
    {
        self::assertNull((new PluginSettingsCrypto(self::APP_SECRET))->decrypt($stored));
    }

    public function testADamagedValueReadsAsNotSet(): void
    {
        $crypto = new PluginSettingsCrypto(self::APP_SECRET);
        $raw = base64_decode(substr($crypto->encrypt('sk-live-1234567890'), strlen('enc:v1:')), true);
        self::assertIsString($raw);

        $flipped = $raw;
        $flipped[strlen($flipped) - 1] = $flipped[strlen($flipped) - 1] ^ "\x01";

        self::assertNull($crypto->decrypt('enc:v1:' . base64_encode($flipped)), 'one changed byte fails the authentication tag');
        self::assertNull($crypto->decrypt('enc:v1:' . base64_encode(substr($raw, 0, -3))), 'a truncated value fails too');
    }

    public function testIsEncryptedOnlyLooksAtThePrefix(): void
    {
        self::assertTrue(PluginSettingsCrypto::isEncrypted('enc:v1:anything'));
        self::assertFalse(PluginSettingsCrypto::isEncrypted('sk-live-1234567890'));
        self::assertFalse(PluginSettingsCrypto::isEncrypted('ENC:V1:anything'));
        self::assertFalse(PluginSettingsCrypto::isEncrypted(''));
    }

    public function testAnEmptyAppSecretIsRefusedInsteadOfEncryptingWithAKeyEveryoneKnows(): void
    {
        $this->expectException(\LogicException::class);

        new PluginSettingsCrypto('');
    }
}
