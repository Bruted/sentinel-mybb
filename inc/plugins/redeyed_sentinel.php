<?php
/**
 * Redeyed Sentinel for MyBB 1.8
 * ------------------------------------------------------------------
 * Adds the Redeyed Sentinel CAPTCHA (self-hosted CAPTCHA + IP
 * reputation) to the MyBB registration form.
 *
 * Sentinel is free to install and stays INERT until you set both the
 * Site Key and the Secret Key in the plugin settings. With no Secret Key
 * the plugin "fails open" and never blocks registration.
 *
 * Settings (Admin CP -> Configuration -> Settings -> Redeyed Sentinel):
 *   - sentinel_site_key   : public site key (safe to expose in HTML)
 *   - sentinel_secret_key : per-site secret key (server-side only, never echoed)
 *   - sentinel_base_url   : Sentinel base URL (default https://redeyed.com)
 *   - sentinel_widget     : optional widget type   -> data-widget     (behavioral|checkbox|press_hold|image_pick|...)
 *   - sentinel_theme      : optional colour theme   -> data-theme      (auto|light|dark)
 *   - sentinel_scheme     : optional colour scheme  -> data-scheme
 *   - sentinel_difficulty : optional difficulty     -> data-difficulty (easy|medium|hard|max or 1-6; only RAISES strength)
 *
 * @package   Redeyed Sentinel
 * @author    Redeyed Corporation
 * @license   MIT (2026)
 * @link      https://redeyed.com
 * @version   1.0.2
 */

// Disallow direct access to this file for security reasons.
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

/* ------------------------------------------------------------------ *
 * Hook registration
 * ------------------------------------------------------------------ */

// member_register_end : fired after the registration template is built,
// so we can inject the Sentinel widget into the output.
$plugins->add_hook('member_register_end', 'redeyed_sentinel_render');

// member_do_register_start : fired when a registration is submitted,
// before the account is created. We verify the token here.
$plugins->add_hook('member_do_register_start', 'redeyed_sentinel_verify');

/* ------------------------------------------------------------------ *
 * Plugin metadata
 * ------------------------------------------------------------------ */

/**
 * Returns the plugin information array used by MyBB.
 *
 * @return array
 */
function redeyed_sentinel_info()
{
    return array(
        'name'          => 'Redeyed Sentinel',
        'description'   => 'Adds the Redeyed Sentinel CAPTCHA (self-hosted CAPTCHA + IP reputation) to the registration form. Free and inert until Site Key and Secret Key are configured.',
        'website'       => 'https://redeyed.com',
        'author'        => 'Redeyed Corporation',
        'authorsite'    => 'https://redeyed.com',
        'version'       => '1.0.2',
        'guid'          => '',
        'codename'      => 'redeyed_sentinel',
        'compatibility' => '18*',
    );
}

/* ------------------------------------------------------------------ *
 * Install / uninstall / activate / deactivate
 * ------------------------------------------------------------------ */

/**
 * Determines whether the plugin is installed (i.e. its setting group
 * exists in the database).
 *
 * @return bool
 */
function redeyed_sentinel_is_installed()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='redeyed_sentinel'");
    return (bool) $db->num_rows($query);
}

/**
 * Installs the plugin: creates the setting group and its settings.
 *
 * @return void
 */
function redeyed_sentinel_install()
{
    global $db;

    // Create the setting group shown in the Admin CP settings list.
    $group = array(
        'name'        => 'redeyed_sentinel',
        'title'       => $db->escape_string('Redeyed Sentinel'),
        'description' => $db->escape_string('Settings for the Redeyed Sentinel CAPTCHA. Leave keys empty to keep the plugin inert (registration is never blocked).'),
        'disporder'   => 99,
        'isdefault'   => 0,
    );
    $gid = (int) $db->insert_query('settinggroups', $group);

    // Settings belonging to the group above.
    $settings = array(
        array(
            'name'        => 'sentinel_site_key',
            'title'       => 'Sentinel Site Key',
            'description' => 'Public site key from Redeyed Lab &rarr; Sentinel &rarr; Sites. Safe to expose in HTML.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 1,
        ),
        array(
            'name'        => 'sentinel_secret_key',
            'title'       => 'Sentinel Secret Key',
            'description' => 'Per-site secret key from Redeyed Lab &rarr; Sentinel &rarr; Sites (shown once). Used server-side only to authenticate verification and never shown in the page.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 2,
        ),
        array(
            'name'        => 'sentinel_base_url',
            'title'       => 'Sentinel Base URL',
            'description' => 'Base URL of your Sentinel install. Defaults to https://redeyed.com.',
            'optionscode' => 'text',
            'value'       => 'https://redeyed.com',
            'disporder'   => 3,
        ),
        array(
            'name'        => 'sentinel_widget',
            'title'       => 'Sentinel Widget Type',
            'description' => 'Optional. Which CAPTCHA challenge the widget renders. Leave on "Auto" to let Sentinel choose adaptively.',
            'optionscode' => "select\n=Auto (site default)\nbehavioral=Behavioral\ncheckbox=Checkbox\npress_hold=Press &amp; Hold\nimage_pick=Image Pick",
            'value'       => '',
            'disporder'   => 4,
        ),
        array(
            'name'        => 'sentinel_theme',
            'title'       => 'Sentinel Theme',
            'description' => 'Optional. Widget colour theme. Leave on "Auto" to follow the visitor\'s system/browser preference.',
            'optionscode' => "select\n=Auto (site default)\nauto=Auto\nlight=Light\ndark=Dark",
            'value'       => '',
            'disporder'   => 5,
        ),
        array(
            'name'        => 'sentinel_scheme',
            'title'       => 'Sentinel Colour Scheme',
            'description' => 'Optional. Named colour scheme for the widget accent (e.g. a brand palette name). Leave empty to use the default.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 6,
        ),
        array(
            'name'        => 'sentinel_difficulty',
            'title'       => 'Sentinel Difficulty',
            'description' => 'Optional. Only RAISES challenge strength above the adaptive baseline &mdash; it never lowers it. Accepts easy|medium|hard|max or 1-6. Leave empty to use the adaptive baseline.',
            'optionscode' => "select\n=Adaptive baseline\neasy=Easy\nmedium=Medium\nhard=Hard\nmax=Max",
            'value'       => '',
            'disporder'   => 7,
        ),
    );

    foreach ($settings as $setting) {
        $setting['name']        = $db->escape_string($setting['name']);
        $setting['title']       = $db->escape_string($setting['title']);
        $setting['description'] = $db->escape_string($setting['description']);
        $setting['optionscode'] = $db->escape_string($setting['optionscode']);
        $setting['value']       = $db->escape_string($setting['value']);
        $setting['gid']         = $gid;

        $db->insert_query('settings', $setting);
    }

    // Rebuild settings.php so the new settings become available as $mybb->settings.
    rebuild_settings();
}

