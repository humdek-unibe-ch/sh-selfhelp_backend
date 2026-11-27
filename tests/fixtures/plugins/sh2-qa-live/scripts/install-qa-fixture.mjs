#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */
/**
 * Install the sh2-qa-live test fixture into a local backend (e2e / P10-A1).
 *
 * Usage:
 *   node scripts/install-qa-fixture.mjs [--backend <dir>] [--uninstall|--reinstall]
 */

import { spawnSync } from 'node:child_process';
import {
    copyFileSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const FIXTURE_ROOT = path.resolve(__dirname, '..');
const PLUGIN_ID = 'sh2-qa-live';
const PLUGIN_VERSION = '1.0.0';

function parseArgs(argv) {
    let backend = process.env.SELFHELP_BACKEND_DIR ?? '';
    let uninstall = false;
    let reinstall = false;

    for (let i = 2; i < argv.length; i++) {
        const arg = argv[i];
        if (arg === '--uninstall') {
            uninstall = true;
        } else if (arg === '--reinstall') {
            reinstall = true;
        } else if (arg === '--backend' && argv[i + 1]) {
            backend = argv[++i];
        } else if (!arg.startsWith('-') && !backend) {
            backend = arg;
        }
    }

    if (!backend) {
        backend = path.resolve(FIXTURE_ROOT, '..', '..', '..', '..');
    }

    return { backend: path.resolve(backend), uninstall, reinstall };
}

function phpEnv(extraEnv = {}) {
    // APP_ENV=dev: unsigned reviewed plugins install (PluginSignatureVerifier).
    // DATABASE_URL points at the same local MySQL the e2e stack uses
    // (selfhelp_test); under APP_ENV=test the when@test doctrine suffix would
    // append another `_test`. Keep redis/mercure on the test stack ports.
    // MESSENGER_PLUGIN_OPS_DSN=sync:// so install/uninstall finalise in-process
    // (dev default); messenger:consume would otherwise wait for a message that
    // never arrives.
    return {
        ...process.env,
        APP_ENV: 'dev',
        APP_DEBUG: '0',
        SELFHELP_PLUGIN_INSTALL_MODE: 'trusted',
        SELFHELP_ALLOW_WEB_PLUGIN_INSTALL: 'true',
        SELFHELP_PLUGIN_REQUIRE_SIGNATURE: 'false',
        MESSENGER_PLUGIN_OPS_DSN: 'sync://',
        DATABASE_URL:
            process.env.SELFHELP_QA_LIVE_DATABASE_URL
            || 'mysql://root:@127.0.0.1:3306/selfhelp_test?serverVersion=8.0.32&charset=utf8mb4',
        REDIS_URL: process.env.REDIS_URL || 'redis://localhost:6380?persistent=1&retry_interval=0&timeout=0.2',
        ...extraEnv,
    };
}

function runPhp(backend, args, extraEnv = {}) {
    const result = spawnSync('php', ['bin/console', ...args], {
        cwd: backend,
        env: phpEnv(extraEnv),
        stdio: 'inherit',
        shell: process.platform === 'win32',
    });
    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }
}

/** Drain plugin_ops when it is a real queue; with sync:// this returns at once. */
function drainPluginOps(backend) {
    runPhp(backend, [
        'messenger:consume',
        'plugin_ops',
        '--limit=1',
        '--time-limit=15',
        '--no-debug',
    ]);
}

/**
 * Install, enable and uninstall run under APP_ENV=dev. The Redis pools and the compiled
 * Symfony container of APP_ENV=test are separate: without a rebuild they keep the plugin
 * bundle (after an uninstall: its Doctrine mapping, so `doctrine:schema:validate` fails with
 * "database schema is not in sync") or miss it (after an install: no plugin routes).
 * No warmup here, the e2e stack warms the container next.
 */
function rebuildTestCaches(backend) {
    runPhp(backend, ['cache:pool:clear', 'cache.app', '--no-debug'], {
        APP_ENV: 'test',
        APP_DEBUG: '0',
    });
    runPhp(backend, ['cache:clear', '--env=test', '--no-warmup', '--no-debug'], {
        APP_ENV: 'test',
        APP_DEBUG: '0',
    });
}

function ensureArtifacts(backend) {
    const source = path.join(FIXTURE_ROOT, 'dist', 'plugin.esm.js');
    if (!existsSync(source)) {
        console.error(`Missing ${source}`);
        process.exit(1);
    }
    const targetDir = path.join(backend, 'public', 'plugin-artifacts', `${PLUGIN_ID}-${PLUGIN_VERSION}`);
    mkdirSync(targetDir, { recursive: true });
    copyFileSync(source, path.join(targetDir, 'plugin.esm.js'));
}

function buildTempManifest() {
    const manifestPath = path.join(FIXTURE_ROOT, 'plugin.json');
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    manifest.backend ??= {};
    manifest.backend.composer ??= {};
    manifest.backend.composer.repository = {
        type: 'path',
        url: FIXTURE_ROOT,
    };
    manifest.frontend ??= {};
    manifest.frontend.runtime ??= {};
    manifest.frontend.runtime.entrypoint = `/plugin-artifacts/${PLUGIN_ID}-${PLUGIN_VERSION}/plugin.esm.js`;
    const tmpDir = mkdtempSync(path.join(tmpdir(), 'sh2-qa-live-manifest-'));
    const out = path.join(tmpDir, 'plugin.json');
    writeFileSync(out, JSON.stringify(manifest, null, 2));
    return { out, tmpDir };
}

function main() {
    const { backend, uninstall, reinstall } = parseArgs(process.argv);

    if (!existsSync(path.join(backend, 'bin', 'console'))) {
        console.error(`Backend not found at ${backend} (expected bin/console)`);
        process.exit(1);
    }

    if (uninstall || reinstall) {
        runPhp(backend, ['selfhelp:plugin:uninstall', PLUGIN_ID, '--no-debug']);
        drainPluginOps(backend);
        if (uninstall) {
            // The uninstall rewrote the bundle list and the lock file; the `test` container
            // and pools still know the plugin until they are rebuilt.
            rebuildTestCaches(backend);
            console.log(`Uninstalled ${PLUGIN_ID}.`);
            return;
        }
    }

    ensureArtifacts(backend);
    const { out, tmpDir } = buildTempManifest();
    try {
        runPhp(backend, ['selfhelp:plugin:install', out, '--no-debug']);
        drainPluginOps(backend);
        runPhp(backend, ['selfhelp:plugin:enable', PLUGIN_ID, '--no-debug']);
        // Install/enable ran under APP_ENV=dev. Redis keys and the compiled
        // Symfony container are env-namespaced: clearing only the `dev` pool
        // leaves a stale `test` `api_routes` list and a pre-plugin container,
        // so e2e `php -S` (APP_ENV=test, APP_DEBUG=0) dumps a matcher without
        // plugin routes and/or misses plugin controller services. Rebuild the
        // test env filesystem cache (no warmup here — e2e-stack warms next).
        rebuildTestCaches(backend);
        console.log(`Installed and enabled ${PLUGIN_ID}@${PLUGIN_VERSION}.`);
    } finally {
        rmSync(tmpDir, { recursive: true, force: true });
    }
}

main();