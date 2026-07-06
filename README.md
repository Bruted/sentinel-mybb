# Redeyed Sentinel for MyBB 1.8

Adds the **Redeyed Sentinel** CAPTCHA (self-hosted CAPTCHA + IP reputation)
to your MyBB registration form. The plugin is free to install and stays
**inert until you configure your keys** — with no Secret Key it never blocks
registration ("fail open").

- **Compatibility:** MyBB 1.8.x (`18*`)
- **Author:** Redeyed Corporation — <https://redeyed.com>
- **License:** MIT (2026)

## Files

```
inc/plugins/redeyed_sentinel.php   The plugin
inc/plugins/redeyed/               Reserved for optional helpers (unused)
README.md
LICENSE
```

## Installation

1. Upload `inc/plugins/redeyed_sentinel.php` to your forum's
   `inc/plugins/` directory (keep the path exactly as shown above).
2. Log in to the **Admin CP → Configuration → Plugins**.
3. Find **Redeyed Sentinel** and click **Install & Activate**.

## Configuration

Go to **Admin CP → Configuration → Settings → Redeyed Sentinel** and set:

| Setting           | Where to get it                                              |
|-------------------|-------------------------------------------------------------|
| **Site Key**      | Redeyed Lab → **Sentinel → Sites** (public key)             |
| **Secret Key**    | Redeyed Lab → **Sentinel → Sites** (secret key, shown once)  |
| **Base URL**      | Defaults to `https://redeyed.com`; change only if self-hosting elsewhere |

Both keys come from the same **Sentinel → Sites** screen in the Redeyed Lab.
The **Site Key** is public and appears in the registration page HTML.
The **Secret Key** is secret: it is only ever sent server-side in the
verification request body and is never printed to the page. It is shown
only once in the Lab, so copy it when you create the site.

> While the Secret Key is empty, Sentinel is **inert**: no widget is shown
> and registration is never blocked.

### Optional widget customization

These four settings are **optional** and **backward-compatible**: leave them
on their default (empty / "Auto") and the widget behaves exactly as before.
When set, each is rendered as a `data-*` attribute on the
`<div class="sentinel-captcha">` element (escaped with
`htmlspecialchars_uni()`, the same as the Site Key) and read by
`sentinel.js`.

| Setting             | Attribute         | Values                                                | Effect                                                                 |
|---------------------|-------------------|-------------------------------------------------------|------------------------------------------------------------------------|
| **Widget Type**     | `data-widget`     | `behavioral`, `checkbox`, `press_hold`, `image_pick`, … | Which challenge the widget renders. Empty = adaptive site default.     |
| **Theme**           | `data-theme`      | `auto`, `light`, `dark`                                | Widget colour theme. Empty = follow the visitor's system preference.   |
| **Colour Scheme**   | `data-scheme`     | a named colour scheme (free text)                      | Accent colour scheme. Empty = default.                                 |
| **Difficulty**      | `data-difficulty` | `easy`, `medium`, `hard`, `max` or `1`–`6`             | Only **raises** challenge strength above the adaptive baseline.        |
| **Widget Width**    | `data-width`      | `full`, `100%`, `340px`, … (free text)                | Width of the widget container. Empty = default width.                  |

> **Difficulty only raises the bar.** Sentinel already picks a challenge
> strength adaptively per request. Setting **Difficulty** can push it
> *higher* than that baseline, but it never *lowers* it below what the
> adaptive engine would otherwise choose. Leave it empty to use the
> adaptive baseline unchanged.

## How it works

- **Render** (`member_register_end`): injects
  `<script src="{base_url}/sentinel.js" async></script>` and
  `<div class="sentinel-captcha" data-sitekey="{site_key}"></div>` into the
  registration form (plus any optional `data-widget` / `data-theme` /
  `data-scheme` / `data-difficulty` attributes you configured). The widget
  adds a hidden `sentinel-token` input.
- **Verify** (`member_do_register_start`): reads the posted
  `sentinel-token`, then POSTs (via cURL) to `{base_url}/sentinel/siteverify`
  with JSON body `{"secret":"…","response":"…","remoteip":"…"}` (the
  `remoteip` field is optional). This is the reCAPTCHA/Turnstile-style
  flow: the per-site **Secret Key** authenticates the call — no developer
  API key and no `X-Api-Key` header. The response has the shape
  `{"success": true|false, "outcome": "…", "score": N}`. Registration
  proceeds only when `success === true`; otherwise an error is added and
  registration is stopped.

## Security notes

- The Secret Key is transmitted only in the server-side verification
  request body and is never echoed in HTML or error messages.
- All output is escaped with MyBB's `htmlspecialchars_uni()`.
- TLS peer/host verification is enabled on the verify request.
- If cURL is unavailable while the Secret Key is set, the plugin fails
  **closed** (blocks registration) so verification is never silently skipped.

## Uninstalling

Admin CP → Configuration → Plugins → **Redeyed Sentinel** →
**Deactivate**, then **Uninstall**. Uninstalling removes the setting
group and all Sentinel settings.

## Changelog

### 1.0.3

- Added an optional **Widget Width** setting (`sentinel_width`) that renders as
  `data-width` on the CAPTCHA `<div>` only when non-empty (escaped with
  `htmlspecialchars_uni()`). Accepts free text such as `full`, `100%` or
  `340px`. Existing installs are back-filled with the new setting on
  re-activation.

### 1.0.2

- Added four **optional**, backward-compatible widget-customization settings —
  **Widget Type** (`data-widget`), **Theme** (`data-theme`), **Colour Scheme**
  (`data-scheme`) and **Difficulty** (`data-difficulty`). Each renders as a
  `data-*` attribute on the CAPTCHA `<div>` only when non-empty, escaped with
  `htmlspecialchars_uni()`. **Difficulty** only raises challenge strength above
  the adaptive baseline; it never lowers it.

### 1.0.1

- Switched server-side verification to the reCAPTCHA/Turnstile-style
  siteverify flow: POST `{base_url}/sentinel/siteverify` with body
  `{"secret":"…","response":"…","remoteip":"…"}` (no developer API key,
  no `X-Api-Key` header). Renamed the **API Key** setting to **Secret Key**
  (`sentinel_secret_key`) and based the "configured?" check on the Secret
  Key being present.

### 1.0.0

- Initial release.
