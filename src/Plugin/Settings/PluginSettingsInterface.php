<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Settings;

/**
 * The one way a plugin reads its own settings (contract C14).
 *
 * A plugin declares its settings in the manifest (`settings[]`: `key`, `label`, `type`, `secret`, `required`,
 * `default`, `envOverride`). The host stores the values, encrypts secrets, lets the administrator edit them
 * through `GET`/`PUT /admin/plugins/{pluginId}/settings`, and hands them back here. A plugin never keeps a
 * settings table or an encryption of its own.
 *
 * What a read returns, in this order:
 *  1. the environment variable named by `envOverride`, when it is set and fits the type;
 *  2. the stored value (a secret is decrypted here, and only here);
 *  3. the manifest `default`;
 *  4. `null`.
 */
interface PluginSettingsInterface
{
    /**
     * The value of one setting of a plugin: a string (`string`, `url`), an int or float (`number`), a bool
     * (`boolean`), or `null` when nothing is set and the manifest declares no default.
     *
     * A secret whose stored text cannot be decrypted (the database came from an installation with another
     * `APP_SECRET`) reads as `null`, as if it had never been entered.
     *
     * @throws \InvalidArgumentException when the plugin is not installed or its manifest declares no such setting
     */
    public function get(string $pluginId, string $key): string|int|float|bool|null;
}
