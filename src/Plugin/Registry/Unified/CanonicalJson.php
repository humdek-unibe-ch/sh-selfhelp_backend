<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * Generic canonical-JSON encoder for unified-registry release documents.
 *
 * MUST stay byte-identical with the Manager's `@shm/registry` `canonicalize()`
 * (`sh-manager/packages/registry/src/canonical.ts`) and the registry's
 * `scripts/sign.mjs` `canonicalStringify`, so a `PluginRelease` / `CoreRelease`
 * signed by CI verifies here:
 *
 *   - object keys sorted ascending (recursive);
 *   - arrays kept in order;
 *   - strings/numbers/booleans/null serialised via JSON;
 *   - no insignificant whitespace;
 *   - forward slashes and unicode NOT escaped (matches JS `JSON.stringify`).
 *
 * This is the registry-document sibling of {@see \App\Plugin\Security\SignedPayloadBuilder},
 * which canonicalises the narrower `.shplugin` signed payload. Both use the same
 * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` + recursive key-sort rules.
 */
final class CanonicalJson
{
    /**
     * @param mixed $value
     */
    public static function encode($value): string
    {
        $json = json_encode(self::sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('CanonicalJson: failed to encode canonical JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn ($v) => self::sortRecursive($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortRecursive($v);
        }
        return $out;
    }
}
