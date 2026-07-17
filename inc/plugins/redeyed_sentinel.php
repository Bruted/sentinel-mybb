<?php
/**
 * Redeyed Sentinel for MyBB 1.8
 * ------------------------------------------------------------------
 * Adds the Redeyed Sentinel CAPTCHA (self-hosted CAPTCHA + IP
 * reputation) to MyBB's high-risk forms: Registration, Login, Lost
 * Password and Contact. Each form is independently toggleable; only
 * Registration is on by default, so upgrades change nothing until you
 * opt the other forms in.
 *
 * Sentinel is free to install and stays INERT until you set both the
 * Site Key and the Secret Key in the plugin settings. With no Secret Key
 * the plugin "fails open" and never blocks a submission.
 *
 * Blocked attempts can be recorded to a log (Admin CP -> Configuration
 * -> Sentinel Block Log) so you can see it working and spot attacks.
 *
 * Settings (Admin CP -> Configuration -> Settings -> Redeyed Sentinel):
 *   - sentinel_site_key      : public site key (safe to expose in HTML)
 *   - sentinel_secret_key    : per-site secret key (server-side only)
 *   - sentinel_base_url      : Sentinel base URL (default https://redeyed.com)
 *   - sentinel_protect_*     : per-form on/off (register|login|lostpw|contact)
 *   - sentinel_log_blocks    : record blocked attempts to the log
 *   - sentinel_widget        : optional widget type   -> data-widget
 *   - sentinel_theme         : optional colour theme   -> data-theme
 *   - sentinel_scheme        : optional colour scheme  -> data-scheme
 *   - sentinel_difficulty    : optional difficulty     -> data-difficulty
 *   - sentinel_width         : optional widget width   -> data-width
 *
 * @package   Redeyed Sentinel
 * @author    Redeyed Corporation
 * @license   MIT (2026)
 * @link      https://redeyed.com
 * @version   1.0.4
 */

// Disallow direct access to this file for security reasons.
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

define('REDEYED_SENTINEL_VERSION', '1.0.4');

/* ------------------------------------------------------------------ *
 * Hook registration
 *
 * Front-end hooks fire on member.php / contact.php; admin hooks fire in
 * the ACP. Registering both is safe — each only runs in its own context.
 * ------------------------------------------------------------------ */

// Registration.
$plugins->add_hook('member_register_end', 'redeyed_sentinel_render_register');
$plugins->add_hook('member_do_register_start', 'redeyed_sentinel_verify_register');

// Login.
$plugins->add_hook('member_login_end', 'redeyed_sentinel_render_login');
$plugins->add_hook('member_do_login_start', 'redeyed_sentinel_verify_login');

// Lost password.
$plugins->add_hook('member_lostpw_end', 'redeyed_sentinel_render_lostpw');
$plugins->add_hook('member_do_lostpw_start', 'redeyed_sentinel_verify_lostpw');

// Contact form (contact.php builds + sends in one pass; we verify on POST
// at contact_start and render into the built page at contact_end).
$plugins->add_hook('contact_start', 'redeyed_sentinel_verify_contact');
$plugins->add_hook('contact_end', 'redeyed_sentinel_render_contact');

// Admin CP — block log viewer under Configuration.
$plugins->add_hook('admin_config_menu', 'redeyed_sentinel_admin_menu');
$plugins->add_hook('admin_config_action_handler', 'redeyed_sentinel_admin_action_handler');
$plugins->add_hook('admin_config_permissions', 'redeyed_sentinel_admin_permissions');
$plugins->add_hook('admin_load', 'redeyed_sentinel_admin_load');

/* ------------------------------------------------------------------ *
 * Plugin metadata
 * ------------------------------------------------------------------ */