/**
 * Uninstalls the plugin: removes the setting group and its settings.
 *
 * @return void
 */
function redeyed_sentinel_uninstall()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='redeyed_sentinel'");
    $gid   = (int) $db->fetch_field($query, 'gid');

    if ($gid) {
        $db->delete_query('settings', "gid='{$gid}'");
        $db->delete_query('settinggroups', "gid='{$gid}'");
    }

    rebuild_settings();
}

/**
 * Activates the plugin. Edits the registration template to add a
 * placeholder where the widget is rendered (kept idempotent).
 *
 * @return void
 */
function redeyed_sentinel_activate()
{
    // No template edits are required because we inject directly into the
    // member_register output. Nothing to do here, but the function must
    // exist for MyBB's activation flow.
}

/**
 * Deactivates the plugin.
 *
 * @return void
 */
function redeyed_sentinel_deactivate()
{
    // Nothing persistent to undo on deactivation.
}

/* ------------------------------------------------------------------ *
 * Rendering
 * ------------------------------------------------------------------ */

/**
 * Injects the Sentinel widget markup into the registration page output.
 *
 * Hooked to member_register_end. At that point $registration holds the
 * fully parsed registration page. We insert our markup just before the
 * submit button / end of the form.
 *
 * @return void
 */
function redeyed_sentinel_render()
{
    global $mybb, $registration;

    $site_key = isset($mybb->settings['sentinel_site_key']) ? trim($mybb->settings['sentinel_site_key']) : '';
    $base_url = isset($mybb->settings['sentinel_base_url']) ? trim($mybb->settings['sentinel_base_url']) : '';

    // Optional widget customisation. Each renders as a data-* attribute only
    // when non-empty, so omitting them keeps the default adaptive behaviour.
    $widget_type = isset($mybb->settings['sentinel_widget'])     ? trim($mybb->settings['sentinel_widget'])     : '';
    $theme       = isset($mybb->settings['sentinel_theme'])      ? trim($mybb->settings['sentinel_theme'])      : '';
    $scheme      = isset($mybb->settings['sentinel_scheme'])     ? trim($mybb->settings['sentinel_scheme'])     : '';
    $difficulty  = isset($mybb->settings['sentinel_difficulty']) ? trim($mybb->settings['sentinel_difficulty']) : '';

    // Inert when no keys are configured: do not render anything.
    if ($site_key === '') {
        return;
    }

    $base_url = redeyed_sentinel_base_url($base_url);

    // Build the optional data-* attributes. Each is emitted only when set and
    // escaped exactly like the site key to stay inside the attribute context.
    $attrs = '';
    if ($widget_type !== '') {
        $attrs .= ' data-widget="' . htmlspecialchars_uni($widget_type) . '"';
    }
    if ($theme !== '') {
        $attrs .= ' data-theme="' . htmlspecialchars_uni($theme) . '"';
    }
    if ($scheme !== '') {
        $attrs .= ' data-scheme="' . htmlspecialchars_uni($scheme) . '"';
    }
    if ($difficulty !== '') {
        $attrs .= ' data-difficulty="' . htmlspecialchars_uni($difficulty) . '"';
    }

    // Build the widget HTML. The site key is public but still escaped to
    // avoid breaking out of the attribute context.
    $script  = '<script src="' . htmlspecialchars_uni($base_url) . '/sentinel.js" async></script>';
    $widget  = '<div class="sentinel-captcha" data-sitekey="' . htmlspecialchars_uni($site_key) . '"' . $attrs . '></div>';

    $markup =
        "\n<!-- Redeyed Sentinel -->\n" .
        '<div class="redeyed-sentinel" style="margin:10px 0;">' .
        $script . $widget .
        "</div>\n<!-- /Redeyed Sentinel -->\n";

    // Try to place the widget right before the form's submit row. We look
    // for common markers in the parsed page and fall back to appending.
    if (is_string($registration) && $registration !== '') {
        if (strpos($registration, 'name="regsubmit"') !== false) {
            // Insert before the table row / container that holds the submit
            // button. Use the input as an anchor and rewind to a safe point.
            $pos = strpos($registration, 'name="regsubmit"');
            // Find the start of the enclosing block before the submit input.
            $anchor = strrpos(substr($registration, 0, $pos), '<');
            if ($anchor !== false) {
                $registration = substr($registration, 0, $anchor) . $markup . substr($registration, $anchor);
                return;
            }
        }

        if (strpos($registration, '</form>') !== false) {
            $registration = str_replace('</form>', $markup . '</form>', $registration);
            return;
        }

        // Last resort: append to the end of the page.
        $registration .= $markup;
    }
}

