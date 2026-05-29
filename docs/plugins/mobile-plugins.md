# Mobile Plugins

The SelfHelp mobile app (Expo / React Native) consumes plugins
through the same `@selfhelp/shared/plugin-sdk` surface used by the
web frontend. Mobile plugins are **opt-in** per plugin via the
`mobile` block of `plugin.json`.

## Manifest shape

```jsonc
{
  "id": "sh2-shp-survey-js",
  "version": "1.0.0",
  "frontend": { "package": "@humdek/sh2-shp-survey-js" },
  "mobile": {
    "package": "@humdek/sh2-shp-survey-js-mobile",
    "entryPoint": "register",
    "supportsOffline": true,
    "minExpoSdk": "52.0.0"
  }
}
```

When `mobile` is **absent**, the plugin is web-only and the mobile
app silently ignores it. When `mobile.package` is set, the mobile app
loads it on startup through the same `definePlugin()` flow used by
the frontend.

## Mobile-only constraints

The shared SDK enforces three rules for `mobile` plugins:

1. **No DOM access** — plugins importing `document` / `window` /
   `HTMLElement` from the global scope fail the
   [`@selfhelp/shared/plugin-sdk/lint-mobile`](../../../sh-selfhelp_shared/scripts/lint-mobile-plugins.mjs)
   step in CI.
2. **No web-only host APIs** — `host.dom`, `host.fetchInBrowser`, and
   any other web-only `IPluginApi` field is `undefined` on mobile.
   Plugins must feature-detect.
3. **All UI through `host.ui`** — the shared SDK provides a
   minimal cross-platform UI primitive set (`Text`, `View`,
   `Pressable`, `TextInput`, `RichTextEditor`). Plugins must not
   import `react-native-*` directly; that breaks code-splitting and
   the web preview.

## Realtime on mobile

Mobile uses the [`usePluginRealtime`](../../../sh-selfhelp_mobile/hooks/usePluginRealtime.ts)
wrapper around the shared SDK hook. The wrapper injects the
`react-native-sse` transport on iOS/Android and falls back to the
browser `EventSource` on web preview. Plugin authors call the shared
hook directly:

```tsx
import { usePluginRealtime } from '@selfhelp/shared/plugin-sdk';

usePluginRealtime({
  pluginId: 'sh2-shp-survey-js',
  topics: ['survey/response'],
  onMessage: (m) => queryClient.invalidateQueries(['surveys']),
});
```

The wrapper handles platform detection, auth-token injection, and
reconnect with exponential backoff.

## Bundling

Mobile plugins are bundled as **separate Expo modules** and resolved
at Metro time. The mobile workspace's `package.json` lists every
mobile plugin under `dependencies`:

```jsonc
{
  "dependencies": {
    "@selfhelp/shared": "^1.0.4",
    "@humdek/sh2-shp-survey-js-mobile": "^1.0.0"
  }
}
```

Metro reads `node_modules/@humdek/sh2-shp-survey-js-mobile/dist/index.js`,
calls its exported `register(api)` factory, and registers the plugin
the same way the web does.

## Offline support

When `mobile.supportsOffline` is `true`, the plugin is bundled into
the OTA update so it works without a network connection. The plugin
SDK exposes `host.queryClient` (a TanStack Query client preconfigured
with persistence to MMKV) for plugins to cache their own data
locally.

When `mobile.supportsOffline` is `false`, the plugin's UI shows the
`OfflineUnavailable` placeholder when the device is offline. The
shared SDK provides this placeholder.

## Health probe

The mobile app reports the loaded plugin set to the backend at
startup so the doctor can show which mobile clients have stale
plugin versions:

```http
POST /cms-api/v1/mobile/plugin-runtime
Authorization: Bearer <jwt>

{
  "plugins": {
    "sh2-shp-survey-js": { "version": "1.0.0", "loaded": true }
  }
}
```

The doctor's `mobilePackages` site check is the **host-side** view of
the same data; the per-client view is in the admin's "Mobile clients"
panel (out of scope of the doctor).

## Related docs

- [Realtime & no polling](./realtime-and-no-polling.md)
- [Architecture](./architecture.md) (§ Mobile)
- [`@selfhelp/shared/plugin-sdk`](../../../sh-selfhelp_shared/src/plugin-sdk/README.md)