function redeyed_sentinel_info()
{
    return array(
        'name'          => 'Redeyed Sentinel',
        'description'   => 'Self-hosted CAPTCHA + IP reputation for the forms bots love most — Registration, Login, Lost Password and Contact. Each form is toggleable; free and inert until Site Key and Secret Key are configured.',
        'website'       => 'https://redeyed.com',
        'author'        => 'Redeyed Corporation',
        'authorsite'    => 'https://redeyed.com',
        'version'       => REDEYED_SENTINEL_VERSION,
        'guid'          => '',
        'codename'      => 'redeyed_sentinel',
        'compatibility' => '18*',
    );
}

/* ------------------------------------------------------------------ *
 * Settings definitions (shared by install + activate back-fill)
 * ------------------------------------------------------------------ */

/**
 * Canonical list of every setting the plugin ships, in display order.
 * install() creates them all; activate() inserts any that are missing so
 * upgrades from an older version pick up new settings without duplicates.
 *
 * @return array<int, array<string, string|int>>
 */
function redeyed_sentinel_settings_defs()
{
    return array(
        array(
            'name' => 'sentinel_site_key', 'title' => 'Sentinel Site Key',
            'description' => 'Public site key from Redeyed Lab &rarr; Sentinel &rarr; Sites. Safe to expose in HTML.',
            'optionscode' => 'text', 'value' => '', 'disporder' => 1,
        ),
        array(
            'name' => 'sentinel_secret_key', 'title' => 'Sentinel Secret Key',
            'description' => 'Per-site secret key from Redeyed Lab &rarr; Sentinel &rarr; Sites (shown once). Used server-side only to authenticate verification and never shown in the page.',
            'optionscode' => 'text', 'value' => '', 'disporder' => 2,
        ),
        array(
            'name' => 'sentinel_base_url', 'title' => 'Sentinel Base URL',
            'description' => 'Base URL of your Sentinel install. Defaults to https://redeyed.com.',
            'optionscode' => 'text', 'value' => 'https://redeyed.com', 'disporder' => 3,
        ),
        // --- Per-form protection toggles ---
        array(
            'name' => 'sentinel_protect_register', 'title' => 'Protect: Registration',
            'description' => 'Show the CAPTCHA on the registration form and verify it. On by default.',
            'optionscode' => 'yesno', 'value' => '1', 'disporder' => 4,
        ),
        array(
            'name' => 'sentinel_protect_login', 'title' => 'Protect: Login',
            'description' => 'Show the CAPTCHA on the login form and verify it (guards against brute force). Off by default.',
            'optionscode' => 'yesno', 'value' => '0', 'disporder' => 5,
        ),
        array(
            'name' => 'sentinel_protect_lostpw', 'title' => 'Protect: Lost Password',
            'description' => 'Show the CAPTCHA on the lost-password form and verify it. Off by default.',
            'optionscode' => 'yesno', 'value' => '0', 'disporder' => 6,
        ),
        array(
            'name' => 'sentinel_protect_contact', 'title' => 'Protect: Contact',
            'description' => 'Show the CAPTCHA on the contact form and verify it. Off by default.',
            'optionscode' => 'yesno', 'value' => '0', 'disporder' => 7,
        ),
        array(
            'name' => 'sentinel_log_blocks', 'title' => 'Log blocked attempts',
            'description' => 'Record each blocked submission (form, IP, outcome, score) to the Sentinel Block Log for admin review. On by default.',
            'optionscode' => 'yesno', 'value' => '1', 'disporder' => 8,
        ),
        // --- Optional widget customisation ---
        array(
            'name' => 'sentinel_widget', 'title' => 'Sentinel Widget Type',
            'description' => 'Optional. Which CAPTCHA challenge the widget renders. Leave on "Auto" to let Sentinel choose adaptively.',
            'optionscode' => "select\n=Auto (site default)\nbehavioral=Behavioral\ncheckbox=Checkbox\npress_hold=Press &amp; Hold\nimage_pick=Image Pick",
            'value' => '', 'disporder' => 9,
        ),
        array(
            'name' => 'sentinel_theme', 'title' => 'Sentinel Theme',
            'description' => 'Optional. Widget colour theme. Leave on "Auto" to follow the visitor\'s system/browser preference.',
            'optionscode' => "select\n=Auto (site default)\nauto=Auto\nlight=Light\ndark=Dark",
            'value' => '', 'disporder' => 10,
        ),
        array(
            'name' => 'sentinel_scheme', 'title' => 'Sentinel Colour Scheme',
            'description' => 'Optional. Named colour scheme for the widget accent (e.g. a brand palette name). Leave empty to use the default.',
            'optionscode' => 'text', 'value' => '', 'disporder' => 11,
        ),
        array(
            'name' => 'sentinel_difficulty', 'title' => 'Sentinel Difficulty',
            'description' => 'Optional. Only RAISES challenge strength above the adaptive baseline &mdash; it never lowers it. Accepts easy|medium|hard|max or 1-6. Leave empty to use the adaptive baseline.',
            'optionscode' => "select\n=Adaptive baseline\neasy=Easy\nmedium=Medium\nhard=Hard\nmax=Max",
            'value' => '', 'disporder' => 12,
        ),
        array(
            'name' => 'sentinel_width', 'title' => 'Sentinel Widget Width',
            'description' => 'Optional. Width for the widget container, e.g. full, 100% or 340px. Leave empty to use the default width.',
            'optionscode' => 'text', 'value' => '', 'disporder' => 13,
        ),
    );
}