/* ------------------------------------------------------------------ *
 * Verification
 * ------------------------------------------------------------------ */

/**
 * Verifies the submitted Sentinel token. On failure, registers an error
 * so MyBB halts the registration.
 *
 * Hooked to member_do_register_start.
 *
 * @return void
 */
function redeyed_sentinel_verify()
{
    global $mybb, $errors, $lang;

    $site_key   = isset($mybb->settings['sentinel_site_key'])   ? trim($mybb->settings['sentinel_site_key'])   : '';
    $secret_key = isset($mybb->settings['sentinel_secret_key']) ? trim($mybb->settings['sentinel_secret_key']) : '';
    $base_url   = isset($mybb->settings['sentinel_base_url'])   ? trim($mybb->settings['sentinel_base_url'])   : '';

    // Fail open: if the Secret Key is missing, Sentinel is inert.
    if ($secret_key === '') {
        return;
    }

    $base_url  = redeyed_sentinel_base_url($base_url);
    $token     = (string) $mybb->get_input('sentinel-token');
    $remote_ip = function_exists('get_ip') ? (string) get_ip() : '';

    $passed = redeyed_sentinel_check($base_url, $secret_key, $token, $remote_ip);

    if (!$passed) {
        redeyed_sentinel_fail();
    }
}

/**
 * Stops registration with an appropriate error message. Uses the $errors
 * array when available (so it merges with MyBB's standard validation),
 * and otherwise calls error() to halt outright.
 *
 * @return void
 */
function redeyed_sentinel_fail()
{
    global $errors;

    $message = 'Sentinel verification failed. Please complete the verification challenge and try again.';

    if (isset($errors) && is_array($errors)) {
        $errors[] = $message;
        return;
    }

    // Fallback if the errors array is not in scope for some reason.
    error($message);
}

/**
 * Performs the server-side verification request to Sentinel.
 *
 * Uses the reCAPTCHA/Turnstile-style siteverify flow: the per-site Secret
 * Key authenticates the call in the request body (no developer API key,
 * no X-Api-Key header). Returns true only when the response decodes to
 * success === true. Any transport/decoding problem returns false so the
 * caller blocks registration (the Secret Key is present => Sentinel active).
 *
 * @param string $base_url   Normalised base URL.
 * @param string $secret_key Per-site secret key (sent in the request body).
 * @param string $token      Token submitted by the widget.
 * @param string $remote_ip  Optional client IP address.
 *
 * @return bool
 */
function redeyed_sentinel_check($base_url, $secret_key, $token, $remote_ip = '')
{
    // No token at all -> not passed.
    if ($token === '') {
        return false;
    }

    if (!function_exists('curl_init')) {
        // Without cURL we cannot verify. Block to be safe (Secret Key is set).
        return false;
    }

    $endpoint = $base_url . '/sentinel/siteverify';

    // Build the JSON body. The Secret Key authenticates the call and the
    // token is the widget response; the client IP is optional.
    $body = array(
        'secret'   => $secret_key,
        'response' => $token,
    );
    if ($remote_ip !== '') {
        $body['remoteip'] = $remote_ip;
    }
    $payload = json_encode($body);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Accept: application/json',
    ));

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $response === false || $status < 200 || $status >= 300) {
        return false;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return false;
    }

    // Response shape: {"success": true|false, "outcome": "...", "score": N}.
    // PASSED only when success === true.
    return isset($decoded['success']) && $decoded['success'] === true;
}

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

/**
 * Normalises the configured base URL, applying the default and trimming
 * any trailing slash so path concatenation is predictable.
 *
 * @param string $base_url Raw configured value.
 *
 * @return string
 */
function redeyed_sentinel_base_url($base_url)
{
    $base_url = trim((string) $base_url);
    if ($base_url === '') {
        $base_url = 'https://redeyed.com';
    }
    return rtrim($base_url, '/');
}
