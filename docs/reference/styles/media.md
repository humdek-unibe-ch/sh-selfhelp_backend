# Media styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 media styles (`@selfhelp/shared` `media` category).
Last verified: 2026-06-04.
Source of truth: `src/types/styles/media.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Media styles embed images, video, and audio. Read
[`_conventions.md`](./_conventions.md) first; common fields are not repeated
here. Media sources reference uploaded assets — see the assets/upload reference
for how files are stored and validated.

---

## image

**Purpose.** Mantine `Image` on web / native `Image` on mobile.

**Administrators.** Place a single image. Set the source, alternative text (`alt`, important for accessibility), and how it fits its box (`web_image_fit`). Use `is_fluid` to make it scale to the container width.

**Developers.** Renders `<Image src fit radius>`. `width`/`height` and `web_width`/`web_height` constrain the box; `web_image_fit` maps to `object-fit`.

**Distinctive fields.** `img_src` (source), `alt` (alt text), `title`, `is_fluid` (responsive width), `height` / `width`, `web_image_fit` (contain/cover/fill/none/scale-down), `web_width` / `web_height`, `web_radius`.

**Children.** No.

---

## video

**Purpose.** HTML5 `<video>` on web / `expo-video` on mobile.

**Administrators.** Embed a video with one or more sources (for format fallbacks). Set `is_fluid` for responsive width and `alt` for accessibility.

**Developers.** Renders a video player from the `sources` array (each entry is a `{ source, type }`-style descriptor).

**Distinctive fields.** `sources` (list of video sources), `is_fluid`, `alt`.

**Children.** No.

---

## audio

**Purpose.** HTML5 `<audio>` on web / `expo-audio` on mobile.

**Administrators.** Embed an audio player with one or more sources.

**Developers.** Renders an audio player from the `sources` array.

**Distinctive fields.** `sources` (list of audio sources).

**Children.** No.

---

## figure

**Purpose.** A `<figure>` with a caption — wraps a media child and attaches a title/caption.

**Administrators.** Use to add a caption under an image or video. Put the media section inside the figure and fill in `caption_title` / `caption`.

**Developers.** Renders `<figure>` with a `<figcaption>`; the media is provided as a child section.

**Distinctive fields.** `caption_title`, `caption` (both translatable).

**Children.** Yes (a media style).

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
