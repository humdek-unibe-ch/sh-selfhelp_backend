<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Settings;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginSetting;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\Plugin\PluginSettingRepository;
use App\Service\Auth\UserContextService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores, shows and hands out the settings a plugin declares (contract C14).
 *
 * One service for the three jobs:
 *  - {@see get()}: what the plugin reads (the one place a secret is decrypted);
 *  - {@see view()}: what the administrator sees; a secret is **never** returned, only whether it is set and
 *    a hint (its last four characters when it has twelve or more);
 *  - {@see update()}: what the administrator saves; every entry is validated before anything is written.
 *
 * The manifest decides what exists (`settings[]`: `key`, `label`, `type`, `secret`, `required`, `default`,
 * `envOverride`); the host decides how it is stored (table `plugin_settings`, keyed by the plugin id, secrets as
 * `enc:v1:`, see {@see PluginSettingsCrypto}). An environment variable named by `envOverride` wins over the
 * stored value and the administrator sees that (`overridden_by_env`).
 *
 * Errors: 400 `invalid_plugin_setting` (data: `key`, `reason`, `detail`), 404 for a plugin that is not installed.
 * The transaction log gets one line with the **keys** that changed, never a value.
 */
final class PluginSettingsService implements PluginSettingsInterface
{
    public const ERROR_INVALID = 'invalid_plugin_setting';

    public const TYPE_STRING = 'string';
    public const TYPE_NUMBER = 'number';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_URL = 'url';

    /** Longest text a `string` setting may have. */
    public const MAX_TEXT_LENGTH = 4000;

    /** Longest `url` setting. */
    public const MAX_URL_LENGTH = 2048;

    /** A secret this long (in characters) or longer gets a hint: its last {@see HINT_LENGTH} characters. */
    public const HINT_MIN_LENGTH = 12;
    public const HINT_LENGTH = 4;

    /** Hosts that may use plain `http` for a `url` setting. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    public function __construct(
        private readonly PluginRegistryService $registry,
        private readonly PluginSettingRepository $settings,
        private readonly PluginSettingsCrypto $crypto,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactions,
        private readonly UserContextService $userContext,
    ) {
    }

    public function get(string $pluginId, string $key): string|int|float|bool|null
    {
        $plugin = $this->registry->findByPluginId($pluginId);
        if ($plugin === null) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" is not installed.', $pluginId));
        }

        $definition = $this->definitions($plugin)[$key] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" declares no setting "%s".', $pluginId, $key));
        }

        return $this->effective($definition, $this->settings->findOneByPluginIdAndKey($pluginId, $key))['value'];
    }

    /**
     * The settings of a plugin as the administrator sees them.
     *
     * @return list<array{key:string,label:string,type:string,secret:bool,required:bool,has_value:bool,value:string|int|float|bool|null,hint:string|null,overridden_by_env:bool}>
     *
     * @throws ServiceException 404 when the plugin is not installed
     */
    public function view(string $pluginId): array
    {
        $plugin = $this->installed($pluginId);
        $stored = $this->settings->findByPluginId($pluginId);

        $view = [];
        foreach ($this->definitions($plugin) as $key => $definition) {
            $row = $stored[$key] ?? null;
            $effective = $this->effective($definition, $row);
            $secret = $definition['secret'] || ($row !== null && $row->isSecret());
            $value = $effective['value'];

            $view[] = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'secret' => $secret,
                'required' => $definition['required'],
                'has_value' => $effective['source'] === 'env' || $effective['source'] === 'stored',
                'value' => $secret ? null : $value,
                'hint' => $secret ? $this->hint($value) : null,
                'overridden_by_env' => $effective['source'] === 'env',
            ];
        }

