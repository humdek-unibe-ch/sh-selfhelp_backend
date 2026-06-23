# Mobile Plugins

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-23.
Source of truth: Plugin layer code and the schemas under this folder.

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
  "compatibility": {
    "core": ">=0.1.19 <0.2.0",
    "pluginApi": ">=0.1.0 <0.2.0",
    "reactNative": "^0.83",
    "expoSdk": "^55",
    "mobile": ">=0.1.0 <0.2.0"
  },
  "mobile": {
    "package": "@humdek/sh2-shp-survey-js-mobile",
    "version": "1.0.0",
    "readonly": true
  }
}
```

When `mobile` is **absent**, the plugin is web-only and the mobile
app renders plugin-owned styles through the web fallback. When
`mobile.package` is set, the package is bundled into the mobile build
by `sh-selfhelp_mobile/scripts/plugins-sync.mjs`, which generates the
mobile style registry used by the renderer. The package exports the
mobile SDK registration (`registerMobile` / `defineMobilePlugin`) from
its normal package entrypoint; there is no manifest `entryPoint`,
`supportsOffline`, or `minExpoSdk` field.

The `compatibility.mobile` range declares which mobile renderer
contract versions the plugin supports. The mobile app and the
`selfhelp-mobile-preview` image advertise their renderer contract as
`mobileRendererVersion`; the SelfHelp Manager blocks a preview image
when a plugin's `compatibility.mobile`, `compatibility.reactNative`, or
`compatibility.expoSdk` range is not satisfied.

## Mobile-only constraints

The shared SDK enforces three rules for `mobile` plugins:

1. **No DOM access** — plugins importing `document` / `window` /
   `HTMLElement` from the global scope fail the
   `@selfhelp/shared/plugin-sdk/lint-mobile`
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

## Preview image compatibility

The shared `selfhelp-mobile-preview` image bundles a curated set of
official mobile plugin packages and publishes that set as
`bundledPlugins` together with `mobileRendererVersion`. During
install/update preflight the Manager reports:

- **block** when `compatibility.mobile`, `compatibility.reactNative`,
  or `compatibility.expoSdk` does not match the selected preview image;
- **warn** when a compatible mobile package is not bundled, or the
  bundled plugin version differs from the installed plugin version;
- **info** when a plugin has no `mobile.package`, because open-on-web
  fallback is expected.

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
- `@selfhelp/shared/plugin-sdk`