/* ------------------------------------------------------------------ *
 * Install / uninstall / activate / deactivate
 * ------------------------------------------------------------------ */

function redeyed_sentinel_is_installed()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='redeyed_sentinel'");
    return (bool) $db->num_rows($query);
}

function redeyed_sentinel_install()
{
    global $db;

    $group = array(
        'name'        => 'redeyed_sentinel',
        'title'       => $db->escape_string('Redeyed Sentinel'),
        'description' => $db->escape_string('Settings for the Redeyed Sentinel CAPTCHA. Leave keys empty to keep the plugin inert (submissions are never blocked).'),
        'disporder'   => 99,
        'isdefault'   => 0,
    );
    $gid = (int) $db->insert_query('settinggroups', $group);

    foreach (redeyed_sentinel_settings_defs() as $setting) {
        $setting['gid']         = $gid;
        $setting['name']        = $db->escape_string($setting['name']);
        $setting['title']       = $db->escape_string($setting['title']);
        $setting['description'] = $db->escape_string($setting['description']);
        $setting['optionscode'] = $db->escape_string($setting['optionscode']);
        $setting['value']       = $db->escape_string((string) $setting['value']);

        $db->insert_query('settings', $setting);
    }

    redeyed_sentinel_create_log_table();

    rebuild_settings();
}

function redeyed_sentinel_uninstall()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='redeyed_sentinel'");
    $gid   = (int) $db->fetch_field($query, 'gid');

    if ($gid) {
        $db->delete_query('settings', "gid='{$gid}'");
        $db->delete_query('settinggroups', "gid='{$gid}'");
    }

    if ($db->table_exists('redeyed_sentinel_log')) {
        $db->drop_table('redeyed_sentinel_log');
    }

    rebuild_settings();
}

/**
 * Activate: back-fill any settings added in a newer version (idempotent)
 * and ensure the log table exists, so upgrades are seamless.
 */
function redeyed_sentinel_activate()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='redeyed_sentinel'");
    $gid   = (int) $db->fetch_field($query, 'gid');
    if (!$gid) {
        return;
    }

    $changed = false;
    foreach (redeyed_sentinel_settings_defs() as $setting) {
        $exists = $db->simple_select(
            'settings',
            'sid',
            "gid='{$gid}' AND name='" . $db->escape_string($setting['name']) . "'"
        );
        if ($db->num_rows($exists)) {
            continue;
        }

        $setting['gid']         = $gid;
        $setting['name']        = $db->escape_string($setting['name']);
        $setting['title']       = $db->escape_string($setting['title']);
        $setting['description'] = $db->escape_string($setting['description']);
        $setting['optionscode'] = $db->escape_string($setting['optionscode']);
        $setting['value']       = $db->escape_string((string) $setting['value']);

        $db->insert_query('settings', $setting);
        $changed = true;
    }

    redeyed_sentinel_create_log_table();

    if ($changed) {
        rebuild_settings();
    }
}