        return $view;
    }

    /**
     * Save values. Every entry is checked first; one bad entry means nothing is written.
     *
     * For each key in `$values`: a value sets (a secret is encrypted), `null` clears. A key that is **not** in
     * `$values` stays as it is, so a form that never shows a secret cannot wipe it by saving.
     *
     * @param array<string, mixed> $values setting key => new value (`null` clears)
     *
     * @return list<array{key:string,label:string,type:string,secret:bool,required:bool,has_value:bool,value:string|int|float|bool|null,hint:string|null,overridden_by_env:bool}>
     *
     * @throws ServiceException 404 when the plugin is not installed, 400 `invalid_plugin_setting`
     */
    public function update(string $pluginId, array $values): array
    {
        $plugin = $this->installed($pluginId);
        $definitions = $this->definitions($plugin);

        // 1. Validate everything; nothing is written yet.
        /** @var array<string, string|null> $texts the text to store per key, null = clear */
        $texts = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $definition = $definitions[$key] ?? null;
            if ($definition === null) {
                throw $this->invalid($key, 'unknown_setting', sprintf('Plugin "%s" declares no setting "%s".', $pluginId, $key));
            }
            $texts[$key] = $value === null ? null : $this->normalize($definition, $value);
        }

        // 2. Write in one transaction.
        $changed = [];
        $this->entityManager->beginTransaction();
        try {
            $by = $this->currentUser();
            foreach ($texts as $key => $text) {
                $row = $this->settings->findOneByPluginIdAndKey($pluginId, $key);

                if ($text === null) {
                    if ($row !== null) {
                        $this->entityManager->remove($row);
                        $changed[] = $key;
                    }
                    continue;
                }

                $secret = $definitions[$key]['secret'];
                if ($row !== null && $row->isSecret() === $secret && $this->plainOf($row) === $text) {
                    continue; // the same value again: not a change
                }

                $stored = $secret ? $this->crypto->encrypt($text) : $text;
                if ($row === null) {
                    // A first value is written with one upsert: two administrators saving the same new key at the
                    // same moment both find no row, and a plain insert would make the second one hit
                    // `uq_plugin_settings_plugin_key` (and close the entity manager). The last save wins.
                    $this->upsertNew($pluginId, $key, $secret, $stored, $by);
                    $changed[] = $key;
                    continue;
                }

                $row->setSecret($secret)->setValue($stored)->touch($by);
                $this->entityManager->persist($row);
                $changed[] = $key;
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();
            throw $exception;
        }

        if ($changed !== []) {
            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugin_settings',
                null,
                false,
                sprintf('Plugin settings of "%s" changed: %s', $pluginId, implode(', ', $changed)),
            );
        }

        return $this->view($pluginId);
    }

    /**
     * Insert the first value of a key, or overwrite the row a concurrent save has just created (one SQL statement,
     * so the unique key `(plugin_id, setting_key)` cannot be violated). The value is already encrypted for a secret.
     */
    private function upsertNew(string $pluginId, string $key, bool $secret, string $stored, ?User $by): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO plugin_settings (plugin_id, setting_key, value, is_secret, updated_at, id_users_updated) '
            . 'VALUES (:plugin_id, :setting_key, :value, :is_secret, :updated_at, :id_users_updated) '
            . 'ON DUPLICATE KEY UPDATE value = VALUES(value), is_secret = VALUES(is_secret), '
            . 'updated_at = VALUES(updated_at), id_users_updated = VALUES(id_users_updated)',
            [
                'plugin_id' => $pluginId,
                'setting_key' => $key,
                'value' => $stored,
                'is_secret' => $secret ? 1 : 0,
                'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'id_users_updated' => $by?->getId(),
            ],
        );
    }

    /**
     * Delete every stored value of a plugin (the purge; an uninstall keeps them).
     *
     * @return int how many values were deleted
     */
    public function purge(string $pluginId): int
    {
        $deleted = $this->settings->deleteByPluginId($pluginId);

        if ($deleted > 0) {
            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugin_settings',
                null,
                false,
                sprintf('Plugin settings of "%s" deleted by the purge: %d value(s)', $pluginId, $deleted),
            );
        }

        return $deleted;
    }

    /**
     * @throws ServiceException 404
     */
    private function installed(string $pluginId): Plugin
    {
        $plugin = $this->registry->findByPluginId($pluginId);
        if ($plugin === null) {
            throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
        }

        return $plugin;
    }

    /**
     * The settings a plugin declares, by key.
     *
     * @return array<string, array{key:string,label:string,type:string,secret:bool,required:bool,default:mixed,envOverride:string|null}>
     */
    private function definitions(Plugin $plugin): array
    {
        $definitions = [];
        foreach ((new PluginManifest($plugin->getManifestJson()))->getSettings() as $setting) {
            $key = $setting['key'] ?? null;
            $type = $setting['type'] ?? null;
            if (!is_string($key) || !is_string($type)) {
                continue;
            }
            $label = $setting['label'] ?? null;
            $envOverride = $setting['envOverride'] ?? null;

            $definitions[$key] = [
                'key' => $key,
                'label' => is_string($label) ? $label : $key,
                'type' => $type,
                'secret' => ($setting['secret'] ?? false) === true,
                'required' => ($setting['required'] ?? false) === true,
                'default' => $setting['default'] ?? null,
                'envOverride' => is_string($envOverride) && $envOverride !== '' ? $envOverride : null,
            ];
        }

        return $definitions;
    }

    /**
     * What a read gets: the environment override, else the stored value, else the default.
     *
     * @param array{key:string,label:string,type:string,secret:bool,required:bool,default:mixed,envOverride:string|null} $definition
     *
     * @return array{value:string|int|float|bool|null,source:'env'|'stored'|'default'|'none'}
     */
    private function effective(array $definition, ?PluginSetting $row): array
    {
        $type = $definition['type'];

        $env = $this->environment($definition['envOverride']);
        if ($env !== null) {
            $typed = $this->typed($type, $env);
            if ($typed !== null) {
                return ['value' => $typed, 'source' => 'env'];
            }
        }

        if ($row !== null) {
            $plain = $this->plainOf($row);
            $typed = $plain === null ? null : $this->typed($type, $plain);
            if ($typed !== null) {
                return ['value' => $typed, 'source' => 'stored'];
            }
        }

        $default = $definition['default'];
        if (!$definition['secret'] && (is_string($default) || is_int($default) || is_float($default) || is_bool($default))) {
            $typed = $this->typed($type, is_bool($default) ? ($default ? '1' : '0') : (string) $default);
            if ($typed !== null) {
                return ['value' => $typed, 'source' => 'default'];
            }
        }

        return ['value' => null, 'source' => 'none'];
    }

    /** The environment variable, or null when it is unset or empty. */
    private function environment(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** The clear text of a stored row; null when it is a secret that cannot be decrypted, or has no value. */
    private function plainOf(PluginSetting $row): ?string
    {
        $stored = $row->getValue();
        if ($stored === null) {
            return null;
        }

        return $row->isSecret() ? $this->crypto->decrypt($stored) : $stored;
    }

    /**
     * A text read from the database or the environment as the type the manifest declares; null when it does
     * not fit the type.
     */
    private function typed(string $type, string $text): string|int|float|bool|null
    {
        switch ($type) {
            case self::TYPE_STRING:
            case self::TYPE_URL:
                return $text;
            case self::TYPE_NUMBER:
                if (!is_numeric($text)) {
                    return null;
                }

                return preg_match('/^-?\d{1,18}$/', $text) === 1 ? (int) $text : (float) $text;
            case self::TYPE_BOOLEAN:
                $lower = strtolower(trim($text));
                if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }

                return in_array($lower, ['0', 'false', 'no', 'off'], true) ? false : null;
            default:
                return null;
        }
    }

    /** The last characters of a long secret; null for a short one or none. */
    private function hint(string|int|float|bool|null $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $text = (string) $value;

        return mb_strlen($text) >= self::HINT_MIN_LENGTH ? mb_substr($text, -self::HINT_LENGTH) : null;
    }

    /**
     * Check one value against its declaration and return the text to store.
     *
     * @param array{key:string,label:string,type:string,secret:bool,required:bool,default:mixed,envOverride:string|null} $definition
     *
     * @throws ServiceException 400 `invalid_plugin_setting`
     */
    private function normalize(array $definition, mixed $value): string
    {
        $key = $definition['key'];

        switch ($definition['type']) {
            case self::TYPE_STRING:
                if (!is_string($value)) {
                    throw $this->invalid($key, 'wrong_type', 'This setting takes a string.');
                }
                if ($value === '') {
                    throw $this->invalid($key, 'empty', 'Send null to clear a setting; an empty string is not a value.');
                }
                if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
                    throw $this->invalid($key, 'too_long', sprintf('At most %d characters.', self::MAX_TEXT_LENGTH));
                }

                return $value;

            case self::TYPE_URL:
                if (!is_string($value)) {
                    throw $this->invalid($key, 'wrong_type', 'This setting takes a url as a string.');
                }
                $this->assertUrl($key, $value);

                return $value;

            case self::TYPE_NUMBER:
                if (is_int($value)) {
                    return (string) $value;
                }
                if (is_float($value) && is_finite($value)) {
                    return (string) $value;
                }
                throw $this->invalid($key, 'wrong_type', 'This setting takes a number.');

            case self::TYPE_BOOLEAN:
                if (!is_bool($value)) {
                    throw $this->invalid($key, 'wrong_type', 'This setting takes true or false.');
                }

                return $value ? '1' : '0';

            default:
                throw $this->invalid($key, 'unsupported_type', sprintf('The manifest declares the unknown type "%s".', $definition['type']));
        }
    }

    /**
     * A `url` setting is an `https` address; plain `http` is for the machine itself (localhost, 127.0.0.1, ::1).
     *
     * @throws ServiceException 400 `invalid_plugin_setting`
     */
    private function assertUrl(string $key, string $url): void
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            throw $this->invalid($key, 'invalid_url', sprintf('A url of 1 to %d characters.', self::MAX_URL_LENGTH));
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower(trim($parts['host'] ?? '', '[]')) : '';
        if ($host === '' || $scheme === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw $this->invalid($key, 'invalid_url', 'This is not a url.');
        }

        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true)) {
            return;
        }

        throw $this->invalid($key, 'url_must_be_https', 'Use https; plain http is accepted for localhost only.');
    }

    private function invalid(string $key, string $reason, string $detail): ServiceException
    {
        return new ServiceException(self::ERROR_INVALID, Response::HTTP_BAD_REQUEST, [
            'key' => $key,
            'reason' => $reason,
            'detail' => $detail,
        ]);
    }

    /** The signed-in administrator as a reference this entity manager owns; null outside a request. */
    private function currentUser(): ?User
    {
        $id = $this->userContext->getCurrentUser()?->getId();

        return $id === null ? null : $this->entityManager->getReference(User::class, $id);
    }
}
