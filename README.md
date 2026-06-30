# Redeyed Sentinel for MyBB 1.8

Adds the **Redeyed Sentinel** CAPTCHA (self-hosted CAPTCHA + IP reputation)
to your MyBB registration form. The plugin is free to install and stays
**inert until you configure your keys** — with empty keys it never blocks
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
| **Site Key**      | Redeyed Lab → Developer → **Sentinel Sites** (public key)    |
| **API Key**       | Redeyed Lab → Developer → **API Keys** (secret key)          |
| **Base URL**      | Defaults to `https://redeyed.com`; change only if self-hosting elsewhere |

The **Site Key** is public and appears in the registration page HTML.
The **API Key** is secret: it is only ever sent server-side in the
`X-Api-Key` request header and is never printed to the page.

> While either key is empty, Sentinel is **inert**: no widget is shown and
> registration is never blocked.

## How it works

- **Render** (`member_register_end`): injects
  `<script src="{base_url}/sentinel.js" async></script>` and
  `<div class="sentinel-captcha" data-sitekey="{site_key}"></div>` into the
  registration form. The widget adds a hidden `sentinel-token` input.
- **Verify** (`member_do_register_start`): reads the posted
  `sentinel-token`, then POSTs (via cURL) to `{base_url}/api/v1/verify`
  with header `X-Api-Key: {api_key}` and JSON body
  `{"site_key":"…","token":"…"}`. Registration proceeds only when the
  response decodes to `data.success === true` (or `success === true`);
  otherwise an error is added and registration is stopped.

## Security notes

- The API key is transmitted only in the `X-Api-Key` header and is never
  echoed in HTML or error messages.
- All output is escaped with MyBB's `htmlspecialchars_uni()`.
- TLS peer/host verification is enabled on the verify request.
- If cURL is unavailable while keys are set, the plugin fails **closed**
  (blocks registration) so verification is never silently skipped.

## Uninstalling

Admin CP → Configuration → Plugins → **Redeyed Sentinel** →
**Deactivate**, then **Uninstall**. Uninstalling removes the setting
group and all Sentinel settings.