function redeyed_sentinel_deactivate()
{
    // Nothing persistent to undo on deactivation (settings + log are kept
    // until a full uninstall).
}

/**
 * Create the block-log table if it does not already exist. Kept simple and
 * MySQL-oriented (the overwhelming majority of MyBB installs).
 */
function redeyed_sentinel_create_log_table()
{
    global $db;

    if ($db->table_exists('redeyed_sentinel_log')) {
        return;
    }

    $collation = $db->build_create_table_collation();

    $db->write_query("CREATE TABLE " . TABLE_PREFIX . "redeyed_sentinel_log (
        lid INT UNSIGNED NOT NULL AUTO_INCREMENT,
        form VARCHAR(20) NOT NULL DEFAULT '',
        ipaddress VARCHAR(45) NOT NULL DEFAULT '',
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        username VARCHAR(120) NOT NULL DEFAULT '',
        outcome VARCHAR(40) NOT NULL DEFAULT '',
        score FLOAT NULL,
        dateline INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (lid),
        KEY dateline (dateline),
        KEY form (form)
    ) ENGINE=InnoDB{$collation};");
}

/* ------------------------------------------------------------------ *
 * Rendering (front-end)
 * ------------------------------------------------------------------ */

function redeyed_sentinel_render_register()
{
    global $mybb, $registration;
    if (empty($mybb->settings['sentinel_protect_register'])) {
        return;
    }
    redeyed_sentinel_inject($registration);
}

function redeyed_sentinel_render_login()
{
    global $mybb, $login;
    if (empty($mybb->settings['sentinel_protect_login'])) {
        return;
    }
    redeyed_sentinel_inject($login);
}

function redeyed_sentinel_render_lostpw()
{
    global $mybb, $lostpw;
    if (empty($mybb->settings['sentinel_protect_lostpw'])) {
        return;
    }
    redeyed_sentinel_inject($lostpw);
}

function redeyed_sentinel_render_contact()
{
    global $mybb, $contact;
    if (empty($mybb->settings['sentinel_protect_contact'])) {
        return;
    }
    redeyed_sentinel_inject($contact);
}

/**
 * Insert the Sentinel widget into a built page, just before the form's
 * closing tag (falls back to appending). No-op when the plugin is inert.
 *
 * @param string $page Passed by reference — the built template output.
 */
function redeyed_sentinel_inject(&$page)
{
    if (!is_string($page) || $page === '') {
        return;
    }

    $markup = redeyed_sentinel_widget_markup();
    if ($markup === '') {
        return; // inert (no Site Key)
    }

    // Prefer inserting right before the submit control; otherwise before the
    // closing </form>; otherwise append.
    if (strpos($page, 'name="regsubmit"') !== false) {
        $pos = strpos($page, 'name="regsubmit"');
        $anchor = strrpos(substr($page, 0, $pos), '<');
        if ($anchor !== false) {
            $page = substr($page, 0, $anchor) . $markup . substr($page, $anchor);
            return;
        }
    }

    $close = strripos($page, '</form>');
    if ($close !== false) {
        $page = substr($page, 0, $close) . $markup . substr($page, $close);
        return;
    }

    $page .= $markup;
}

/**
 * Build the widget markup (script + div) with the optional data-* attributes.
 * Returns '' when no Site Key is configured (inert).
 *
 * @return string
 */
