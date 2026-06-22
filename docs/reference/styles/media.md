# Media styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 media styles (`@selfhelp/shared` `media` category).
Last verified: 2026-06-22.
Source of truth: `src/types/styles/media.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Media styles embed images, video, and audio. Read
[`_conventions.md`](./_conventions.md) first; common fields are not repeated
here. Media sources reference uploaded assets — see the assets/upload reference
for how files are stored and validated.

---

## image

**Purpose.** Mantine `Image` on web / native `Image` on mobile.

**Administrators.** Place a single image. Set the source, alternative text (`alt`, important for accessibility), and how it fits its box (`web_image_fit`). Use `is_fluid` to make it scale to the container width.

**Developers.** Renders `<Image src fit radius>`. `width`/`height` and `web_width`/`web_height` constrain the box; `web_image_fit` maps to `object-fit`. `fallback_src` maps to Mantine `Image.fallbackSrc` and is shown if the main source 404s/errors.

**Distinctive fields.** `img_src` (source), `alt` (alt text), `title`, `is_fluid` (responsive width), `height` / `width`, `web_image_fit` (contain/cover/fill/none/scale-down), `web_width` / `web_height`, `web_radius`, `fallback_src` (image shown when the main source fails to load).

**Children.** No.

---

## video

**Purpose.** HTML5 `<video>` on web / `expo-video` on mobile.

**Administrators.** Embed a video. Set `video_src` (the file), a `poster_src` still frame shown before playback, `alt` for accessibility, and `is_fluid` for responsive width. The playback toggles `has_controls`, `media_loop`, `media_autoplay`, `media_muted` map straight to the player (autoplay generally requires muted in browsers).

**Developers.** Renders `<video src={video_src} poster={poster_src} controls={has_controls} loop muted autoplay>`. Toggles are `'0' | '1'` strings; precedence is the literal field value → default. `video_src`/`poster_src` resolve through the asset URL resolver.

**Distinctive fields.** `video_src` (source), `poster_src` (poster image), `alt`, `is_fluid`, `has_controls`, `media_loop`, `media_autoplay`, `media_muted`.

**Children.** No.

---

## audio

**Purpose.** HTML5 `<audio>` on web / `expo-audio` on mobile.

**Administrators.** Embed an audio player. Set the source(s) and the playback toggles `has_controls`, `media_loop`, `media_autoplay`.

**Developers.** Renders an audio player from `sources`. Toggles are `'0' | '1'` strings mapped to `controls`/`loop`/`autoplay`.

**Distinctive fields.** `sources` (list of audio sources), `alt`, `has_controls`, `media_loop`, `media_autoplay`.

**Children.** No.

---

## figure

**Purpose.** A `<figure>` with a caption — wraps a media child and attaches a title/caption.

**Administrators.** Use to add a caption under an image or video. Either put a media section inside the figure, **or** fill in the optional built-in `img_src` / `alt` to render the image automatically without a child section. Fill in `caption_title` / `caption` for the caption text.

**Developers.** Renders `<figure>` with a `<figcaption>`. When `img_src` is set the renderer draws that image itself (with `alt`); otherwise the media is provided as a child section. The built-in image is a render-only convenience — it never auto-creates a child section.

**Distinctive fields.** `caption_title`, `caption` (both translatable), `img_src` (optional built-in image), `alt` (alt text for the built-in image).

**Children.** Yes (a media style) — optional when `img_src` is used instead.

---

## carousel

**Purpose.** A slideshow — Embla carousel on web, `reanimated-carousel` on mobile.

**Administrators.** Show a rotating set of slides/images. Toggle navigation controls and indicator dots, set slide size/gap, autoplay-style looping, and drag behaviour.

**Developers.** Renders a carousel over `sources` (or child slides). The many `web_carousel_*` fields map to Embla options; `web_carousel_embla_options` accepts raw Embla JSON for advanced tuning.

**Distinctive fields.** `sources` (slides), `has_controls`, `has_indicators`, `has_crossfade`, `web_loop`, `drag_free`, `skip_snaps`, `web_carousel_slide_size`, `web_carousel_slide_gap`, `web_carousel_align`, `web_carousel_contain_scroll`, `web_carousel_in_view_threshold`, `web_carousel_duration`, `web_control_size`, `web_carousel_controls_offset`, `web_carousel_next_control_icon` / `web_carousel_previous_control_icon`, `web_orientation`, `web_height`, `web_carousel_embla_options` (raw JSON).

**Children.** Yes.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