function redeyed_sentinel_widget_markup()
{
    global $mybb;

    $site_key = isset($mybb->settings['sentinel_site_key']) ? trim($mybb->settings['sentinel_site_key']) : '';
    if ($site_key === '') {
        return '';
    }

    $base_url = redeyed_sentinel_base_url(isset($mybb->settings['sentinel_base_url']) ? $mybb->settings['sentinel_base_url'] : '');

    $map = array(
        'data-widget'     => 'sentinel_widget',
        'data-theme'      => 'sentinel_theme',
        'data-scheme'     => 'sentinel_scheme',
        'data-difficulty' => 'sentinel_difficulty',
        'data-width'      => 'sentinel_width',
    );

    $attrs = '';
    foreach ($map as $attr => $key) {
        $val = isset($mybb->settings[$key]) ? trim($mybb->settings[$key]) : '';
        if ($val !== '') {
            $attrs .= ' ' . $attr . '="' . htmlspecialchars_uni($val) . '"';
        }
    }

    $script = '<script src="' . htmlspecialchars_uni($base_url) . '/sentinel.js" async></script>';
    $widget = '<div class="sentinel-captcha" data-sitekey="' . htmlspecialchars_uni($site_key) . '"' . $attrs . '></div>';

    return "\n<!-- Redeyed Sentinel -->\n" .
        '<div class="redeyed-sentinel" style="margin:10px 0;">' . $script . $widget .
        "</div>\n<!-- /Redeyed Sentinel -->\n";
}

/* ------------------------------------------------------------------ *
 * Verification (front-end)
 * ------------------------------------------------------------------ */

function redeyed_sentinel_verify_register()
{
    global $mybb;
    if (empty($mybb->settings['sentinel_protect_register'])) {
        return;
    }
    redeyed_sentinel_guard('register', (string) $mybb->get_input('username'));
}

function redeyed_sentinel_verify_login()
{
    global $mybb;
    if (empty($mybb->settings['sentinel_protect_login'])) {
        return;
    }
    redeyed_sentinel_guard('login', (string) $mybb->get_input('username'));
}

function redeyed_sentinel_verify_lostpw()
{
    global $mybb;
    if (empty($mybb->settings['sentinel_protect_lostpw'])) {
        return;
    }
    redeyed_sentinel_guard('lostpw', (string) $mybb->get_input('email'));
}

function redeyed_sentinel_verify_contact()
{
    global $mybb;
    if (empty($mybb->settings['sentinel_protect_contact'])) {
        return;
    }
    // contact_start fires for both the form display and the send; only verify
    // an actual submission.
    if ($mybb->request_method !== 'post') {
        return;
    }
    redeyed_sentinel_guard('contact', (string) $mybb->get_input('email'));
}

/**
 * Verify the submitted token for a form. On failure, log (if enabled) and
 * halt. Fails open when no Secret Key is configured.
 *
 * @param string $form     One of register|login|lostpw|contact.
 * @param string $subject  Optional attempted identity (username/email) for the log.
 */
function redeyed_sentinel_guard($form, $subject = '')
{
    global $mybb;

    $secret_key = isset($mybb->settings['sentinel_secret_key']) ? trim($mybb->settings['sentinel_secret_key']) : '';
    if ($secret_key === '') {
        return; // inert / fail-open
    }

    $base_url  = redeyed_sentinel_base_url(isset($mybb->settings['sentinel_base_url']) ? $mybb->settings['sentinel_base_url'] : '');
    $token     = (string) $mybb->get_input('sentinel-token');
    $remote_ip = function_exists('get_ip') ? (string) get_ip() : '';

    $result = redeyed_sentinel_check($base_url, $secret_key, $token, $remote_ip);

    if ($result['success'] !== true) {
        if (!empty($mybb->settings['sentinel_log_blocks'])) {
            redeyed_sentinel_log_block($form, $remote_ip, $result['outcome'], $result['score'], $subject);
        }
        redeyed_sentinel_block();
    }
}

/**
 * Halt the current submission with an error. Uses the $errors array when one
 * is in scope (merges with MyBB's validation), otherwise error() outright.
 */
function redeyed_sentinel_block()
{
    global $errors, $lang;

    redeyed_sentinel_load_lang();
    $message = (isset($lang->redeyed_sentinel_failed) && $lang->redeyed_sentinel_failed !== '')
        ? $lang->redeyed_sentinel_failed
        : 'Sentinel verification failed. Please complete the verification challenge and try again.';

    if (isset($errors) && is_array($errors)) {
        $errors[] = $message;
        return;
    }

    error($message);
}

/**
 * Record a blocked attempt to the log table. Best-effort — never throws.
 *
 * @param string     $form
 * @param string     $ip
 * @param string     $outcome
 * @param float|null $score
 * @param string     $subject  Attempted username/email.
 */
function redeyed_sentinel_log_block($form, $ip, $outcome, $score, $subject = '')
{
    global $db, $mybb;

    if (!$db->table_exists('redeyed_sentinel_log')) {
        return;
    }

    $db->insert_query('redeyed_sentinel_log', array(
        'form'      => substr($form, 0, 20),
        'ipaddress' => substr($ip, 0, 45),
        'uid'       => (int) (isset($mybb->user['uid']) ? $mybb->user['uid'] : 0),
        'username'  => substr((string) $subject, 0, 120),
        'outcome'   => substr((string) $outcome, 0, 40),
        'score'     => ($score === null || $score === '') ? null : (float) $score,
        'dateline'  => (int) (defined('TIME_NOW') ? TIME_NOW : time()),
    ));
}

/**
 * Server-side verification against Sentinel's reCAPTCHA/Turnstile-style
 * siteverify endpoint. The per-site Secret Key authenticates the call in the
 * body (no developer API key). Returns success/outcome/score; any transport
 * or decoding problem returns success=false so the caller blocks.
 *
 * @return array{success: bool, outcome: string, score: float|null}
 */
function redeyed_sentinel_check($base_url, $secret_key, $token, $remote_ip = '')
{
    $fail = array('success' => false, 'outcome' => 'error', 'score' => null);

    if ($token === '' || !function_exists('curl_init')) {
        return $fail;
    }

    $body = array('secret' => $secret_key, 'response' => $token);
    if ($remote_ip !== '') {
        $body['remoteip'] = $remote_ip;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base_url . '/sentinel/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
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
        return $fail;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return $fail;
    }

    return array(
        'success' => (isset($decoded['success']) && $decoded['success'] === true),
        'outcome' => isset($decoded['outcome']) ? (string) $decoded['outcome'] : '',
        'score'   => isset($decoded['score']) ? (float) $decoded['score'] : null,
    );
}

/* ------------------------------------------------------------------ *
 * Admin CP — Block Log viewer (Configuration -> Sentinel Block Log)
 * ------------------------------------------------------------------ */

function redeyed_sentinel_admin_menu(&$sub_menu)
{
    $sub_menu[] = array(
        'id'    => 'redeyed_sentinel_log',
        'title' => 'Sentinel Block Log',
        'link'  => 'index.php?module=config-redeyed_sentinel_log',
    );
}

function redeyed_sentinel_admin_action_handler(&$actions)
{
    $actions['redeyed_sentinel_log'] = array('active' => 'redeyed_sentinel_log', 'file' => '');
}

function redeyed_sentinel_admin_permissions(&$admin_permissions)
{
    $admin_permissions['redeyed_sentinel_log'] = 'Can view the Redeyed Sentinel block log?';
}

/**
 * Render the ACP block-log page. Fires on every admin page via admin_load;
 * only acts on our own action.
 */
function redeyed_sentinel_admin_load()
{
    global $mybb, $db, $page, $lang;

    if ($page->active_action != 'redeyed_sentinel_log') {
        return;
    }

    $page->add_breadcrumb_item('Sentinel Block Log', 'index.php?module=config-redeyed_sentinel_log');

    // Clear the log (CSRF-protected).
    if ($mybb->get_input('action') == 'clear') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message('Invalid or missing security token. Please try again.', 'error');
            admin_redirect('index.php?module=config-redeyed_sentinel_log');
        }

        if ($db->table_exists('redeyed_sentinel_log')) {
            $db->delete_query('redeyed_sentinel_log');
        }
        flash_message('The Sentinel block log has been cleared.', 'success');
        admin_redirect('index.php?module=config-redeyed_sentinel_log');
    }

    $page->output_header('Redeyed Sentinel &mdash; Block Log');

    $sub_tabs['redeyed_sentinel_log'] = array(
        'title'       => 'Block Log',
        'link'        => 'index.php?module=config-redeyed_sentinel_log',
        'description' => 'Submissions blocked by Redeyed Sentinel — the form, source IP, outcome and score.',
    );
    $page->output_nav_tabs($sub_tabs, 'redeyed_sentinel_log');

    if (!$db->table_exists('redeyed_sentinel_log')) {
        $page->output_error('The log table does not exist yet. Deactivate and reactivate the plugin to create it.');
        $page->output_footer();
        return;
    }

    // Pagination.
    $per_page = 30;
    $current  = (int) $mybb->get_input('page', MyBB::INPUT_INT);
    if ($current < 1) {
        $current = 1;
    }
    $total = (int) $db->fetch_field($db->simple_select('redeyed_sentinel_log', 'COUNT(*) AS cnt'), 'cnt');
    $start = ($current - 1) * $per_page;

    $table = new Table;
    $table->construct_header('Time', array('width' => '18%'));
    $table->construct_header('Form', array('width' => '12%'));
    $table->construct_header('IP address', array('width' => '20%'));
    $table->construct_header('Identity', array('width' => '25%'));
    $table->construct_header('Outcome', array('width' => '15%'));
    $table->construct_header('Score', array('width' => '10%', 'class' => 'align_center'));

    $query = $db->simple_select('redeyed_sentinel_log', '*', '', array(
        'order_by' => 'dateline', 'order_dir' => 'desc',
        'limit_start' => $start, 'limit' => $per_page,
    ));

    while ($row = $db->fetch_array($query)) {
        $table->construct_cell(my_date('normal', (int) $row['dateline']) . ', ' . my_date('time', (int) $row['dateline']));
        $table->construct_cell(htmlspecialchars_uni($row['form']));
        $table->construct_cell(htmlspecialchars_uni($row['ipaddress']));
        $table->construct_cell($row['username'] !== '' ? htmlspecialchars_uni($row['username']) : '&mdash;');
        $table->construct_cell(htmlspecialchars_uni($row['outcome']));
        $table->construct_cell($row['score'] === null ? '&mdash;' : htmlspecialchars_uni((string) $row['score']), array('class' => 'align_center'));
        $table->construct_row();
    }

    if ($table->num_rows() == 0) {
        $table->construct_cell('No blocked attempts recorded yet.', array('colspan' => 6));
        $table->construct_row();
    }

    $table->output('Recent blocked attempts (' . my_number_format($total) . ' total)');

    echo draw_admin_pagination($current, $per_page, $total, 'index.php?module=config-redeyed_sentinel_log&amp;page={page}');

    if ($total > 0) {
        $clear_link = 'index.php?module=config-redeyed_sentinel_log&amp;action=clear&amp;my_post_key=' . $mybb->post_code;
        echo '<br /><div class="confirm_action"><p>Remove every entry from the block log.</p><br /><a href="' . $clear_link . '" class="button" onclick="return confirm(\'Clear the entire Sentinel block log?\');">Clear log</a></div>';
    }

    $page->output_footer();
}

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

/**
 * Load the plugin's front-end language file if present (best-effort).
 */
function redeyed_sentinel_load_lang()
{
    global $lang;

    if (isset($lang->redeyed_sentinel_failed)) {
        return;
    }
    if (is_object($lang) && method_exists($lang, 'load')) {
        // Suppress a missing-file notice on installs that didn't ship the lang file.
        @$lang->load('redeyed_sentinel');
    }
}

/**
 * Normalise the base URL: apply the default and trim any trailing slash.
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
