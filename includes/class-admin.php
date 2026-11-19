<?php
/**
 * The plugin's own screens: an overview a shop owner can read at a glance,
 * the settings form, and a dashboard widget. Nothing here is reachable by
 * MageSentinel; it only reads what MageSentinel_Monitor already collects.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Admin
{
    const SLUG = 'magesentinel-monitor';
    const DASHBOARD_URL = 'https://magesentinel.com/app';

    /** Short step label for the purchase funnel. Literal per label so translators see each one. */
    private static function flow(string $step): string
    {
        switch ($step) {
            case 'Store': return _x('Store', 'purchase step', 'magesentinel-monitor');
            case 'Search': return _x('Search', 'purchase step', 'magesentinel-monitor');
            case 'Product': return _x('Product', 'purchase step', 'magesentinel-monitor');
            case 'Cart': return _x('Cart', 'purchase step', 'magesentinel-monitor');
            case 'Checkout': return _x('Checkout', 'purchase step', 'magesentinel-monitor');
            case 'Customer': return _x('Customer', 'purchase step', 'magesentinel-monitor');
            case 'Shipping': return _x('Shipping', 'purchase step', 'magesentinel-monitor');
            case 'Payment': return _x('Payment', 'purchase step', 'magesentinel-monitor');
            case 'Order': return _x('Order', 'purchase step', 'magesentinel-monitor');
            case 'Confirmed': return _x('Confirmed', 'purchase step', 'magesentinel-monitor');
            case 'Cancelled': return _x('Cancelled', 'purchase step', 'magesentinel-monitor');
            case 'Sign in': return _x('Sign in', 'purchase step', 'magesentinel-monitor');
        }
        return $step;
    }

    /** "Check again": run the self-check now (records it, and emails if something turned red), then back to the overview. */
    public static function recheck(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('', '', 403);
        check_admin_referer('magesentinel_recheck');
        MageSentinel_Monitor_Self_Check::run();
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    const REVIEW_OPTION = 'magesentinel_monitor_review';
    const REVIEW_URL = 'https://wordpress.org/support/plugin/magesentinel-monitor/reviews/#new-post';

    /** Ask for a review only after two weeks with the plugin, only while the store is healthy, and never again once answered. */
    private static function review_due(array $v): bool
    {
        $r = get_option(self::REVIEW_OPTION);
        if (!is_array($r)) {
            update_option(self::REVIEW_OPTION, ['since' => time(), 'snooze' => 0, 'done' => false], false);
            return false;
        }
        if (!empty($r['done']) || (int) ($r['snooze'] ?? 0) > time()) return false;
        return (int) ($r['since'] ?? time()) <= time() - 14 * DAY_IN_SECONDS && (int) ($v['pct'] ?? 0) === 100;
    }

    /** The review notice's three answers: write it (to wordpress.org), later (30 days), no thanks (never). */
    public static function review_answer(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('', '', 403);
        check_admin_referer('magesentinel_review');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() above.
        $do = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        $r = get_option(self::REVIEW_OPTION);
        $r = is_array($r) ? $r : ['since' => time()];
        if ($do === 'later') $r['snooze'] = time() + 30 * DAY_IN_SECONDS;
        else $r['done'] = true;
        update_option(self::REVIEW_OPTION, $r, false);
        if ($do === 'write') {
            add_filter('allowed_redirect_hosts', static function ($hosts) { $hosts[] = 'wordpress.org'; return $hosts; });
            wp_safe_redirect(self::REVIEW_URL);
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function review_notice(array $v): void
    {
        if (!self::review_due($v)) return;
        $a = MageSentinel_Monitor::activity();
        $n = count($a['orders']);
        $url = static function (string $do): string { return wp_nonce_url(admin_url('admin-post.php?action=magesentinel_review&do=' . $do), 'magesentinel_review'); };
        ?>
        <section class="ms-card ms-review ms-appear" style="--d:1">
            <div class="ms-review-stars" aria-hidden="true">★★★★★</div>
            <div class="ms-review-text">
                <h2><?php echo esc_html($n > 0 ? sprintf(/* translators: %d: number of test purchases */ _n('MageSentinel has watched your checkout through %d test purchase.', 'MageSentinel has watched your checkout through %d test purchases.', $n, 'magesentinel-monitor'), $n) : __('MageSentinel has been watching your store for two weeks.', 'magesentinel-monitor')); ?></h2>
                <p><?php echo esc_html(__('If it is useful to you, a short review on WordPress.org helps other store owners find it. It takes a minute.', 'magesentinel-monitor')); ?></p>
            </div>
            <div class="ms-review-actions">
                <a class="ms-btn ms-btn-primary" href="<?php echo esc_url($url('write')); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Write a review', 'magesentinel-monitor')); ?></a>
                <a class="ms-btn ms-btn-ghost" href="<?php echo esc_url($url('later')); ?>"><?php echo esc_html(__('Later', 'magesentinel-monitor')); ?></a>
                <a class="ms-review-no" href="<?php echo esc_url($url('never')); ?>"><?php echo esc_html(__('No, thanks', 'magesentinel-monitor')); ?></a>
            </div>
        </section>
        <?php
    }

    /** The overview's alerts switch: flip the free email alerts, back to the overview. */
    /** Per-user colour choice for the plugin screens: auto follows the system. */
    private const THEME_META = 'magesentinel_monitor_theme';

    private static function theme(): string
    {
        $t = (string) get_user_meta(get_current_user_id(), self::THEME_META, true);
        return in_array($t, ['light', 'dark'], true) ? $t : 'auto';
    }

    public static function theme_answer(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('', '', 403);
        check_admin_referer('magesentinel_theme');
        $t = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : 'auto';
        if (in_array($t, ['light', 'dark'], true)) update_user_meta(get_current_user_id(), self::THEME_META, $t);
        else delete_user_meta(get_current_user_id(), self::THEME_META);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    /** The Auto / Light / Dark switch in the page header. */
    private static function theme_switch(): void
    {
        $now = self::theme();
        $choices = [
            'auto' => ['monitor', __('Match my system', 'magesentinel-monitor')],
            'light' => ['sun', __('Light', 'magesentinel-monitor')],
            'dark' => ['moon', __('Dark', 'magesentinel-monitor')],
        ];
        echo '<span class="ms-theme" role="group" aria-label="' . esc_attr(__('Colours', 'magesentinel-monitor')) . '">';
        foreach ($choices as $key => [$icon, $label]) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=magesentinel_theme&do=' . $key), 'magesentinel_theme');
            echo '<a class="ms-theme-opt' . ($now === $key ? ' on' : '') . '" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . ($now === $key ? ' aria-current="true"' : '') . '>' . wp_kses(self::icon($icon, 15), self::allowed_html()) . '</a>';
        }
        echo '</span>';
    }

    public static function toggle_alerts(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('', '', 403);
        check_admin_referer('magesentinel_alerts_toggle');
        $s = MageSentinel_Monitor::settings();
        $s['alerts'] = !$s['alerts'];
        // Straight to the stored option: the settings sanitizer expects the full form.
        remove_all_filters('sanitize_option_' . MageSentinel_Monitor::OPTION);
        update_option(MageSentinel_Monitor::OPTION, $s);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    /**
     * "Test cart & checkout": asks MageSentinel to run this store's checkout check
     * and relays its progress. Server to server, so the key (the hash of the
     * connector token, all this site keeps) never reaches the browser.
     */
    /** Start a checkout check, for the Test purchase button and the check after an update. */
    /** @param string $reason '' (a person pressed the button) or 'update' (the check after an update). */
    public static function request_check(string $reason = ''): array
    {
        return self::api('POST', '', $reason !== '' ? (string) wp_json_encode(['reason' => $reason]) : '');
    }

    private static function api(string $method, string $path, string $body = ''): array
    {
        $s = MageSentinel_Monitor::settings();
        $headers = ['X-MageSentinel-Key' => $s['token_hash'], 'X-MageSentinel-Site' => home_url('/'), 'Accept' => 'application/json'];
        $args = ['method' => $method, 'timeout' => 15, 'headers' => $headers];
        if ($body !== '') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $body;
        }
        $res = wp_remote_request('https://magesentinel.com/api/connector/checks' . $path, $args);
        if (is_wp_error($res)) return ['code' => 0, 'body' => null];
        return ['code' => (int) wp_remote_retrieve_response_code($res), 'body' => json_decode((string) wp_remote_retrieve_body($res), true)];
    }

    private static function ajax_guard(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => ''], 403);
        check_ajax_referer('magesentinel_test', 'nonce');
    }

    /** Not connected: the free monthly test runs as a MageSentinel audit, no account. */
    private static function free_start(): void
    {
        $challenge = wp_generate_password(40, false, false);
        set_transient(MageSentinel_Monitor::FREE_PROOF, $challenge, 2 * MINUTE_IN_SECONDS);
        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        $res = wp_remote_post('https://magesentinel.com/api/audits/plugin', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => (string) wp_json_encode([
                'site' => home_url('/'),
                'email' => MageSentinel_Monitor_Self_Check::recipient(),
                'locale' => strpos($locale, 'el') === 0 ? 'el' : 'en',
                'challenge' => $challenge,
            ]),
        ]);
        delete_transient(MageSentinel_Monitor::FREE_PROOF);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $body = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code === 202 && !empty($body['data']['id'])) {
            update_option(MageSentinel_Monitor::FREE_STATE, ['id' => (string) $body['data']['id'], 'at' => time(), 'next' => strtotime((string) ($body['data']['nextAt'] ?? '')) ?: 0], false);
            wp_send_json_success(['jobId' => 'audit:' . $body['data']['id']]);
        }
        if ($code === 429 && ($body['reason'] ?? '') === 'month') {
            $next = strtotime((string) ($body['nextAt'] ?? '')) ?: 0;
            if (!empty($body['id'])) update_option(MageSentinel_Monitor::FREE_STATE, ['id' => (string) $body['id'], 'at' => 0, 'next' => $next], false);
            wp_send_json_error(['message' => sprintf(/* translators: %s: date */ __('This month\'s free test has been used. The next one is on %s.', 'magesentinel-monitor'), date_i18n(get_option('date_format'), $next))]);
        }
        $msg = [
            403 => __('MageSentinel could not reach this site from the internet to confirm the request.', 'magesentinel-monitor'),
            429 => __('Too many tests right now. Try again later.', 'magesentinel-monitor'),
        ][$code] ?? __('Could not reach MageSentinel. Try again.', 'magesentinel-monitor');
        wp_send_json_error(['message' => $msg]);
    }

    /** An audit's progress, in the shape the funnel already draws for a connected store's check. */
    private static function free_status(string $id): void
    {
        $res = wp_remote_get('https://magesentinel.com/api/audits/' . rawurlencode($id), ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
        $d = is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200 ? null : (json_decode((string) wp_remote_retrieve_body($res), true)['data'] ?? null);
        if (!is_array($d)) wp_send_json_error(['message' => __('Could not reach MageSentinel. Try again.', 'magesentinel-monitor')]);
        $state = ['queued' => 'waiting', 'running' => 'active', 'done' => 'completed', 'error' => 'failed'][$d['status'] ?? ''] ?? 'waiting';
        $progress = null;
        $result = null;
        $map = ['passed' => 'passed', 'failed' => 'failed', 'skipped' => 'skipped'];
        if ($state === 'active' && is_array($d['progress'] ?? null)) {
            $p = $d['progress'];
            $names = array_values(array_map('strval', (array) ($p['names'] ?? [])));
            $done = (array) ($p['done'] ?? []);
            $statuses = [];
            $durations = [];
            foreach ($done as $step) { $statuses[] = $map[$step['status'] ?? ''] ?? 'passed'; $durations[] = (int) ($step['durationMs'] ?? 0); }
            $index = count($done);
            $current = (string) ($p['current']['name'] ?? ($names[$index] ?? ''));
            if ($current !== '') {
                $progress = ['step' => $current, 'index' => $index, 'total' => max(count($names), $index + 1), 'phase' => 'running', 'statuses' => $statuses, 'names' => $names, 'durations' => $durations];
            }
        }
        if ($state === 'completed' && is_array($d['result'] ?? null)) {
            $r = $d['result'];
            $steps = array_values(array_filter((array) ($r['steps'] ?? []), static function ($x) { return strpos((string) ($x['name'] ?? ''), 'order') !== 0; }));
            $names = array_map(static function ($x) { return (string) $x['name']; }, $steps);
            $statuses = array_map(static function ($x) use ($map) { return $map[$x['status'] ?? ''] ?? 'pending'; }, $steps);
            $durations = array_map(static function ($x) { return (int) ($x['durationMs'] ?? 0); }, $steps);
            if ($names) $progress = ['step' => end($names), 'index' => count($names) - 1, 'total' => count($names), 'phase' => 'done', 'statuses' => $statuses, 'names' => $names, 'durations' => $durations];
            $verdict = ['pass' => 'PASS', 'fail' => 'FAIL'][$r['verdict'] ?? ''] ?? 'UNKNOWN';
            if ($durations) MageSentinel_Monitor::record_free_speed($id, $verdict, (int) array_sum($durations), self::any_slow($names, $durations));
            $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
            $result = ['status' => $verdict, 'url' => 'https://magesentinel.com' . (strpos($locale, 'el') === 0 ? '/el' : '') . '/audit/' . rawurlencode($id)];
        }
        wp_send_json_success(['state' => $state, 'progress' => $progress, 'result' => $result]);
    }

    public static function test_start(): void
    {
        self::ajax_guard();
        if (self::connection()['state'] === 'off') self::free_start();
        $r = self::api('POST', '');
        if ($r['code'] === 202 && !empty($r['body']['data']['jobId'])) wp_send_json_success(['jobId' => (string) $r['body']['data']['jobId']]);
        $msg = [
            401 => __('MageSentinel did not recognise this store. Check the token under Settings.', 'magesentinel-monitor'),
            409 => __('This store has no active checkout monitor in MageSentinel.', 'magesentinel-monitor'),
            429 => __('A test purchase was just run. Try again in a couple of minutes.', 'magesentinel-monitor'),
        ][$r['code']] ?? __('Could not reach MageSentinel. Try again.', 'magesentinel-monitor');
        wp_send_json_error(['message' => $msg]);
    }

    public static function test_status(): void
    {
        self::ajax_guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ajax_guard() above checks the nonce.
        $job = isset($_GET['job']) ? preg_replace('/[^A-Za-z0-9:_-]/', '', sanitize_text_field(wp_unslash($_GET['job']))) : '';
        if (strpos($job, 'audit:') === 0) self::free_status(substr($job, 6));
        $r = self::api('GET', '/' . rawurlencode($job));
        if ($r['code'] !== 200 || empty($r['body']['data'])) wp_send_json_error(['message' => __('Could not reach MageSentinel. Try again.', 'magesentinel-monitor')]);
        wp_send_json_success($r['body']['data']);
    }

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_dashboard_setup', [self::class, 'dashboard_widget']);
        add_action('admin_post_magesentinel_recheck', [self::class, 'recheck']);
        add_action('admin_post_magesentinel_alerts_toggle', [self::class, 'toggle_alerts']);
        add_action('admin_post_magesentinel_review', [self::class, 'review_answer']);
        add_action('admin_post_magesentinel_theme', [self::class, 'theme_answer']);
        add_action('admin_post_magesentinel_snooze', [self::class, 'snooze_answer']);
        add_action('wp_ajax_magesentinel_test_start', [self::class, 'test_start']);
        add_action('wp_ajax_magesentinel_test_status', [self::class, 'test_status']);
        add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/magesentinel-monitor.php'), [self::class, 'action_links']);
    }

    public static function assets(string $hook): void
    {
        if (strpos($hook, self::SLUG) === false && $hook !== 'index.php') return;
        wp_enqueue_style('magesentinel-monitor', plugins_url('assets/admin.css', dirname(__FILE__)), [], MageSentinel_Monitor::VERSION . '.' . filemtime(dirname(__DIR__) . '/assets/admin.css'));
        wp_enqueue_script('magesentinel-monitor', plugins_url('assets/admin.js', dirname(__FILE__)), [], MageSentinel_Monitor::VERSION . '.' . filemtime(dirname(__DIR__) . '/assets/admin.js'), true);
    }

    public static function menu(): void
    {
        add_menu_page('MageSentinel', 'MageSentinel', 'manage_woocommerce', self::SLUG, [self::class, 'overview_page'], self::menu_icon(), 56);
        add_submenu_page(self::SLUG, 'MageSentinel', __('Overview', 'magesentinel-monitor'), 'manage_woocommerce', self::SLUG, [self::class, 'overview_page']);
        add_submenu_page(self::SLUG, __('MageSentinel settings', 'magesentinel-monitor'), __('Settings', 'magesentinel-monitor'), 'manage_woocommerce', self::SLUG . '-settings', [self::class, 'settings_page']);
    }

    /** WordPress tints a data-URI SVG menu icon to the admin colour scheme. */
    private static function menu_icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/><circle cx="12" cy="11" r="3"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function action_links(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html(__('Overview', 'magesentinel-monitor')) . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')) . '">' . esc_html(__('Settings', 'magesentinel-monitor')) . '</a>'
        );
        return $links;
    }

    public static function register_settings(): void
    {
        register_setting('magesentinel_monitor', MageSentinel_Monitor::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
        ]);
    }

    /** Keeps the stored hash unless a new token is typed; the token itself is never stored. */
    public static function sanitize($input): array
    {
        $old = MageSentinel_Monitor::settings();
        $token = isset($input['token']) ? trim((string) $input['token']) : '';
        return [
            'enabled' => !empty($input['enabled']),
            'allow_cancel' => !empty($input['allow_cancel']),
            'allow_captcha_skip' => !empty($input['allow_captcha_skip']),
            'allow_test_gateway' => !empty($input['allow_test_gateway']),
            'alerts' => !empty($input['alerts']),
            'weekly_report' => !empty($input['weekly_report']),
            'js_errors' => !empty($input['js_errors']),
            'alert_email' => isset($input['alert_email']) ? sanitize_email((string) $input['alert_email']) : $old['alert_email'],
            'token_hash' => $token !== '' ? hash('sha256', $token) : $old['token_hash'],
        ];
    }

    // ----------------------------------------------------------------- icons

    /** Stroke icons, 24px grid. Decorative: every one sits next to its text. */
    /**
     * The markup the plugin's own helpers build (icons, charts, the score ring,
     * the check rows), escaped against an allow-list before it is printed.
     */
    public static function allowed_html(): array
    {
        static $allowed = null;
        if ($allowed === null) {
            $common = ['class' => true, 'id' => true, 'role' => true, 'title' => true, 'style' => true, 'hidden' => true,
                'aria-hidden' => true, 'aria-label' => true, 'aria-live' => true, 'aria-current' => true, 'aria-checked' => true, 'data-*' => true];
            $svg = $common + ['xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true,
                'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
                'focusable' => true, 'preserveaspectratio' => true, 'transform' => true, 'opacity' => true, 'fill-opacity' => true,
                'd' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true, 'x' => true, 'y' => true,
                'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true, 'points' => true, 'offset' => true, 'stop-color' => true,
                'stop-opacity' => true, 'text-anchor' => true, 'dominant-baseline' => true, 'font-size' => true, 'font-weight' => true];
            $allowed = [];
            foreach (['svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'defs', 'lineargradient', 'stop', 'text', 'tspan', 'title'] as $t) $allowed[$t] = $svg;
            foreach (['div', 'span', 'b', 'strong', 'i', 'em', 'small', 'p', 'ul', 'ol', 'li', 'br', 'code', 'details', 'summary',
                'table', 'thead', 'tbody', 'tr', 'th', 'td', 'dl', 'dt', 'dd', 'h2', 'h3'] as $t) $allowed[$t] = $common + ['scope' => true, 'colspan' => true];
            $allowed['a'] = $common + ['href' => true, 'target' => true, 'rel' => true];
            $allowed['button'] = $common + ['type' => true, 'disabled' => true];
        }
        return $allowed;
    }

    private static function icon(string $name, int $size = 18): string
    {
        $paths = [
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
            'monitor' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
            'shield' => '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/><circle cx="12" cy="11" r="3"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'mail-off' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/><path d="M2 2l20 20"/>',
            'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'x' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
            'store' => '<path d="M3 9h18l-1.5-5h-15L3 9Z"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/>',
            'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6"/>',
            'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
            'bug' => '<rect x="8" y="6" width="8" height="14" rx="4"/><path d="M19 7l-3 2"/><path d="M5 7l3 2"/><path d="M19 13h-3"/><path d="M5 13h3"/><path d="M19 19l-3-2"/><path d="M5 19l3-2"/><path d="M12 6V3"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'server' => '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01"/><path d="M6 17h.01"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>',
            'disk' => '<path d="M22 12H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6l3.5-7Z"/><path d="M6 16h.01"/><path d="M10 16h.01"/>',
            'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'code' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
            'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
            'refresh' => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/><path d="M3 21v-5h5"/>',
            'gauge' => '<path d="M12 14l4-4"/><path d="M3.3 19a10 10 0 1 1 17.4 0"/>',
            'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
            'receipt' => '<path d="M4 2v20l3-2 3 2 2-2 2 2 3-2 3 2V2l-3 2-3-2-2 2-2-2-3 2-3-2Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
            'sliders' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
            'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        ];
        $body = $paths[$name] ?? $paths['check'];
        return '<svg class="ms-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    // ---------------------------------------------------------------- health

    /** @return array<int,array{key:string,icon:string,label:string,state:string,summary:string,link?:string}> */
    /** The rows the overview, the widget and the scheduled self-check all judge by. */
    public static function health_rows(): array
    {
        return self::apply_snooze(self::health(MageSentinel_Monitor::facts(), MageSentinel_Monitor::cache(), MageSentinel_Monitor::sales()));
    }

    const SNOOZED = 'magesentinel_monitor_snoozed';

    /**
     * A snoozed check counts as healthy (score, alerts, weekly email) but keeps
     * being checked: it wakes up on its own when it is fixed or gets worse
     * (needs attention → problem), and after 30 days at most.
     */
    private static function apply_snooze(array $rows): array
    {
        $snoozed = get_option(self::SNOOZED, []);
        if (!is_array($snoozed) || !$snoozed) return $rows;
        $rank = ['good' => 0, 'watch' => 1, 'bad' => 2];
        $keep = [];
        foreach ($rows as &$row) {
            $s = $snoozed[$row['key']] ?? null;
            if (!$s || $row['state'] === 'good') continue;
            if (($rank[$row['state']] ?? 2) > ($rank[$s['state'] ?? 'watch'] ?? 1) || (int) ($s['at'] ?? 0) < time() - 30 * DAY_IN_SECONDS) continue;
            $keep[$row['key']] = $s;
            $row['snoozed'] = $row['state'];
            $row['state'] = 'good';
        }
        unset($row);
        if (count($keep) !== count($snoozed)) update_option(self::SNOOZED, $keep, false);
        return $rows;
    }

    public static function snooze_answer(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Not allowed.', 'magesentinel-monitor'));
        check_admin_referer('magesentinel_snooze');
        $key = isset($_GET['key']) ? sanitize_key((string) $_GET['key']) : '';
        $state = isset($_GET['state']) && $_GET['state'] === 'bad' ? 'bad' : 'watch';
        $snoozed = get_option(self::SNOOZED, []);
        if (!is_array($snoozed)) $snoozed = [];
        if ($key !== '') {
            if (isset($_GET['do']) && $_GET['do'] === 'off') unset($snoozed[$key]);
            else $snoozed[$key] = ['state' => $state, 'at' => time()];
            update_option(self::SNOOZED, $snoozed, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function snooze_url(array $row, bool $on): string
    {
        $args = ['action' => 'magesentinel_snooze', 'key' => $row['key'], 'do' => $on ? 'on' : 'off', 'state' => $on ? $row['state'] : ($row['snoozed'] ?? 'watch')];
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'magesentinel_snooze');
    }

    private static function health(array $f, array $cache, array $sales = []): array
    {
        $rows = [];

        $rows[] = $f['maintenance']
            ? ['key' => 'storefront', 'icon' => 'store', 'label' => __('Storefront', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => __('Maintenance mode is on: every visitor gets a 503 and nobody can buy.', 'magesentinel-monitor')]
            : ['key' => 'storefront', 'icon' => 'store', 'label' => __('Storefront', 'magesentinel-monitor'), 'state' => 'good', 'summary' => __('Open. No maintenance flag.', 'magesentinel-monitor')];

        $pages = [];
        if (!$f['cart_page']) $pages[] = __('cart', 'magesentinel-monitor');
        if (!$f['checkout_page']) $pages[] = __('checkout', 'magesentinel-monitor');
        $rows[] = $pages
            ? ['key' => 'pages', 'icon' => 'cart', 'label' => __('Cart and checkout pages', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => sprintf(/* translators: %s: value */ __('The %s page is not set or not published.', 'magesentinel-monitor'), implode(' + ', $pages)), 'link' => admin_url('admin.php?page=wc-settings&tab=advanced')]
            : ['key' => 'pages', 'icon' => 'cart', 'label' => __('Cart and checkout pages', 'magesentinel-monitor'), 'state' => 'good', 'summary' => __('Both set and published.', 'magesentinel-monitor')];

        $n = count($f['payments']);
        $rows[] = $n === 0
            ? ['key' => 'payments', 'icon' => 'card', 'label' => __('Payment methods', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => __('No payment method is enabled: checkout cannot complete.', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=wc-settings&tab=checkout')]
            : ['key' => 'payments', 'icon' => 'card', 'label' => __('Payment methods', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %1$d: number of payment methods, %2$s: their names */ __('%1$d enabled: %2$s.', 'magesentinel-monitor'), $n, implode(', ', array_column($f['payments'], 'title')))];

        $n = count($f['shipping']);
        $rows[] = $n === 0
            ? ['key' => 'shipping', 'icon' => 'truck', 'label' => __('Shipping', 'magesentinel-monitor'), 'state' => 'watch', 'summary' => __('No shipping method in any zone. Fine for digital goods only.', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=wc-settings&tab=shipping')]
            : ['key' => 'shipping', 'icon' => 'truck', 'label' => __('Shipping', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %s: value */ __('%d method(s) across your zones.', 'magesentinel-monitor'), $n)];

        $failed = (int) $f['failed_orders_24h'];
        $failedHour = (int) ($f['failed_orders_1h'] ?? 0);
        $pending = (int) $f['pending_payment_24h'];
        // A method that only fails while the others take money: that gateway is broken.
        $dead = null;
        $others = 0;
        foreach ((array) ($f['gateways_24h'] ?? []) as $g) {
            if ($g['failed'] >= 3 && $g['paid'] === 0 && ($dead === null || $g['failed'] > $dead['failed'])) $dead = $g;
        }
        foreach ((array) ($f['gateways_24h'] ?? []) as $g) $others += $g['paid'];
        $summary = sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$d failed, %2$d still pending payment.', 'magesentinel-monitor'), $failed, $pending);
        if ($failedHour >= 3) $summary = sprintf(/* translators: %s: value */ __('%d failed in the last hour: a payment method may have stopped working.', 'magesentinel-monitor'), $failedHour) . ' ' . $summary;
        if ($dead) $summary .= ' ' . ($others > 0
            ? sprintf(/* translators: %1$s: value, %2$s: value, %3$s: value */ __('%1$s: %2$d failed and none paid, while other methods took %3$d.', 'magesentinel-monitor'), $dead['title'], $dead['failed'], $others)
            : sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$s: %2$d failed and none paid.', 'magesentinel-monitor'), $dead['title'], $dead['failed']));
        $rows[] = ['key' => 'failed', 'icon' => 'receipt', 'label' => __('Failed payments', 'magesentinel-monitor'), 'state' => ($failed >= 3 || $failedHour >= 3 || $dead) ? 'bad' : ($failed > 0 || $pending >= 5 ? 'watch' : 'good'), 'summary' => $summary, 'link' => admin_url('admin.php?page=wc-orders&status=wc-failed')];

        if (count($f['payments']) > 0) {
            $tm = (array) ($f['test_mode'] ?? []);
            $rows[] = $tm
                ? ['key' => 'testmode', 'icon' => 'card', 'label' => __('Live payments', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => sprintf(/* translators: %s: value */ __('%s is in test mode: real cards are declined, or orders go through without the money being taken.', 'magesentinel-monitor'), implode(', ', array_column($tm, 'title'))), 'link' => admin_url('admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode($tm[0]['code']))]
                : ['key' => 'testmode', 'icon' => 'card', 'label' => __('Live payments', 'magesentinel-monitor'), 'state' => 'good', 'summary' => __('No payment method is in test mode.', 'magesentinel-monitor')];
        }

        $fe = (int) ($f['fatal_errors']['total'] ?? 0);
        $resolvedFe = (int) ($f['fatal_errors']['resolved_inactive'] ?? 0);
        $feTop = $f['fatal_errors']['by_type'][0]['type'] ?? '';
        $feSource = strpos($feTop, 'PHP fatal error in ') === 0 ? substr($feTop, 19) : '';
        $rows[] = $fe > 0
            ? ['key' => 'fatal', 'icon' => 'bug', 'label' => __('Fatal errors', 'magesentinel-monitor'), 'state' => $fe >= 5 ? 'bad' : 'watch', 'summary' => sprintf(/* translators: %s: value */ __('%d recorded by WooCommerce in 24 hours.', 'magesentinel-monitor'), $fe) . ($feSource !== '' ? ' ' . sprintf(/* translators: %s: value */ __('Most from: %s.', 'magesentinel-monitor'), $feSource) : ''), 'link' => admin_url('admin.php?page=wc-status&tab=logs&source=fatal-errors')]
            : ['key' => 'fatal', 'icon' => 'bug', 'label' => __('Fatal errors', 'magesentinel-monitor'), 'state' => 'good', 'summary' => $resolvedFe > 0 ? sprintf(/* translators: %s: value */ __('None from active plugins. %d came from a plugin that is now deactivated.', 'magesentinel-monitor'), $resolvedFe) : __('None recorded in 24 hours.', 'magesentinel-monitor')];

        $s = $f['scheduler'];
        $notes = [];
        $state = 'good';
        if (!empty($s['actions_failed_24h'])) {
            $notes[] = sprintf(/* translators: %s: value */ __('%d scheduled action(s) failed', 'magesentinel-monitor'), $s['actions_failed_24h']) . (!empty($s['actions_failed_hooks']) ? ' (' . implode(', ', $s['actions_failed_hooks']) . ')' : '');
            $state = 'watch';
        }
        if (!empty($s['actions_past_due'])) { $notes[] = sprintf(/* translators: %s: value */ __('%d action(s) over an hour late', 'magesentinel-monitor'), $s['actions_past_due']); $state = 'watch'; }
        if (($s['wp_cron_oldest_overdue_minutes'] ?? 0) > 60 && empty($s['wp_cron_disabled'])) { $notes[] = sprintf(/* translators: %s: value */ __('WP-Cron is %d minutes behind', 'magesentinel-monitor'), $s['wp_cron_oldest_overdue_minutes']); $state = 'watch'; }
        $rows[] = ['key' => 'tasks', 'icon' => 'clock', 'label' => __('Background tasks', 'magesentinel-monitor'), 'state' => $state, 'summary' => $notes ? implode('; ', $notes) . '. ' . __('Emails, webhooks and stock sync wait on these.', 'magesentinel-monitor') : __('Running on time.', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=wc-status&tab=action-scheduler&status=failed')];

        if ($f['db_update_needed']) {
            $rows[] = ['key' => 'db', 'icon' => 'database', 'label' => __('WooCommerce database', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => __('A database update is waiting to run.', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=wc-status')];
        }

        $rows[] = $cache['https']
            ? ['key' => 'https', 'icon' => 'lock', 'label' => __('Secure connection', 'magesentinel-monitor'), 'state' => 'good', 'summary' => __('HTTPS.', 'magesentinel-monitor')]
            : ['key' => 'https', 'icon' => 'lock', 'label' => __('Secure connection', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => __('The store address is not HTTPS: browsers warn shoppers at checkout.', 'magesentinel-monitor'), 'link' => admin_url('options-general.php')];

        $web = $cache['https'] ? MageSentinel_Monitor::web_security() : null;
        if ($web && $web['redirects'] !== null) {
            $rows[] = $web['redirects']
                ? ['key' => 'redirect', 'icon' => 'lock', 'label' => __('http:// to https://', 'magesentinel-monitor'), 'state' => 'good', 'summary' => __('Visitors who type http:// are sent to the secure address.', 'magesentinel-monitor')]
                : ['key' => 'redirect', 'icon' => 'lock', 'label' => __('http:// to https://', 'magesentinel-monitor'), 'state' => 'watch', 'summary' => __('The http:// address opens without being sent to https://: shoppers can land on the insecure page and browsers mark it "Not secure".', 'magesentinel-monitor')];
        }

        $ssl = MageSentinel_Monitor::ssl();
        if ($ssl) {
            $days = (int) floor(($ssl['expires'] - time()) / DAY_IN_SECONDS);
            $until = date_i18n(get_option('date_format'), $ssl['expires']);
            $rows[] = $days < 0
                ? ['key' => 'ssl', 'icon' => 'lock', 'label' => __('SSL certificate', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => sprintf(/* translators: %s: value */ __('Expired on %s: browsers block the store with a security warning.', 'magesentinel-monitor'), $until)]
                : ['key' => 'ssl', 'icon' => 'lock', 'label' => __('SSL certificate', 'magesentinel-monitor'), 'state' => $days < 7 ? 'bad' : ($days < 14 ? 'watch' : 'good'), 'summary' => $days < 14
                    ? sprintf(/* translators: %1$s: value, %2$s: value */ __('Expires in %1$d days (%2$s). If it is not renewed, browsers block the store with a security warning.', 'magesentinel-monitor'), $days, $until)
                    : sprintf(/* translators: %1$s: value, %2$s: value */ __('Valid until %1$s (%2$d days).', 'magesentinel-monitor'), $until, $days)];
        }

        $dns = MageSentinel_Monitor::dns();
        if ($dns) {
            $first = $dns['servers'][0];
            $label = __('DNS on this server', 'magesentinel-monitor');
            if (!$dns['answered']) {
                $rows[] = ['key' => 'dns', 'icon' => 'globe', 'label' => $label, 'state' => 'bad', 'summary' => __('No nameserver in /etc/resolv.conf answers: this site cannot reach payment gateways, couriers or updates. Ask your host.', 'magesentinel-monitor')];
            } elseif ($first['ms'] === null) {
                $rows[] = ['key' => 'dns', 'icon' => 'globe', 'label' => $label, 'state' => 'watch', 'summary' => sprintf(/* translators: %1$s: seconds, %2$s: IP address, %3$s: seconds */ __('Lookups take %1$s s: the first nameserver in /etc/resolv.conf (%2$s) does not answer, so every lookup waits %3$s s before trying the next. Remove it or move it down, or ask your host.', 'magesentinel-monitor'), number_format_i18n($dns['ms'] / 1000, 1), $first['ip'], $dns['timeout'])];
            } else {
                $rows[] = ['key' => 'dns', 'icon' => 'globe', 'label' => $label, 'state' => $dns['ms'] >= 1000 ? 'watch' : 'good', 'summary' => sprintf(/* translators: %s: milliseconds */ __('Lookups answer in %s ms.', 'magesentinel-monitor'), number_format_i18n($dns['ms']))];
            }
        }

        $nb = MageSentinel_Monitor::unbuyable();
        if ($nb['count'] > 0) {
            $names = implode(', ', array_map(static function ($i) { return '«' . $i['title'] . '»'; }, array_slice($nb['items'], 0, 3))) . ($nb['count'] > 3 ? ', …' : '');
            $rows[] = ['key' => 'unbuyable', 'icon' => 'cart', 'label' => __('Products without a price', 'magesentinel-monitor'), 'state' => 'watch',
                'summary' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$s in stock but without a price, so nobody can add them to the cart: %2$s.', 'magesentinel-monitor'), $nb['count'] > 200 ? '200+' : (string) $nb['count'], $names),
                'link' => $nb['count'] === 1 ? admin_url('post.php?post=' . $nb['items'][0]['id'] . '&action=edit') : admin_url('edit.php?post_type=product')];
        }

        if ($cache['debug_display']) {
            $rows[] = ['key' => 'debug', 'icon' => 'code', 'label' => __('Errors on screen', 'magesentinel-monitor'), 'state' => 'watch', 'summary' => __('PHP errors are printed on the page: shoppers can see them and they can break checkout requests.', 'magesentinel-monitor')];
        }

        $quiet = MageSentinel_Monitor_Self_Check::silence($sales);
        if ($quiet) {
            $rows[] = $quiet['silent']
                ? ['key' => 'silence', 'icon' => 'cart', 'label' => __('Orders coming in', 'magesentinel-monitor'), 'state' => 'bad', 'summary' => $quiet['expected'] !== null
                    ? sprintf(/* translators: %1$d: hours without an order, %2$s: orders usually taken in that time */ __('No order for %1$d hours. At these hours of the week this store usually takes about %2$s orders.', 'magesentinel-monitor'), $quiet['hours'], number_format_i18n($quiet['expected'], 0))
                    : sprintf(/* translators: %1$s: value, %2$s: value */ __('No order for %1$d hours. This store usually takes about %2$s a day.', 'magesentinel-monitor'), $quiet['hours'], number_format_i18n($quiet['per_day'], 1)), 'link' => admin_url('admin.php?page=wc-orders')]
                : ['key' => 'silence', 'icon' => 'cart', 'label' => __('Orders coming in', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %s: value */ __('As usual: about %s a day.', 'magesentinel-monitor'), number_format_i18n($quiet['per_day'], 1))];
        }

        $upd = array_values(array_filter(MageSentinel_Monitor::recent_updates(2 * DAY_IN_SECONDS), static function ($u) { return !empty($u['checkout']); }));
        if ($upd) {
            $names = implode(', ', array_unique(array_map(static function ($u) { return trim($u['name'] . ' ' . $u['version']); }, $upd)));
            $ago = human_time_diff((int) $upd[0]['at'], time());
            $oldest = (int) end($upd)['at'];
            $after = MageSentinel_Monitor::after_update_state();
            if (MageSentinel_Monitor::test_orders_since($oldest)) {
                $rows[] = ['key' => 'updated', 'icon' => 'refresh', 'label' => __('After the update', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$s updated %2$s ago. A MageSentinel test purchase has gone through since.', 'magesentinel-monitor'), $names, $ago)];
            } elseif ($after['code'] === 202 && $after['at'] >= $oldest) {
                // The check this update triggered: its verdict arrives as an alert, not here.
                $rows[] = ['key' => 'updated', 'icon' => 'refresh', 'label' => __('After the update', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %1$s: value, %2$s: value, %3$s: value */ __('%1$s updated %2$s ago. MageSentinel started a test purchase %3$s after it and alerts you if checkout broke.', 'magesentinel-monitor'), $names, $ago, human_time_diff((int) $upd[0]['at'], $after['at']))];
            } elseif (MageSentinel_Monitor::connected() && wp_next_scheduled(MageSentinel_Monitor::AFTER_UPDATE_HOOK)) {
                $rows[] = ['key' => 'updated', 'icon' => 'refresh', 'label' => __('After the update', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$s updated %2$s ago. MageSentinel tests checkout in a couple of minutes.', 'magesentinel-monitor'), $names, $ago)];
            } elseif (MageSentinel_Monitor_Self_Check::monitored()) {
                $rows[] = ['key' => 'updated', 'icon' => 'refresh', 'label' => __('After the update', 'magesentinel-monitor'), 'state' => 'good', 'summary' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$s updated %2$s ago. MageSentinel places its next test purchase within the hour.', 'magesentinel-monitor'), $names, $ago)];
            } else {
                $rows[] = ['key' => 'updated', 'icon' => 'refresh', 'label' => __('After the update', 'magesentinel-monitor'), 'state' => 'watch', 'summary' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$s updated %2$s ago. Updates are the most common reason a checkout breaks: place a test order.', 'magesentinel-monitor'), $names, $ago), 'link' => MageSentinel_Monitor_Self_Check::SIGNUP_URL, 'link_text' => __('Test it with a real purchase', 'magesentinel-monitor')];
            }
        }

        $rows = array_merge($rows, MageSentinel_Monitor_Checks::rows());

        if ($f['disk']) {
            $used = (int) $f['disk']['used_percent'];
            $rows[] = ['key' => 'disk', 'icon' => 'disk', 'label' => __('Disk', 'magesentinel-monitor'), 'state' => $used >= 95 ? 'bad' : ($used >= 90 ? 'watch' : 'good'), 'summary' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$d%% used, %2$s free.', 'magesentinel-monitor'), $used, size_format($f['disk']['free_bytes']))];
        }

        return $rows;
    }

    /** The workspace's plan as MageSentinel reports it, cached for an hour; null when it cannot be read. */
    private static function plan_status(): ?array
    {
        $cached = get_transient('magesentinel_monitor_plan');
        if (is_array($cached)) return $cached;
        $s = MageSentinel_Monitor::settings();
        $res = wp_remote_get('https://magesentinel.com/api/connector/status', [
            'timeout' => 8,
            'headers' => ['X-MageSentinel-Key' => $s['token_hash'], 'X-MageSentinel-Site' => home_url('/'), 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return null;
        $d = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($d['data'] ?? null)) return null;
        set_transient('magesentinel_monitor_plan', $d['data'], HOUR_IN_SECONDS);
        return $d['data'];
    }

    /** @return array{state:string,title:string,detail:string,plans?:bool} */
    private static function connection(): array
    {
        $s = MageSentinel_Monitor::settings();
        $a = MageSentinel_Monitor::activity();
        if (!$s['enabled'] || $s['token_hash'] === '') {
            return ['state' => 'off', 'title' => __('Not connected', 'magesentinel-monitor'), 'detail' => __('Switch the connector on and paste your token under Settings.', 'magesentinel-monitor')];
        }
        // On the Free plan MageSentinel stops calling the connector, so "last seen" goes stale by design.
        $plan = self::plan_status();
        if ($plan !== null && empty($plan['connector'])) {
            return ['state' => 'watch', 'title' => __('Free plan: connector inactive', 'magesentinel-monitor'), 'detail' => __('MageSentinel still checks your checkout once a day, without test orders or store diagnostics. The plugin can stay installed. Choose a plan for checks every 30 minutes.', 'magesentinel-monitor'), 'plans' => true];
        }
        if ($a['last_seen'] === 0) {
            return ['state' => 'watch', 'title' => __('Waiting for the first check', 'magesentinel-monitor'), 'detail' => __('Ready. MageSentinel has not called in yet — run a check from your dashboard.', 'magesentinel-monitor')];
        }
        $ago = human_time_diff($a['last_seen'], time());
        if (time() - $a['last_seen'] > 3 * HOUR_IN_SECONDS) {
            return ['state' => 'watch', 'title' => __('No recent checks', 'magesentinel-monitor'), 'detail' => sprintf(/* translators: %s: value */ __('Last check %s ago. Is the monitor paused, or the token rotated?', 'magesentinel-monitor'), $ago)];
        }
        return ['state' => 'good', 'title' => __('Monitored by MageSentinel', 'magesentinel-monitor'), 'detail' => sprintf(/* translators: %s: value */ __('Last check %s ago.', 'magesentinel-monitor'), $ago)];
    }

    /** The pricing page in the admin's language. */
    private static function pricing_url(): string
    {
        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        return strpos($locale, 'el') === 0 ? 'https://magesentinel.com/el/pricing?utm_source=wporg&utm_medium=plugin' : MageSentinel_Monitor_Self_Check::PRICING_URL;
    }

    /** Where the free alerts stand, in one line. */
    private static function alerts_status(): array
    {
        $s = MageSentinel_Monitor::settings();
        if (MageSentinel_Monitor_Self_Check::monitored()) return ['state' => 'good', 'text' => __('MageSentinel sends the alerts', 'magesentinel-monitor')];
        if (!$s['alerts']) return ['state' => 'off', 'text' => __('Email alerts off', 'magesentinel-monitor')];
        $to = MageSentinel_Monitor_Self_Check::recipient();
        return $to === '' ? ['state' => 'watch', 'text' => __('No address for alerts', 'magesentinel-monitor')] : ['state' => 'good', 'text' => __('Email alerts on', 'magesentinel-monitor')];
    }

    private static function state_icon(string $state): string
    {
        return self::icon($state === 'good' ? 'check' : ($state === 'bad' ? 'x' : 'alert'), 16);
    }

    // ---------------------------------------------------------------- charts

    /** Round a peak up to a clean top tick: 2, 4, 6, 10, 20, 40, 60, 100 … */
    private static function nice_max(int $peak): int
    {
        if ($peak <= 2) return 2;
        $mag = 10 ** (int) floor(log10($peak));
        foreach ([1, 2, 4, 5, 6, 8, 10] as $m) {
            if ($m * $mag >= $peak) return $m * $mag;
        }
        return 10 * $mag;
    }

    /** A column with a 4px rounded data-end and a square foot on the baseline. */
    private static function column(float $x, float $y, float $w, float $h, string $fill, int $i = 0): string
    {
        $r = min(4, $w / 2, $h);
        return '<path d="M' . round($x, 1) . ' ' . round($y + $h, 1)
            . ' V' . round($y + $r, 1) . ' Q' . round($x, 1) . ' ' . round($y, 1) . ' ' . round($x + $r, 1) . ' ' . round($y, 1)
            . ' H' . round($x + $w - $r, 1) . ' Q' . round($x + $w, 1) . ' ' . round($y, 1) . ' ' . round($x + $w, 1) . ' ' . round($y + $r, 1)
            . ' V' . round($y + $h, 1) . ' Z" fill="' . $fill . '" class="ms-col" style="--i:' . $i . '"/>';
    }

    /** Y ticks (0, half, top) as solid hairlines with clean labels. */
    private static function y_grid(int $max, int $left, int $right, int $top, float $plot): string
    {
        $out = '';
        foreach ([0, 0.5, 1] as $g) {
            $y = round($top + $plot * (1 - $g), 1) + 0.5;
            $out .= '<line x1="' . $left . '" x2="' . $right . '" y1="' . $y . '" y2="' . $y . '" stroke="' . ($g ? '#eef0f5' : '#d5d9e3') . '" stroke-width="1"/>'
                . '<text x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="ms-tick">' . number_format_i18n($max * $g) . '</text>';
        }
        return $out;
    }

    /** Seven daily columns; today in the accent, the rest a paler step. Label today and the peak only. */
    /** Vertical gradient for columns. Ids repeat across charts on a page; the stops are identical, so either copy resolves. */
    private static function gradient(string $id, string $from, string $to, string $toOpacity = '1'): string
    {
        return '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $from . '"/><stop offset="1" stop-color="' . $to . '" stop-opacity="' . $toOpacity . '"/></linearGradient></defs>';
    }

    /** A step over its usual time, as MageSentinel's dashboard marks it (same thresholds). */
    private static function any_slow(array $names, array $durations): bool
    {
        $limit = ['discovery' => 4000, 'product' => 6000, 'cart' => 15000, 'cart-validation' => 3000, 'checkout' => 5000, 'customer' => 14000, 'shipping' => 8000, 'payment' => 5000];
        foreach ($names as $i => $n) if ((int) ($durations[$i] ?? 0) > ($limit[$n] ?? 8000)) return true;
        return false;
    }

    /** The Checkout speed card's runs: MageSentinel's last 30 when connected, else the free monthly tests. */
    private static function speed_runs(array $conn): array
    {
        if ($conn['state'] === 'off') return MageSentinel_Monitor::free_speed();
        $cached = get_transient('magesentinel_monitor_speed');
        if (is_array($cached)) return $cached;
        $r = self::api('GET', '/history');
        $runs = $r['code'] === 200 && is_array($r['body']['data'] ?? null) ? array_values(array_filter($r['body']['data'], 'is_array')) : [];
        set_transient('magesentinel_monitor_speed', $runs, 10 * MINUTE_IN_SECONDS);
        return $runs;
    }

    private static function secs(int $ms): string
    {
        return number_format_i18n($ms / 1000, 1) . ' s';
    }

    /** Two lines, this week over last week, store days. */
    private static function revenue_chart(array $rev, callable $money): string
    {
        $w = 560; $h = 190; $top = 16; $bottom = 26; $left = 8; $right = $w - 8; $plot = $h - $top - $bottom;
        $max = max(1.0, max($rev['this']), max($rev['last'])) * 1.12;
        $x = static function (int $i) use ($left, $right) { return round($left + ($right - $left) * $i / 6, 1); };
        $y = static function (float $v) use ($top, $plot, $max) { return round($top + $plot - $plot * $v / $max, 1); };
        $pts = static function (array $vals) use ($x, $y) { $o = []; foreach ($vals as $i => $v) $o[] = $x($i) . ',' . $y((float) $v); return implode(' ', $o); };
        $out = '<div class="ms-plot" data-ms-plot><svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr(__('Revenue this week vs last week', 'magesentinel-monitor')) . '">'
            . self::gradient('ms-rev', '#4f6ef7', '#4f6ef7', '0');
        for ($g = 1; $g <= 3; $g++) { $gy = round($top + $plot * $g / 4, 1); $out .= '<line x1="' . $left . '" x2="' . $right . '" y1="' . $gy . '" y2="' . $gy . '" stroke="#eef1f6"/>'; }
        $area = $x(0) . ',' . ($top + $plot) . ' ' . $pts($rev['this']) . ' ' . $x(6) . ',' . ($top + $plot);
        $out .= '<polygon class="ms-rev-area" points="' . $area . '" fill="url(#ms-rev)" fill-opacity=".18"/>'
            . '<polyline class="ms-rev-last" points="' . $pts($rev['last']) . '" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 4"/>'
            . '<polyline class="ms-rev-this" points="' . $pts($rev['this']) . '" fill="none" stroke="#4f6ef7" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
        foreach ($rev['this'] as $i => $v) {
            $today = $i === 6;
            $out .= '<g class="ms-hitg"><circle cx="' . $x($i) . '" cy="' . $y((float) $v) . '" r="' . ($today ? 4.5 : 3) . '" fill="' . ($today ? '#4f6ef7' : '#fff') . '" stroke="#4f6ef7" stroke-width="2"/>'
                . '<text class="ms-tick' . ($today ? ' now' : '') . '" x="' . $x($i) . '" y="' . ($h - 7) . '" text-anchor="middle">' . esc_html($today ? __('Today', 'magesentinel-monitor') : date_i18n('D', strtotime($rev['days'][$i] . ' 12:00:00'))) . '</text>'
                . '<rect class="ms-hit" x="' . round($x($i) - ($right - $left) / 12, 1) . '" y="' . $top . '" width="' . round(($right - $left) / 6, 1) . '" height="' . ($plot + $bottom) . '" data-x="' . $x($i) . '" data-y="' . $y((float) $v) . '" data-label="' . esc_attr(date_i18n('D j M', strtotime($rev['days'][$i] . ' 12:00:00'))) . '" data-value="' . esc_attr($money((float) $v, 0) . ' · ' . sprintf(/* translators: %s: amount */ __('last week %s', 'magesentinel-monitor'), $money((float) $rev['last'][$i], 0))) . '"/></g>';
        }
        return $out . '</svg><div class="ms-tip" role="tooltip"></div></div>';
    }

    private static function week_chart(array $days, bool $compact = false): string
    {
        $days = array_values($days);
        $n = max(1, count($days));
        $w = $compact ? 540 : 560; $h = $compact ? 130 : 200; $top = 24; $bottom = 26; $left = $compact ? 4 : 30; $right = $w - 4;
        $plot = $h - $top - $bottom;
        $peak = $days ? (int) max(array_column($days, 'count')) : 0;
        $max = self::nice_max($peak);
        $band = ($right - $left) / $n;
        $bw = min($compact ? 34 : 40, $band * ($compact ? 0.6 : 0.56));
        $out = '<div class="ms-plot" data-ms-plot><svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr(__('Orders, last 7 days', 'magesentinel-monitor')) . '">'
            . self::gradient('ms-teal', '#2dd4bf', '#0d9488') . self::gradient('ms-teal-p', '#a7e3dc', '#a7e3dc', '.75');
        $out .= $compact
            ? '<line x1="0" x2="' . $w . '" y1="' . ($top + $plot + 0.5) . '" y2="' . ($top + $plot + 0.5) . '" stroke="#d5d9e3"/>'
            : self::y_grid($max, $left, $right, $top, $plot);
        $peakShown = false;
        foreach ($days as $i => $d) {
            $c = (int) $d['count'];
            $today = $i === $n - 1;
            $cx = $left + $band * ($i + 0.5);
            $bh = $c > 0 ? max(3, $plot * $c / $max) : 0;
            $y = $top + $plot - $bh;
            $out .= '<g class="ms-hitg">';
            if ($bh > 0) $out .= self::column($cx - $bw / 2, $y, $bw, $bh, $today ? 'url(#ms-teal)' : 'url(#ms-teal-p)', $i);
            $isPeak = !$compact && !$peakShown && $c === $peak && $c > 0 && !$today;
            if (!$compact && ($today || $isPeak)) {
                $peakShown = $peakShown || $isPeak;
                $out .= '<text class="ms-val" x="' . round($cx, 1) . '" y="' . round($y - 8, 1) . '" text-anchor="middle">' . number_format_i18n($c) . '</text>';
            }
            $out .= '<text class="ms-tick' . ($today ? ' now' : '') . '" x="' . round($cx, 1) . '" y="' . ($h - 7) . '" text-anchor="middle">' . esc_html($today ? __('Today', 'magesentinel-monitor') : date_i18n('D', strtotime($d['day'] . ' 12:00:00'))) . '</text>'
                . '<rect class="ms-hit" x="' . round($left + $band * $i, 1) . '" y="' . $top . '" width="' . round($band, 1) . '" height="' . ($plot + $bottom) . '" data-x="' . round($cx, 1) . '" data-y="' . round($y, 1) . '" data-label="' . esc_attr(date_i18n('D j M', strtotime($d['day'] . ' 12:00:00'))) . '" data-value="' . esc_attr(self::orders_label($c)) . '"/></g>';
        }
        return $out . '</svg><div class="ms-tip" role="tooltip"></div></div>';
    }

    /** @return array<int,array{label:string,count:int}> the last 24 hours, oldest first, in store time. */
    private static function hour_slots(array $hours): array
    {
        $byHour = [];
        foreach ($hours as $p) $byHour[$p['hour']] = (int) $p['count'];
        $slots = [];
        for ($i = 23; $i >= 0; $i--) {
            $key = gmdate('Y-m-d\TH:00:00\Z', time() - $i * HOUR_IN_SECONDS);
            $start = get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($key)), 'H:00');
            $end = get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($key) + HOUR_IN_SECONDS), 'H:00');
            $slots[] = ['short' => $start, 'label' => $start . '–' . $end, 'count' => $byHour[$key] ?? 0];
        }
        return $slots;
    }

    private static function day_chart(array $slots): string
    {
        $w = 560; $h = 200; $top = 24; $bottom = 26; $left = 30; $right = $w - 4;
        $plot = $h - $top - $bottom;
        $max = self::nice_max((int) max(array_column($slots, 'count')));
        $band = ($right - $left) / 24;
        $bw = min(14, $band * 0.7);
        $out = '<div class="ms-plot" data-ms-plot><svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr(__('Orders by hour, last 24 hours', 'magesentinel-monitor')) . '">'
            . self::gradient('ms-violet', '#a78bfa', '#7c3aed')
            . self::y_grid($max, $left, $right, $top, $plot);
        foreach ($slots as $i => $s) {
            $c = (int) $s['count'];
            $cx = $left + $band * ($i + 0.5);
            $bh = $c > 0 ? max(3, $plot * $c / $max) : 0;
            $y = $top + $plot - $bh;
            $out .= '<g class="ms-hitg">';
            if ($bh > 0) $out .= self::column($cx - $bw / 2, $y, $bw, $bh, 'url(#ms-violet)', $i);
            $tick = $i % 6 === 0 ? $s['short'] : ($i === 23 ? __('Now', 'magesentinel-monitor') : '');
            if ($tick !== '') {
                $out .= '<text class="ms-tick' . ($i === 23 ? ' now' : '') . '" x="' . round($cx, 1) . '" y="' . ($h - 7) . '" text-anchor="' . ($i === 23 ? 'end' : 'middle') . '">' . esc_html($tick) . '</text>';
            }
            $out .= '<rect class="ms-hit" x="' . round($left + $band * $i, 1) . '" y="' . $top . '" width="' . round($band, 1) . '" height="' . ($plot + $bottom) . '" data-x="' . round($cx, 1) . '" data-y="' . round($y, 1) . '" data-label="' . esc_attr($s['label']) . '" data-value="' . esc_attr(self::orders_label($c)) . '"/></g>';
        }
        return $out . '</svg><div class="ms-tip" role="tooltip"></div></div>';
    }

    private static function orders_label(int $n): string
    {
        return $n === 1 ? __('1 order', 'magesentinel-monitor') : sprintf(/* translators: %s: value */ __('%s orders', 'magesentinel-monitor'), number_format_i18n($n));
    }

    /** The same numbers as a small table, so nothing lives in the picture alone. */
    private static function table_view(array $rows): string
    {
        $out = '<details class="ms-details"><summary>' . esc_html(__('Show as table', 'magesentinel-monitor')) . '</summary><table class="ms-mini">';
        foreach ($rows as [$label, $count]) {
            $out .= '<tr><td>' . esc_html($label) . '</td><td>' . number_format_i18n($count) . '</td></tr>';
        }
        return $out . '</table></details>';
    }

    /**
     * Store health as a ring: 100% green, 85–99% light orange, below that red.
     * The final value is in the markup; admin.js only replays the fill.
     */
    private static function ring(int $pct, string $size = ''): string
    {
        $tone = $pct >= 100 ? 'good' : ($pct >= 85 ? 'watch' : 'bad');
        $c = round(2 * M_PI * 42, 2);
        $off = round($c * (1 - $pct / 100), 2);
        $arc = ' cx="50" cy="50" r="42" stroke="url(#ms-rg-' . $tone . ')" stroke-dasharray="' . $c . '" stroke-dashoffset="' . $off . '"';
        return '<div class="ms-ring' . ($size ? ' ms-ring-' . $size : '') . '" data-ms-ring="' . $pct . '" role="img" aria-label="' . esc_attr(sprintf(/* translators: %s: value */ __('Store health %d%%', 'magesentinel-monitor'), $pct)) . '">'
            . '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"><defs>'
            . '<linearGradient id="ms-rg-good" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4ade80"/><stop offset="1" stop-color="#059669"/></linearGradient>'
            . '<linearGradient id="ms-rg-watch" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fdba74"/><stop offset="1" stop-color="#f59e0b"/></linearGradient>'
            . '<linearGradient id="ms-rg-bad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fb7185"/><stop offset="1" stop-color="#dc2626"/></linearGradient>'
            . '</defs><circle class="ms-ring-track" cx="50" cy="50" r="42"/><circle class="ms-ring-glow"' . $arc . '/><circle class="ms-ring-arc"' . $arc . '/></svg>'
            . '<b><span class="ms-ring-n">' . $pct . '</span><small>%</small></b></div>';
    }

    /** One segment per health check, in the list's order. */
    private static function strip(array $rows): string
    {
        $out = '<div class="ms-strip" aria-hidden="true">';
        foreach ($rows as $r) $out .= '<span class="ms-seg ms-' . esc_attr($r['state']) . '" title="' . esc_attr($r['label']) . '"></span>';
        return $out . '</div>';
    }

    /** @return array{pct:int,good:int,total:int,worst:string,title:string,sub:string} */
    private static function verdict(array $rows): array
    {
        $states = array_column($rows, 'state');
        $total = count($rows);
        $good = count(array_filter($states, static function ($x) { return $x === 'good'; }));
        $issues = $total - $good;
        $worst = in_array('bad', $states, true) ? 'bad' : ($issues ? 'watch' : 'good');
        $title = $worst === 'good' ? __('Everything that takes orders looks healthy', 'magesentinel-monitor')
            : ($worst === 'bad' ? __('Something is stopping or risking orders', 'magesentinel-monitor')
            : ($issues === 1 ? __('One thing needs attention', 'magesentinel-monitor') : sprintf(/* translators: %s: value */ __('%d things need attention', 'magesentinel-monitor'), $issues)));
        return [
            'pct' => $total ? (int) floor(100 * $good / $total) : 0,
            'good' => $good, 'total' => $total, 'worst' => $worst, 'title' => $title,
            'sub' => sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$d of %2$d checks healthy', 'magesentinel-monitor'), $good, $total)
                . (($z = count(array_filter($rows, static function ($r) { return !empty($r['snoozed']); }))) ? ' · ' . sprintf(/* translators: %d: snoozed checks */ _n('%d snoozed', '%d snoozed', $z, 'magesentinel-monitor'), $z) : '') . '.',
        ];
    }

    /** Problems first: a healthy row is reassurance, a failing one is work. */
    private static function sorted_health(): array
    {
        $rows = self::health_rows();
        $order = ['bad' => 0, 'watch' => 1, 'good' => 2];
        usort($rows, static function ($x, $y) use ($order) { return $order[$x['state']] <=> $order[$y['state']]; });
        return $rows;
    }

    /**
     * The checks in three cards, as the Magento module shows them: taking
     * orders, security, platform. Rows keep their problems-first order.
     *
     * @return array<int,array{id:string,title:string,icon:string,rows:array,good:int,worst:string}>
     */
    private static function check_groups(array $rows): array
    {
        $groups = [
            'sales' => [__('Taking orders', 'magesentinel-monitor'), 'cart', ['storefront', 'pages', 'payments', 'shipping', 'failed', 'testmode', 'silence', 'emails', 'unbuyable', 'updated', 'js_errors']],
            'security' => [__('Security', 'magesentinel-monitor'), 'shield', ['skimmers', 'uploads_php', 'admin_user', 'logins', 'integrity', 'https', 'redirect', 'ssl', 'debug']],
            'platform' => [__('Platform', 'magesentinel-monitor'), 'server', []],
        ];
        $by = array_fill_keys(array_keys($groups), []);
        foreach ($rows as $i => $row) {
            $row['i'] = $i; // position in the health strip, which the settle animation follows
            $key = 'platform';
            foreach ($groups as $id => $g) {
                if (in_array($row['key'], $g[2], true)) { $key = $id; break; }
            }
            $by[$key][] = $row;
        }
        $out = [];
        foreach ($groups as $id => [$title, $icon]) {
            if (!$by[$id]) continue;
            $states = array_column($by[$id], 'state');
            $out[] = [
                'id' => $id, 'title' => $title, 'icon' => $icon, 'rows' => $by[$id],
                'good' => count(array_keys($states, 'good', true)),
                'worst' => in_array('bad', $states, true) ? 'bad' : (in_array('watch', $states, true) ? 'watch' : 'good'),
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------- pages

    private static function header(string $active): void
    {
        $conn = self::connection();
        ?>
        <header class="ms-top ms-appear">
            <div class="ms-brand">
                <img class="ms-logo" src="<?php echo esc_url(plugins_url('assets/logo.svg', dirname(__FILE__))); ?>" width="44" height="44" alt="">
                <div><h1>MageSentinel</h1><p><?php echo esc_html(__('Checkout monitoring for WooCommerce', 'magesentinel-monitor')); ?></p></div>
                <span class="ms-pill ms-pill-<?php echo esc_attr($conn['state']); ?>" title="<?php echo esc_attr($conn['detail']); ?>"><span class="ms-pulse"></span><?php echo esc_html($conn['title']); ?></span>
            </div>
            <nav class="ms-nav" aria-label="MageSentinel">
                <?php self::theme_switch(); ?>
                <a class="ms-tab<?php echo $active === 'overview' ? ' on' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>"<?php echo $active === 'overview' ? ' aria-current="page"' : ''; ?>><?php echo wp_kses(self::icon('gauge', 16), self::allowed_html()); ?><?php echo esc_html(__('Overview', 'magesentinel-monitor')); ?></a>
                <a class="ms-tab<?php echo $active === 'settings' ? ' on' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>"<?php echo $active === 'settings' ? ' aria-current="page"' : ''; ?>><?php echo wp_kses(self::icon('sliders', 16), self::allowed_html()); ?><?php echo esc_html(__('Settings', 'magesentinel-monitor')); ?></a>
                <?php if ($conn['state'] === 'off') : ?>
                    <a class="ms-btn ms-btn-ghost" href="<?php echo esc_url(self::pricing_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Plans & pricing', 'magesentinel-monitor')); ?></a>
                    <a class="ms-btn ms-btn-primary" href="<?php echo esc_url(MageSentinel_Monitor_Self_Check::SIGNUP_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Try MageSentinel free', 'magesentinel-monitor')); ?><?php echo wp_kses(self::icon('external', 15), self::allowed_html()); ?></a>
                <?php else : ?>
                    <?php if (!empty($conn['plans'])) : ?>
                        <a class="ms-btn ms-btn-ghost" href="<?php echo esc_url(self::pricing_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Plans & pricing', 'magesentinel-monitor')); ?></a>
                    <?php endif; ?>
                    <a class="ms-btn ms-btn-primary" href="<?php echo esc_url(self::DASHBOARD_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Open MageSentinel', 'magesentinel-monitor')); ?><?php echo wp_kses(self::icon('external', 15), self::allowed_html()); ?></a>
                <?php endif; ?>
            </nav>
        </header>
        <?php
    }

    /** A setting's option name, said the way a shop owner would. */
    private static function setting_label(string $path): string
    {
        $known = [
            'active_plugins' => __('Active plugins', 'magesentinel-monitor'),
            'stylesheet' => __('Theme', 'magesentinel-monitor'),
            'woocommerce_ship_to_countries' => __('Shipping countries', 'magesentinel-monitor'),
            'woocommerce_currency' => __('Currency', 'magesentinel-monitor'),
            'woocommerce_calc_taxes' => __('Taxes', 'magesentinel-monitor'),
            'woocommerce_enable_guest_checkout' => __('Guest checkout', 'magesentinel-monitor'),
            'woocommerce_checkout_page_id' => __('Checkout page', 'magesentinel-monitor'),
            'woocommerce_cart_page_id' => __('Cart page', 'magesentinel-monitor'),
        ];
        if (isset($known[$path])) return $known[$path];
        if (preg_match('/^woocommerce_(.+)_settings$/', $path, $m)) return sprintf(/* translators: %s: value */ __('Payment or shipping method: %s', 'magesentinel-monitor'), $m[1]);
        return __('WooCommerce setting', 'magesentinel-monitor');
    }

    /** Tabs over the overview so it is four short pages instead of one long one. Numbers are open problems. */
    private static function overview_tabs(array $rows): void
    {
        $open = static function (array $keys) use ($rows): int {
            return count(array_filter($rows, static function ($r) use ($keys) { return $r['state'] !== 'good' && ($keys === [] || in_array($r['key'], $keys, true)); }));
        };
        $hasSecurity = MageSentinel_Monitor_Js_Errors::enabled() && MageSentinel_Monitor_Js_Errors::summary() || MageSentinel_Monitor_Security_Watch::logins()['since'];
        $tabs = [
            'overview' => [__('Overview', 'magesentinel-monitor'), 'gauge', 0],
            'health' => [__('Health checks', 'magesentinel-monitor'), 'check', $open([])],
            'security' => [__('Errors & logins', 'magesentinel-monitor'), 'shield', $open(['js_errors', 'logins'])],
            'store' => [__('Store', 'magesentinel-monitor'), 'store', 0],
        ];
        if (!$hasSecurity) unset($tabs['security']);
        ?>
        <nav class="ms-ptabs" role="tablist" aria-label="<?php echo esc_attr(__('Sections', 'magesentinel-monitor')); ?>" data-ms-ptabs>
            <?php foreach ($tabs as $id => [$label, $icon, $n]) : ?>
                <a role="tab" class="ms-ptab<?php echo $id === 'overview' ? ' on' : ''; ?>" href="#tab-<?php echo esc_attr($id); ?>" data-ms-ptab="<?php echo esc_attr($id); ?>" aria-selected="<?php echo $id === 'overview' ? 'true' : 'false'; ?>"><?php echo wp_kses(self::icon($icon, 15), self::allowed_html()); ?><?php echo esc_html($label); ?><?php if ($n) : ?><span class="ms-ptab-n"><?php echo esc_html((string) $n); ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /** A section heading with its icon tile; tone is good|watch|bad|'' */
    private static function sec_head(string $icon, string $title, string $sub, string $tone = ''): void
    {
        ?>
        <div class="ms-head ms-sec-head"><h2><span class="ms-sec-ico<?php echo $tone ? ' ms-' . esc_attr($tone) : ''; ?>"><?php echo wp_kses(self::icon($icon, 16), self::allowed_html()); ?></span><?php echo esc_html($title); ?></h2><small><?php echo wp_kses($sub, self::allowed_html()); ?></small></div>
        <?php
    }

    /** One KPI card for the Errors & logins tab. */
    private static function sec_kpi(string $icon, string $label, int $value, string $tone, string $note): void
    {
        ?>
        <div class="ms-card ms-kpi ms-appear ms-kpi-<?php echo esc_attr($tone ?: 'plain'); ?>">
            <span class="ms-kpi-l"><?php echo wp_kses(self::icon($icon, 16), self::allowed_html()); ?><?php echo esc_html($label); ?></span>
            <span class="ms-kpi-v" data-ms-count="<?php echo esc_attr((string) $value); ?>"><?php echo esc_html(number_format_i18n($value)); ?></span>
            <span class="ms-kpi-d"><?php echo wp_kses($note, self::allowed_html()); ?></span>
        </div>
        <?php
    }

    private static function chip(string $text, string $tone = ''): string
    {
        return '<span class="ms-chip' . ($tone ? ' ms-' . esc_attr($tone) : '') . '">' . esc_html($text) . '</span>';
    }

    /** KPI row on top of the Errors & logins tab. */
    private static function security_kpis(): void
    {
        $js = MageSentinel_Monitor_Js_Errors::enabled() ? MageSentinel_Monitor_Js_Errors::summary() : [];
        $day = MageSentinel_Monitor_Security_Watch::login_totals(24);
        if (!$js && !$day['since']) return;
        $week = MageSentinel_Monitor_Security_Watch::login_totals(MageSentinel_Monitor_Security_Watch::HOURS);
        $n24 = array_sum(array_column($js, 'n24'));
        $topIp = $day['ip'] ? (int) reset($day['ip']) : 0;
        $failTone = $day['fail'] >= 1000 ? 'bad' : (($day['fail'] >= 100 || $topIp >= 50) ? 'watch' : 'good');
        ?>
        <section data-ms-tab="security" class="ms-kpis">
            <?php
            self::sec_kpi('bug', __('Checkout JS errors, 24 h', 'magesentinel-monitor'), $n24, $js ? ($n24 >= 20 ? 'bad' : ($n24 > 0 ? 'watch' : 'good')) : '',
                $js ? self::chip(sprintf(/* translators: %d: count */ _n('%d distinct error', '%d distinct errors', count($js), 'magesentinel-monitor'), count($js)), $n24 > 0 ? 'watch' : '') . esc_html(__('14 days', 'magesentinel-monitor'))
                    : self::chip(__('none recorded', 'magesentinel-monitor'), 'good'));
            self::sec_kpi('lock', __('Failed logins, 24 h', 'magesentinel-monitor'), (int) $day['fail'], $failTone,
                self::chip($failTone === 'good' ? __('normal', 'magesentinel-monitor') : ($failTone === 'bad' ? __('under attack', 'magesentinel-monitor') : __('busy', 'magesentinel-monitor')), $failTone)
                . esc_html(sprintf(/* translators: %s: count */ __('%s in 7 days', 'magesentinel-monitor'), number_format_i18n((int) $week['fail']))));
            self::sec_kpi('check', __('Successful logins, 24 h', 'magesentinel-monitor'), (int) $day['ok'], '',
                esc_html(sprintf(/* translators: %s: count */ __('%s administrator logins this week', 'magesentinel-monitor'), number_format_i18n(count($week['admins'])))));
            self::sec_kpi('globe', __('Networks trying, 24 h', 'magesentinel-monitor'), count($day['ip']), $topIp >= 50 ? 'watch' : '',
                $topIp ? esc_html(sprintf(/* translators: %s: count */ __('busiest sent %s', 'magesentinel-monitor'), number_format_i18n($topIp))) : esc_html(__('no failed logins', 'magesentinel-monitor')));
            ?>
        </section>
        <?php
    }

    /** Ranked list with a proportional bar behind each count. */
    private static function bar_list(array $items, string $empty, bool $code = true): void
    {
        if (!$items) { echo '<p class="ms-empty">' . wp_kses(self::icon('check', 14), self::allowed_html()) . esc_html($empty) . '</p>'; return; }
        $max = max(1, (int) max($items));
        echo '<ol class="ms-bars">';
        foreach ($items as $name => $n) {
            $w = max(4, (int) round($n / $max * 100));
            echo '<li style="--w:' . esc_attr((string) $w) . '%"><span class="ms-bars-k">' . ($code ? '<code>' . esc_html((string) $name) . '</code>' : esc_html((string) $name)) . '</span><span class="ms-bars-v">' . esc_html(number_format_i18n((int) $n)) . '</span></li>';
        }
        echo '</ol>';
    }

    /** Shoppers' JavaScript errors on cart and checkout, shown once there is something to show. */
    private static function js_errors_section(): void
    {
        if (!MageSentinel_Monitor_Js_Errors::enabled()) return;
        $errors = MageSentinel_Monitor_Js_Errors::summary();
        if (!$errors) return;
        $n24 = array_sum(array_column($errors, 'n24'));
        $clear = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=magesentinel_js_errors_clear'), 'magesentinel_js_errors_clear')) . '">' . esc_html(__('Clear the list', 'magesentinel-monitor')) . '</a>';
        ?>
        <section id="ms-js-errors" data-ms-tab="security" class="ms-card ms-appear" style="--d:6">
            <?php self::sec_head('bug', __('JavaScript errors on cart and checkout', 'magesentinel-monitor'), esc_html(__('from real shoppers, last 14 days', 'magesentinel-monitor')) . ' · ' . $clear, $n24 >= 20 ? 'bad' : ($n24 > 0 ? 'watch' : 'good')); ?>
            <div class="ms-scroll"><table class="ms-list ms-errs">
                <thead><tr><th><?php echo esc_html(__('Error', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Where', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Page', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Browsers', 'magesentinel-monitor')); ?></th><th class="ms-num"><?php echo esc_html(__('24 h', 'magesentinel-monitor')); ?></th><th class="ms-num"><?php echo esc_html(__('14 days', 'magesentinel-monitor')); ?></th><th class="ms-num"><?php echo esc_html(__('Last seen', 'magesentinel-monitor')); ?></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($errors, 0, 20) as $e) :
                    $load = strpos((string) $e['m'], 'Script failed to load') === 0;
                    $tone = $e['n24'] >= 3 ? 'bad' : ($e['n24'] > 0 ? 'watch' : ''); ?>
                    <tr class="<?php echo $tone ? 'ms-row-' . esc_attr($tone) : 'ms-row-old'; ?>">
                        <td><div class="ms-err"><span class="ms-dot<?php echo $tone ? ' ms-' . esc_attr($tone) : ''; ?>" aria-hidden="true"></span><div><?php if ($load) : ?><?php echo wp_kses(self::chip(__('Script failed to load', 'magesentinel-monitor'), 'bad'), self::allowed_html()); ?><?php endif; ?><code><?php echo esc_html($load ? (string) (wp_parse_url((string) $e['s'], PHP_URL_HOST) ?: $e['s']) : $e['m']); ?></code></div></div></td>
                        <td><?php if ($e['s'] !== '') : ?><span class="ms-where" title="<?php echo esc_attr($e['s']); ?>"><?php echo wp_kses(self::icon('file', 13), self::allowed_html()); ?><?php echo esc_html(MageSentinel_Monitor_Js_Errors::where($e)); ?></span><?php else : ?><span class="ms-faint">—</span><?php endif; ?></td>
                        <td><span class="ms-chip"><?php echo wp_kses(self::icon(strpos($e['pages'], 'checkout') !== false ? 'card' : 'cart', 12), self::allowed_html()); ?><?php echo esc_html($e['pages']); ?></span></td>
                        <td class="ms-muted"><?php echo esc_html($e['browsers']); ?></td>
                        <td class="ms-num"><?php if ($e['n24'] > 0) : ?><?php echo wp_kses(self::chip(number_format_i18n($e['n24']), $tone), self::allowed_html()); ?><?php else : ?><span class="ms-faint">0</span><?php endif; ?></td>
                        <td class="ms-num"><b><?php echo esc_html(number_format_i18n($e['n'])); ?></b></td>
                        <td class="ms-num ms-muted"><?php echo esc_html(sprintf(/* translators: %s: time */ __('%s ago', 'magesentinel-monitor'), human_time_diff($e['last'], time()))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="ms-foot"><span><?php echo esc_html(__('Errors from browser extensions are left out. A script that fails to load (a payment or tracking script) shows as "Script failed to load".', 'magesentinel-monitor')); ?></span></div>
        </section>
        <?php
    }

    /** Failed logins, the names bots try and where they come from; recent administrator logins. */
    private static function logins_section(): void
    {
        $day = MageSentinel_Monitor_Security_Watch::login_totals(24);
        if (!$day['since']) return;
        $week = MageSentinel_Monitor_Security_Watch::login_totals(MageSentinel_Monitor_Security_Watch::HOURS);
        ?>
        <section id="ms-logins" data-ms-tab="security" class="ms-card ms-appear" style="--d:7">
            <?php self::sec_head('lock', __('Login activity, 7 days', 'magesentinel-monitor'), esc_html(__('addresses masked to their network', 'magesentinel-monitor'))); ?>
            <div class="ms-cols3">
                <div class="ms-sub"><h3><?php echo wp_kses(self::icon('eye', 14), self::allowed_html()); ?><?php echo esc_html(__('Most tried usernames', 'magesentinel-monitor')); ?></h3>
                    <?php self::bar_list(array_slice($week['u'], 0, 6, true), __('No failed logins', 'magesentinel-monitor')); ?></div>
                <div class="ms-sub"><h3><?php echo wp_kses(self::icon('globe', 14), self::allowed_html()); ?><?php echo esc_html(__('Networks with most failed logins', 'magesentinel-monitor')); ?></h3>
                    <?php self::bar_list(array_slice($week['ip'], 0, 6, true), __('No failed logins', 'magesentinel-monitor')); ?></div>
                <div class="ms-sub"><h3><?php echo wp_kses(self::icon('shield', 14), self::allowed_html()); ?><?php echo esc_html(__('Recent administrator logins', 'magesentinel-monitor')); ?></h3>
                    <?php if (!$week['admins']) : ?><p class="ms-empty"><?php echo esc_html(__('None this week', 'magesentinel-monitor')); ?></p><?php else : ?>
                    <ul class="ms-people">
                    <?php foreach (array_slice($week['admins'], 0, 6) as $a) : $u = (string) $a['user']; ?>
                        <li><span class="ms-avatar" aria-hidden="true"><?php echo esc_html(strtoupper(mb_substr($u, 0, 1))); ?></span><span class="ms-people-t"><b><?php echo esc_html($u); ?></b><code><?php echo esc_html((string) $a['ip']); ?></code></span><span class="ms-muted"><?php echo esc_html(sprintf(/* translators: %s: time */ __('%s ago', 'magesentinel-monitor'), human_time_diff((int) $a['t'], time()))); ?></span></li>
                    <?php endforeach; ?>
                    </ul><?php endif; ?></div>
            </div>
        </section>
        <?php
    }

    public static function overview_page(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        $f = MageSentinel_Monitor::facts();
        $sales = MageSentinel_Monitor::sales();
        $catalog = MageSentinel_Monitor::catalog();
        $unbuyable = MageSentinel_Monitor::unbuyable();
        $byMethod = MageSentinel_Monitor::payments_by_method();
        $visits = MageSentinel_Monitor::checkout_visits();
        $paid7 = array_sum(array_column($byMethod, 'paid'));
        $updates = MageSentinel_Monitor::updates();
        $cache = MageSentinel_Monitor::cache();
        $changes = MageSentinel_Monitor::setting_changes();
        $a = MageSentinel_Monitor::activity();
        $conn = self::connection();
        $rows = self::sorted_health();
        $v = self::verdict($rows);

        $today = (int) ($sales['total_24h'] ?? 0);
        $delta = $today - (int) ($sales['same_window_last_week'] ?? 0);
        $revenue = (float) ($sales['revenue_24h'] ?? 0);
        $currency = (string) ($sales['currency'] ?? get_woocommerce_currency());
        $failed = (int) $f['failed_orders_24h'];
        $pending = (int) $f['pending_payment_24h'];
        $tests = $a['orders'];
        $cancelled = count(array_filter($tests, static function ($o) { return !empty($o['cancelled_at']); }));
        $lastTest = $tests ? max(array_column($tests, 'placed_at')) : 0;
        $week = array_values($sales['last_7d'] ?? []);
        $slots = self::hour_slots($sales['last_24h'] ?? []);
        $peakSlot = $today > 0 ? array_reduce($slots, static function ($c, $s) { return $c === null || $s['count'] > $c['count'] ? $s : $c; }) : null;
        $pluginUpdates = $updates['plugins'];
        $updateCount = count($pluginUpdates) + ($updates['wordpress_update'] ? 1 : 0);
        $published = (int) $catalog['published'];
        $out = (int) $catalog['out_of_stock'];
        $money = static function (float $n, int $dec = 2) use ($currency): string {
            return html_entity_decode(wp_strip_all_tags(wc_price($n, ['currency' => $currency, 'decimals' => $dec])));
        };
        ?>
        <div class="wrap ms-app" data-ms-theme="<?php echo esc_attr(self::theme()); ?>" data-ms-active="overview">
            <?php self::header('overview'); ?>
            <?php self::review_notice($v); ?>
            <?php self::overview_tabs($rows); ?>

            <section data-ms-tab="overview" class="ms-card ms-verdict ms-appear" style="--d:1" aria-live="polite" data-ms-verdict>
                <?php echo wp_kses(self::ring($v['pct']), self::allowed_html()); ?>
                <div>
                    <h2><?php echo esc_html($v['title']); ?></h2>
                    <p><?php echo esc_html($v['sub']); ?></p>
                </div>
                <div class="ms-meta">
                    <?php if ($conn['state'] === 'off') : $selfCheck = MageSentinel_Monitor_Self_Check::state(); $alerts = self::alerts_status(); ?>
                        <div><span><?php echo esc_html(__('Self-check', 'magesentinel-monitor')); ?></span><strong><?php echo esc_html($selfCheck['checked_at'] ? sprintf(/* translators: %s: value */ __('%s ago', 'magesentinel-monitor'), human_time_diff($selfCheck['checked_at'], time())) : __('Within the hour', 'magesentinel-monitor')); ?></strong></div>
                        <div><span><?php echo esc_html(__('Alerts', 'magesentinel-monitor')); ?></span><strong class="ms-alerts-line"><?php $on = MageSentinel_Monitor::settings()['alerts']; ?><a class="ms-mini-toggle<?php echo $on ? ' is-on' : ''; ?>" role="switch" aria-checked="<?php echo $on ? 'true' : 'false'; ?>" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=magesentinel_alerts_toggle'), 'magesentinel_alerts_toggle')); ?>"><span class="ms-switch" aria-hidden="true"></span><?php echo esc_html($on ? __('On', 'magesentinel-monitor') : __('Off', 'magesentinel-monitor')); ?></a></strong></div>
                    <?php else : ?>
                        <div><span><?php echo esc_html(__('Last MageSentinel check', 'magesentinel-monitor')); ?></span><strong><?php echo esc_html($a['last_seen'] ? sprintf(/* translators: %s: value */ __('%s ago', 'magesentinel-monitor'), human_time_diff($a['last_seen'], time())) : '—'); ?></strong></div>
                        <div><span><?php echo esc_html(__('Last test purchase', 'magesentinel-monitor')); ?></span><strong><?php echo esc_html($lastTest ? sprintf(/* translators: %s: value */ __('%s ago', 'magesentinel-monitor'), human_time_diff((int) $lastTest, time())) : '—'); ?></strong></div>
                    <?php endif; ?>
                    <?php self::test_button($conn); ?>
                    <a class="ms-btn ms-btn-ghost" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=magesentinel_recheck'), 'magesentinel_recheck')); ?>" data-ms-recheck><?php echo wp_kses(self::icon('refresh', 15), self::allowed_html()); ?><?php echo esc_html(__('Check again', 'magesentinel-monitor')); ?></a>
                </div>
                <?php echo wp_kses(self::strip($rows), self::allowed_html()); ?>
                <?php self::test_box($conn); ?>
                <?php if ($conn['state'] === 'off') : ?>
                    <div class="ms-test ms-preview" data-ms-preview>
                        <span class="ms-test-title"><?php echo esc_html(__('What MageSentinel tests every hour', 'magesentinel-monitor')); ?></span>
                        <ol class="ms-flow" aria-hidden="true">
                            <?php foreach (['Store', 'Product', 'Cart', 'Checkout', 'Customer', 'Order', 'Confirmed', 'Cancelled'] as $st) : ?>
                                <li class="ms-flow-step ms-f-pending"><span class="ms-flow-node"></span><span class="ms-flow-label"><?php echo esc_html(self::flow($st)); ?></span><span class="ms-flow-time"></span></li>
                            <?php endforeach; ?>
                        </ol>
                        <p class="ms-test-line"><?php echo esc_html(__('The checks above read settings and counts from inside the site. MageSentinel also buys from outside, like a shopper: every step of a real purchase, every hour, and the test order is cancelled.', 'magesentinel-monitor')); ?> <a href="<?php echo esc_url(MageSentinel_Monitor_Self_Check::SIGNUP_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Try it 14 days free', 'magesentinel-monitor')); ?> →</a></p>
                    </div>
                <?php endif; ?>
                <div class="ms-legend"><span><i class="ms-good"></i><?php echo esc_html(__('Healthy', 'magesentinel-monitor')); ?></span><span><i class="ms-watch"></i><?php echo esc_html(__('Needs attention', 'magesentinel-monitor')); ?></span><span><i class="ms-bad"></i><?php echo esc_html(__('Problem', 'magesentinel-monitor')); ?></span></div>
            </section>

            <section data-ms-tab="overview" class="ms-kpis" aria-label="<?php echo esc_attr(__('last 24 hours', 'magesentinel-monitor')); ?>">
                <div class="ms-card ms-kpi ms-appear" style="--d:2">
                    <span class="ms-kpi-l"><?php echo wp_kses(self::icon('cart', 16), self::allowed_html()); ?><?php echo esc_html(__('Orders today', 'magesentinel-monitor')); ?></span>
                    <span class="ms-kpi-v" data-ms-count="<?php echo esc_attr((string) $today); ?>"><?php echo esc_html(number_format_i18n($today)); ?></span>
                    <span class="ms-kpi-d"><?php if ($delta !== 0) : ?><span class="<?php echo $delta > 0 ? 'ms-up' : 'ms-down'; ?>"><?php echo esc_html(($delta > 0 ? '+' : '−') . number_format_i18n(abs($delta))); ?></span><?php echo esc_html(__('vs the same hours a week ago', 'magesentinel-monitor')); ?><?php else : ?><?php echo esc_html(__('Same as the same hours a week ago', 'magesentinel-monitor')); ?><?php endif; ?></span>
                </div>
                <div class="ms-card ms-kpi ms-appear" style="--d:3">
                    <span class="ms-kpi-l"><?php echo wp_kses(self::icon('receipt', 16), self::allowed_html()); ?><?php echo esc_html(__('Revenue today', 'magesentinel-monitor')); ?></span>
                    <span class="ms-kpi-v"><?php echo esc_html($money($revenue)); ?></span>
                    <span class="ms-kpi-d"><?php echo esc_html($today > 0 ? sprintf(/* translators: %s: value */ __('average order %s', 'magesentinel-monitor'), $money($revenue / $today)) : __('last 24 hours', 'magesentinel-monitor')); ?></span>
                </div>
                <div class="ms-card ms-kpi ms-appear" style="--d:4">
                    <span class="ms-kpi-l"><?php echo wp_kses(self::icon('card', 16), self::allowed_html()); ?><?php echo esc_html(__('Failed payments', 'magesentinel-monitor')); ?></span>
                    <span class="ms-kpi-v" data-ms-count="<?php echo esc_attr((string) $failed); ?>"><?php echo esc_html(number_format_i18n($failed)); ?></span>
                    <span class="ms-kpi-d"><span class="ms-chip<?php echo $pending >= 5 ? ' ms-watch' : ''; ?>"><?php echo esc_html(sprintf(/* translators: %s: value */ __('%d pending payment', 'magesentinel-monitor'), $pending)); ?></span></span>
                </div>
                <div class="ms-card ms-kpi ms-appear" style="--d:5">
                    <span class="ms-kpi-l"><?php echo wp_kses(self::icon('eye', 16), self::allowed_html()); ?><?php echo esc_html(__('Test purchases', 'magesentinel-monitor')); ?></span>
                    <span class="ms-kpi-v" data-ms-count="<?php echo esc_attr((string) count($tests)); ?>"><?php echo esc_html(number_format_i18n(count($tests))); ?></span>
                    <span class="ms-kpi-d"><?php if ($tests) : ?><span class="ms-chip <?php echo $cancelled === count($tests) ? 'ms-good' : 'ms-watch'; ?>"><?php echo esc_html($cancelled === count($tests) ? __('all cancelled', 'magesentinel-monitor') : sprintf(/* translators: %s: value */ __('%d cancelled', 'magesentinel-monitor'), $cancelled)); ?></span><?php endif; ?><?php echo esc_html(__('not counted in the numbers', 'magesentinel-monitor')); ?></span>
                </div>
            </section>

            <section data-ms-tab="overview" class="ms-g2">
                <div class="ms-card ms-appear" style="--d:6">
                    <div class="ms-head"><h2><?php echo esc_html(__('Orders, last 7 days', 'magesentinel-monitor')); ?></h2><small><?php echo esc_html(__('total', 'magesentinel-monitor')); ?> <b><?php echo esc_html(number_format_i18n(array_sum(array_column($week, 'count')))); ?></b></small></div>
                    <?php echo wp_kses(self::week_chart($week), self::allowed_html()); ?>
                    <div class="ms-foot"><span><?php echo esc_html(__('MageSentinel test orders are not counted.', 'magesentinel-monitor')); ?></span><?php echo wp_kses(self::table_view(array_map(static function ($d) { return [date_i18n('D j M', strtotime($d['day'] . ' 12:00:00')), (int) $d['count']]; }, $week)), self::allowed_html()); ?></div>
                </div>
                <div class="ms-card ms-appear" style="--d:7">
                    <div class="ms-head"><h2><?php echo esc_html(__('Orders by hour, last 24 hours', 'magesentinel-monitor')); ?></h2><?php if ($peakSlot) : ?><small><?php echo esc_html(__('peak', 'magesentinel-monitor')); ?> <b><?php echo esc_html($peakSlot['short']); ?></b></small><?php endif; ?></div>
                    <?php echo wp_kses(self::day_chart($slots), self::allowed_html()); ?>
                    <div class="ms-foot"><span><?php echo esc_html($today > 0 ? sprintf(/* translators: %s: value */ __('Store time (%s).', 'magesentinel-monitor'), wp_timezone_string()) : __('No orders in the last 24 hours.', 'magesentinel-monitor')); ?></span><?php echo wp_kses(self::table_view(array_map(static function ($s) { return [$s['label'], $s['count']]; }, $slots)), self::allowed_html()); ?></div>
                </div>
            </section>

            <section data-ms-tab="health" class="ms-card ms-appear" style="--d:8">
                <div class="ms-head"><h2><?php echo esc_html(__('Health checks', 'magesentinel-monitor')); ?></h2><small><?php echo esc_html(__('checked just now', 'magesentinel-monitor')); ?></small></div>
                <div class="ms-groups" data-ms-checks>
                    <?php foreach (self::check_groups($rows) as $g) : ?>
                        <div class="ms-group ms-<?php echo esc_attr($g['worst']); ?>">
                            <div class="ms-group-h">
                                <span class="ms-group-i"><?php echo wp_kses(self::icon($g['icon'], 18), self::allowed_html()); ?></span>
                                <h3><?php echo esc_html($g['title']); ?></h3>
                                <span class="ms-group-n"><b><?php echo esc_html((string) $g['good']); ?></b>/<?php echo esc_html((string) count($g['rows'])); ?></span>
                            </div>
                            <div class="ms-group-bar" aria-hidden="true">
                                <?php foreach ($g['rows'] as $row) : ?><i class="ms-<?php echo esc_attr($row['state']); ?>"></i><?php endforeach; ?>
                            </div>
                            <div class="ms-group-rows">
                                <?php foreach ($g['rows'] as $row) : ?>
                                    <?php $zz = !empty($row['snoozed']); ?>
                                    <div class="ms-check ms-<?php echo esc_attr($row['state']); ?><?php echo $zz ? ' ms-snoozed' : ''; ?>" data-s="<?php echo esc_attr($row['state']); ?>" data-i="<?php echo esc_attr((string) $row['i']); ?>"<?php echo ($row['state'] === 'good' && !$zz) ? ' title="' . esc_attr($row['summary']) . '"' : ''; ?>>
                                        <span class="ms-ci"><?php echo wp_kses(self::icon($row['icon'], 15), self::allowed_html()); ?></span>
                                        <div class="ms-cb">
                                            <h4><?php echo esc_html($row['label']); ?><?php if ($zz) : ?> <span class="ms-chip"><?php echo esc_html(__('Snoozed', 'magesentinel-monitor')); ?></span><?php endif; ?></h4>
                                            <p><?php echo esc_html($row['summary']); ?></p>
                                            <?php if (!empty($row['link']) && ($row['state'] !== 'good' || $zz)) : ?><a class="ms-fix" href="<?php echo esc_url($row['link']); ?>"<?php echo strpos($row['link'], admin_url()) === 0 ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html($row['link_text'] ?? __('Review', 'magesentinel-monitor')); ?> →</a><?php endif; ?>
                                            <?php if ($row['state'] !== 'good' || $zz) : ?><a class="ms-snooze" href="<?php echo esc_url(self::snooze_url($row, !$zz)); ?>" title="<?php echo esc_attr($zz ? __('Count this check again and alert when it fails.', 'magesentinel-monitor') : __('Stop counting this check and alerting about it. It is still checked: it wakes up when fixed, when it gets worse, or after 30 days.', 'magesentinel-monitor')); ?>"><?php echo esc_html($zz ? __('Wake up', 'magesentinel-monitor') : __('Snooze', 'magesentinel-monitor')); ?></a><?php endif; ?>
                                        </div>
                                        <span class="ms-st ms-<?php echo esc_attr($row['state']); ?>"><?php echo wp_kses(self::state_icon($row['state']), self::allowed_html()); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php self::security_kpis(); ?>
            <?php self::js_errors_section(); ?>
            <?php self::logins_section(); ?>

            <section data-ms-tab="store" class="ms-g3">
                <div class="ms-card ms-appear" style="--d:9">
                    <div class="ms-head"><h2><?php echo esc_html(__('Catalog', 'magesentinel-monitor')); ?></h2><?php echo wp_kses($out > 0 ? '<span class="ms-chip ms-watch">' . esc_html(sprintf(/* translators: %s: value */ __('%d out of stock', 'magesentinel-monitor'), $out)) . '</span>' : '<span class="ms-chip ms-good">' . esc_html(__('all in stock', 'magesentinel-monitor')) . '</span>', self::allowed_html()); ?></div>
                    <div class="ms-bar" aria-hidden="true"><i style="width:<?php echo esc_attr((string) ($published ? min(100, round(100 * $out / $published, 1)) : 0)); ?>%"></i></div>
                    <dl class="ms-facts">
                        <div><dt><?php echo esc_html(__('Published products', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html(number_format_i18n($published)); ?></dd></div>
                        <div><dt><?php echo esc_html(__('Out of stock', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html(number_format_i18n($out)); ?></dd></div>
                        <div><dt><?php echo esc_html(sprintf(/* translators: %s: value */ __('Low stock (≤ %d)', 'magesentinel-monitor'), (int) $catalog['low_stock_threshold'])); ?></dt><dd><?php echo esc_html(number_format_i18n((int) $catalog['low_stock'])); ?></dd></div>
                        <div><dt><?php echo esc_html(__('On backorder', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html(number_format_i18n((int) $catalog['on_backorder'])); ?></dd></div>
                        <div><dt><?php echo esc_html(__('No price (cannot be bought)', 'magesentinel-monitor')); ?></dt><dd><?php echo wp_kses($unbuyable['count'] > 0 ? '<span class="ms-chip ms-watch">' . esc_html($unbuyable['count'] > 200 ? '200+' : number_format_i18n($unbuyable['count'])) . '</span>' : esc_html('0'), self::allowed_html()); ?></dd></div>
                    </dl>
                </div>
                <div class="ms-card ms-appear" style="--d:10">
                    <div class="ms-head"><h2><?php echo esc_html(__('Updates', 'magesentinel-monitor')); ?></h2><?php echo wp_kses($updateCount > 0 ? '<span class="ms-chip ms-watch">' . esc_html(sprintf(/* translators: %s: value */ __('%d available', 'magesentinel-monitor'), $updateCount)) . '</span>' : '<span class="ms-chip ms-good">' . esc_html(__('up to date', 'magesentinel-monitor')) . '</span>', self::allowed_html()); ?></div>
                    <dl class="ms-facts">
                        <div><dt>WordPress</dt><dd><?php echo wp_kses($updates['wordpress_update'] ? esc_html($updates['wordpress'] . ' → ' . $updates['wordpress_update']) : esc_html($updates['wordpress']) . ' <span class="ms-chip ms-good">' . esc_html(__('latest', 'magesentinel-monitor')) . '</span>', self::allowed_html()); ?></dd></div>
                        <?php foreach (array_slice($pluginUpdates, 0, 4) as $p) : ?>
                            <div><dt><?php echo esc_html($p['name']); ?></dt><dd><?php echo esc_html($p['version'] . ' → ' . ($p['new_version'] ?? '?')); ?></dd></div>
                        <?php endforeach; ?>
                        <div><dt><?php echo esc_html(__('Active plugins', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html(number_format_i18n(count((array) get_option('active_plugins', [])))); ?></dd></div>
                    </dl>
                    <p class="ms-note"><?php echo $updateCount > 0 ? '<a href="' . esc_url(admin_url('update-core.php')) . '">' . esc_html(__('Review', 'magesentinel-monitor')) . ' →</a> ' : ''; ?><?php echo esc_html(__('After updating, run a check from MageSentinel so a test purchase confirms checkout still works.', 'magesentinel-monitor')); ?></p>
                </div>
                <div class="ms-card ms-appear" style="--d:11">
                    <div class="ms-head"><h2><?php echo esc_html(__('Performance & security', 'magesentinel-monitor')); ?></h2></div>
                    <dl class="ms-facts">
                        <div><dt><?php echo esc_html(__('Page cache', 'magesentinel-monitor')); ?></dt><dd><?php echo wp_kses($cache['page_cache'] ? '<span class="ms-chip ms-good">' . esc_html($cache['page_cache']) . '</span>' : '<span class="ms-chip">' . esc_html(__('None detected', 'magesentinel-monitor')) . '</span>', self::allowed_html()); ?></dd></div>
                        <div><dt><?php echo esc_html(__('Object cache', 'magesentinel-monitor')); ?></dt><dd><span class="ms-chip<?php echo $cache['object_cache'] ? ' ms-good' : ''; ?>"><?php echo esc_html($cache['object_cache'] ? __('Persistent', 'magesentinel-monitor') : __('Database only', 'magesentinel-monitor')); ?></span></dd></div>
                        <div><dt>HTTPS</dt><dd><span class="ms-chip <?php echo $cache['https'] ? 'ms-good' : 'ms-bad'; ?>"><?php echo esc_html($cache['https'] ? __('on', 'magesentinel-monitor') : __('off', 'magesentinel-monitor')); ?></span></dd></div>
                        <div><dt><?php echo esc_html(__('Errors on screen', 'magesentinel-monitor')); ?></dt><dd><span class="ms-chip <?php echo $cache['debug_display'] ? 'ms-watch' : 'ms-good'; ?>"><?php echo esc_html($cache['debug_display'] ? __('Shown to visitors', 'magesentinel-monitor') : __('Hidden', 'magesentinel-monitor')); ?></span></dd></div>
                        <?php $web = $cache['https'] ? MageSentinel_Monitor::web_security() : null; if ($web) : $have = count(array_filter($web['headers'])); ?>
                            <div><dt><?php echo esc_html(__('Security headers', 'magesentinel-monitor')); ?></dt><dd><span class="ms-chip <?php echo $have === count($web['headers']) ? 'ms-good' : ($have === 0 ? 'ms-watch' : ''); ?>" title="<?php echo esc_attr(implode("\n", array_map(static function ($k, $v) { return ($v ? '✓ ' : '✗ ') . $k; }, array_keys($web['headers']), $web['headers']))); ?>"><?php echo esc_html(sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$d of %2$d', 'magesentinel-monitor'), $have, count($web['headers']))); ?></span></dd></div>
                        <?php endif; ?>
                        <div><dt>PHP</dt><dd><?php echo esc_html(PHP_VERSION); ?></dd></div>
                    </dl>
                </div>
            </section>

            <section data-ms-tab="store" class="ms-g2">
                <div class="ms-card ms-appear" style="--d:12">
                    <div class="ms-head"><h2><?php echo esc_html(__('Payments by method', 'magesentinel-monitor')); ?></h2><small><?php echo esc_html(__('last 7 days', 'magesentinel-monitor')); ?></small></div>
                    <?php if (!$byMethod) : ?>
                        <p class="ms-empty"><?php echo esc_html(__('No payment method is enabled.', 'magesentinel-monitor')); ?></p>
                    <?php else : ?>
                        <div class="ms-twrap"><table class="ms-orders ms-pay">
                            <thead><tr><th><?php echo esc_html(__('Method', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Paid', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Failed', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Unpaid', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Success', 'magesentinel-monitor')); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($byMethod as $m) : $tries = $m['paid'] + $m['failed']; $rate = $tries ? (int) round(100 * $m['paid'] / $tries) : null; $tone = $rate === null ? '' : ($rate >= 90 ? 'ms-good' : ($rate >= 70 ? 'ms-watch' : 'ms-bad')); ?>
                                <tr>
                                    <td><?php echo esc_html($m['title']); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($m['paid'])); ?></td>
                                    <td<?php echo $m['failed'] ? ' class="ms-bad-t"' : ''; ?>><?php echo esc_html(number_format_i18n($m['failed'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($m['pending'])); ?></td>
                                    <td><?php if ($rate === null) : ?><span class="ms-muted">—</span><?php else : ?><span class="ms-rate"><span class="ms-rate-bar"><i class="<?php echo esc_attr($tone); ?>" style="width:<?php echo esc_attr((string) $rate); ?>%"></i></span><b><?php echo esc_html($rate . '%'); ?></b></span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                        <p class="ms-note"><?php echo esc_html(__('Unpaid: orders created and never paid, what a payment page that fails to load leaves behind. MageSentinel test orders are not counted.', 'magesentinel-monitor')); ?></p>
                    <?php endif; ?>
                </div>
                <div class="ms-card ms-appear" style="--d:13">
                    <div class="ms-head"><h2><?php echo esc_html(__('Checkout, last 7 days', 'magesentinel-monitor')); ?></h2></div>
                    <?php
                    $started = max($visits['started'], $paid7);
                    $left = max(0, $started - $paid7);
                    $conv = $started ? (int) round(100 * $paid7 / $started) : null;
                    $counting = $visits['since'] === 0 || time() - $visits['since'] < 7 * DAY_IN_SECONDS;
                    ?>
                    <div class="ms-funnel">
                        <div><span><?php echo esc_html(__('Reached checkout', 'magesentinel-monitor')); ?></span><b><?php echo esc_html(number_format_i18n($started)); ?></b><i style="width:100%"></i></div>
                        <div><span><?php echo esc_html(__('Paid', 'magesentinel-monitor')); ?></span><b><?php echo esc_html(number_format_i18n($paid7)); ?></b><i class="ms-good" style="width:<?php echo esc_attr((string) ($paid7 ? max(2, round(100 * $paid7 / $started)) : 0)); ?>%"></i></div>
                        <div><span><?php echo esc_html(__('Left without paying', 'magesentinel-monitor')); ?></span><b><?php echo esc_html(number_format_i18n($left)); ?></b><i class="ms-watch" style="width:<?php echo esc_attr((string) ($started ? round(100 * $left / $started) : 0)); ?>%"></i></div>
                    </div>
                    <p class="ms-note"><?php
                        echo esc_html($conv === null ? __('Nobody has reached checkout yet.', 'magesentinel-monitor') : sprintf(/* translators: %s: value */ __('%d%% of shoppers who reached checkout paid.', 'magesentinel-monitor'), $conv));
                        echo ' ';
                        echo esc_html($counting
                            ? ($visits['since'] ? sprintf(/* translators: %s: value */ __('Counting since %s, so the first week is partial.', 'magesentinel-monitor'), date_i18n(get_option('date_format'), $visits['since'])) : __('Counting starts with the next shopper who reaches checkout.', 'magesentinel-monitor'))
                            : __('Counts only: no shopper details are kept.', 'magesentinel-monitor'));
                    ?></p>
                </div>
            </section>

            <?php
            $rev = MageSentinel_Monitor::revenue_weeks();
            $revThis = array_sum($rev['this']);
            $revLast = array_sum($rev['last']);
            $revPct = $revLast > 0 ? (int) round(($revThis - $revLast) / $revLast * 100) : null;
            $speed = self::speed_runs($conn);
            $speedMs = array_values(array_filter(array_map(static function ($r) { return (int) ($r['ms'] ?? 0); }, $speed)));
            $speedMed = 0;
            if ($speedMs) { $sorted = $speedMs; sort($sorted); $mid = intdiv(count($sorted), 2); $speedMed = count($sorted) % 2 ? $sorted[$mid] : (int) (($sorted[$mid - 1] + $sorted[$mid]) / 2); }
            $speedScale = max(20000, $speedMs ? (int) (ceil(max($speedMs) / 10000) * 10000) : 20000);
            $lastSpeed = $speed ? end($speed) : null;
            $hdays = MageSentinel_Monitor::health_days();
            $oos = MageSentinel_Monitor::selling_out_of_stock();
            ?>
            <section data-ms-tab="overview" class="ms-g2">
                <div class="ms-card ms-appear" style="--d:10">
                    <div class="ms-head"><h2><?php echo esc_html(__('Revenue this week vs last week', 'magesentinel-monitor')); ?></h2><small><b><?php echo esc_html($money($revThis, 0)); ?></b><?php if ($revPct !== null) : ?> <span class="<?php echo $revPct >= 0 ? 'ms-up' : 'ms-down'; ?>"><?php echo esc_html(($revPct >= 0 ? '+' : '−') . abs($revPct) . '%'); ?></span><?php endif; ?></small></div>
                    <?php echo wp_kses(self::revenue_chart($rev, $money), self::allowed_html()); ?>
                    <div class="ms-foot"><span class="ms-lg"><i class="ms-lg-this"></i><?php echo esc_html(__('This week', 'magesentinel-monitor')); ?> <i class="ms-lg-last"></i><?php echo esc_html(sprintf(/* translators: %s: amount */ __('Last week %s', 'magesentinel-monitor'), $money($revLast, 0))); ?></span></div>
                </div>
                <div class="ms-card ms-appear" style="--d:11">
                    <div class="ms-head"><h2><?php echo esc_html(__('Checkout speed', 'magesentinel-monitor')); ?></h2><small><?php echo esc_html($conn['state'] === 'off' ? __('end to end, your free monthly tests', 'magesentinel-monitor') : __('end to end, last 30 test purchases', 'magesentinel-monitor')); ?></small></div>
                    <?php if (!$speedMs) : ?>
                        <p class="ms-empty"><?php echo esc_html($conn['state'] === 'off' ? __('No test purchase yet. Run the free one above to see how long your checkout takes, from the home page to the last click before paying.', 'magesentinel-monitor') : __('No checks yet. Times appear here after MageSentinel\'s first test purchase.', 'magesentinel-monitor')); ?></p>
                    <?php else : ?>
                        <div class="ms-speed-kpis">
                            <div><span><?php echo esc_html(__('Last run', 'magesentinel-monitor')); ?></span><strong><?php echo esc_html(self::secs((int) ($lastSpeed['ms'] ?? 0))); ?></strong></div>
                            <div><span><?php echo esc_html(sprintf(/* translators: %d: number of runs */ __('Median, last %d', 'magesentinel-monitor'), count($speedMs))); ?></span><strong><?php echo esc_html(self::secs($speedMed)); ?></strong></div>
                        </div>
                        <div class="ms-speed" style="--med:<?php echo esc_attr((string) round(100 * $speedMed / $speedScale, 1)); ?>%;--slow:<?php echo esc_attr((string) min(100, round(130 * $speedMed / $speedScale, 1))); ?>%">
                            <span class="ms-speed-slow"><b><?php echo esc_html(__('slow', 'magesentinel-monitor')); ?></b></span>
                            <span class="ms-speed-med"><b><?php echo esc_html(self::secs($speedMed)); ?></b></span>
                            <div class="ms-speed-bars">
                                <?php foreach (array_pad($speed, -30, null) as $r) :
                                    if ($r === null) { echo '<i class="ms-sp-none"></i>'; continue; }
                                    $ms = (int) ($r['ms'] ?? 0);
                                    $cls = ($r['status'] ?? '') === 'FAIL' ? 'ms-sp-fail' : ($speedMed > 0 && $ms > 1.3 * $speedMed ? 'ms-sp-slow' : '');
                                    $when = strtotime((string) ($r['at'] ?? '')) ?: time();
                                    ?><i class="<?php echo esc_attr($cls); ?>" style="height:<?php echo esc_attr((string) max(3, min(100, round(100 * $ms / $speedScale, 1)))); ?>%" title="<?php echo esc_attr(self::secs($ms) . ' · ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $when)); ?>"></i><?php
                                endforeach; ?>
                            </div>
                        </div>
                        <div class="ms-foot"><span class="ms-lg"><i class="ms-lg-pass"></i><?php echo esc_html(__('passed', 'magesentinel-monitor')); ?> <i class="ms-lg-slow"></i><?php echo esc_html(__('slower than usual', 'magesentinel-monitor')); ?> <i class="ms-lg-fail"></i><?php echo esc_html(__('problem', 'magesentinel-monitor')); ?></span><span><?php echo esc_html(sprintf(/* translators: %s: seconds */ __('Scale %s; dashed line = median, above the dotted line = slow.', 'magesentinel-monitor'), self::secs($speedScale))); ?></span></div>
                    <?php endif; ?>
                    <?php if ($conn['state'] === 'off') : ?>
                        <p class="ms-note"><?php echo esc_html(__('One point a month without an account. Connected, MageSentinel measures it on every check and tells you the moment it slows down.', 'magesentinel-monitor')); ?> <a href="<?php echo esc_url(MageSentinel_Monitor_Self_Check::SIGNUP_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Try it 14 days free', 'magesentinel-monitor')); ?> →</a></p>
                    <?php endif; ?>
                </div>
            </section>

            <section data-ms-tab="health" class="ms-g2">
                <div class="ms-card ms-appear" style="--d:12">
                    <?php $hn = array_sum(array_column($hdays, 'n')); $hclean = array_sum(array_column($hdays, 'clean')); ?>
                    <div class="ms-head"><h2><?php echo esc_html(__('Health over 7 days', 'magesentinel-monitor')); ?></h2><?php if ($hn) : ?><small><b><?php echo esc_html(number_format_i18n(100 * $hclean / $hn, 1)); ?>%</b> <?php echo esc_html(__('of checks without a problem', 'magesentinel-monitor')); ?></small><?php endif; ?></div>
                    <div class="ms-heat">
                        <div class="ms-heat-y" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></div>
                        <?php foreach ($hdays as $i => $d) :
                            $st = $d['state'];
                            $label = $st === null ? __('No check', 'magesentinel-monitor') : ($st === 'bad' ? sprintf(/* translators: %d: number of problems */ _n('%d problem', '%d problems', $d['bad'], 'magesentinel-monitor'), $d['bad']) : ($st === 'watch' ? __('Needs attention', 'magesentinel-monitor') : __('Healthy', 'magesentinel-monitor')));
                            if ($d['what']) $label .= ': ' . implode(', ', $d['what']);
                            $hours = $d['h'] !== '' ? $d['h'] : str_repeat('-', 24);
                            $pct = $d['n'] ? (int) floor(100 * $d['clean'] / $d['n']) : null;
                            $pctTone = $pct === null ? ($st ?? 'none') : ($pct >= 99 ? 'good' : ($pct >= 90 ? 'watch' : 'bad'));
                            $short = $st === null ? '–' : ($st === 'bad' ? sprintf(/* translators: %d: number of problems */ _n('%d problem', '%d problems', $d['bad'], 'magesentinel-monitor'), $d['bad']) : ($st === 'watch' ? '!' : '✓'));
                            ?>
                            <div class="ms-heat-col" title="<?php echo esc_attr(date_i18n('D j M', strtotime($d['day'] . ' 12:00:00')) . ' · ' . $label); ?>">
                                <div class="ms-heat-cells">
                                    <?php for ($h = 0; $h < 24; $h++) : $c = ['o' => 'good', 'w' => 'watch', 'b' => 'bad'][$hours[$h]] ?? 'none'; ?><i class="ms-hc ms-<?php echo esc_attr($c); ?>" title="<?php echo esc_attr(sprintf('%02d:00', $h)); ?>"></i><?php endfor; ?>
                                </div>
                                <span class="ms-heat-pct ms-<?php echo esc_attr($pctTone); ?>"><?php echo esc_html($pct === null ? $short : $pct . '%'); ?></span>
                                <span class="ms-day-name"><?php echo esc_html($i === 6 ? __('Today', 'magesentinel-monitor') : date_i18n('D', strtotime($d['day'] . ' 12:00:00'))); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ms-foot"><span class="ms-lg"><i class="ms-lg-good"></i><?php echo esc_html(__('Healthy', 'magesentinel-monitor')); ?> <i class="ms-lg-watch"></i><?php echo esc_html(__('Needs attention', 'magesentinel-monitor')); ?> <i class="ms-lg-bad"></i><?php echo esc_html(__('Problem', 'magesentinel-monitor')); ?></span><span><?php echo esc_html(__('Each square is one hour; hover a day for what went wrong.', 'magesentinel-monitor')); ?></span></div>
                </div>
                <div class="ms-card ms-appear" style="--d:13">
                    <div class="ms-head"><h2><?php echo esc_html(__('Selling, but out of stock', 'magesentinel-monitor')); ?></h2><small><?php echo esc_html(__('sold in the last 30 days', 'magesentinel-monitor')); ?></small></div>
                    <?php if (!$oos['items']) : ?>
                        <p class="ms-empty"><?php echo esc_html(__('Nothing that sold in the last 30 days has run out.', 'magesentinel-monitor')); ?></p>
                    <?php else : ?>
                        <ul class="ms-oos">
                            <?php foreach ($oos['items'] as $it) : ?>
                                <li><a href="<?php echo esc_url($it['url']); ?>"><?php echo esc_html($it['name']); ?></a><span class="ms-muted"><?php echo esc_html(sprintf(/* translators: %d: quantity */ __('%d sold', 'magesentinel-monitor'), $it['sold'])); ?></span><span class="ms-chip <?php echo $it['left'] === 0 ? 'ms-bad' : 'ms-watch'; ?>"><?php echo esc_html(sprintf(/* translators: %d: quantity */ __('%d left', 'magesentinel-monitor'), $it['left'])); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($oos['more'] > 0) : ?><p class="ms-note"><?php echo esc_html(sprintf(/* translators: %d: number of products */ _n('And %d more product.', 'And %d more products.', $oos['more'], 'magesentinel-monitor'), $oos['more'])); ?></p><?php endif; ?>
                        <?php if ($oos['missed'] > 0) : ?><p class="ms-note ms-note-info"><?php echo esc_html(sprintf(/* translators: %s: amount */ __('Missed sales estimate: ~%s this month at the last 30 days\' pace.', 'magesentinel-monitor'), $money((float) $oos['missed'], 0))); ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section data-ms-tab="store" class="ms-g2 ms-wide">
                <div class="ms-card ms-appear" style="--d:14">
                    <div class="ms-head"><h2><?php echo esc_html(__('Test purchases', 'magesentinel-monitor')); ?></h2><small><?php echo esc_html(__('placed and cancelled automatically', 'magesentinel-monitor')); ?></small></div>
                    <?php if (!$tests && $conn['state'] === 'off') : ?>
                        <div class="ms-upsell">
                            <p><b><?php echo esc_html(__('The checks above read your settings. They cannot tell whether a shopper can actually pay.', 'magesentinel-monitor')); ?></b> <?php echo esc_html(__('MageSentinel buys something in your store from outside every hour, like a real customer, cancels the order, and tells you by email or Slack the moment checkout breaks.', 'magesentinel-monitor')); ?></p>
                            <div class="ms-upsell-actions">
                                <a class="ms-btn ms-btn-primary" href="<?php echo esc_url(MageSentinel_Monitor_Self_Check::SIGNUP_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Start 14 days free', 'magesentinel-monitor')); ?><?php echo wp_kses(self::icon('external', 15), self::allowed_html()); ?></a>
                                <a class="ms-btn ms-btn-ghost" href="<?php echo esc_url(self::pricing_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Plans & pricing', 'magesentinel-monitor')); ?></a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>"><?php echo esc_html(__('Already have an account? Connect it', 'magesentinel-monitor')); ?> →</a>
                            </div>
                        </div>
                    <?php elseif (!$tests) : ?>
                        <p class="ms-empty"><?php echo esc_html(__('None yet. When real test orders are on, each one appears here with what happened to it.', 'magesentinel-monitor')); ?></p>
                    <?php else : ?>
                        <div class="ms-twrap"><table class="ms-orders">
                            <thead><tr><th><?php echo esc_html(__('Order', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('When', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Product', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Payment', 'magesentinel-monitor')); ?></th><th class="ms-num"><?php echo esc_html(__('Total', 'magesentinel-monitor')); ?></th><th><?php echo esc_html(__('Status', 'magesentinel-monitor')); ?></th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($tests, 0, 6) as $o) :
                                $order = function_exists('wc_get_order') ? wc_get_order((int) $o['id']) : null;
                                $items = $order ? $order->get_items() : [];
                                $first = $items ? reset($items) : null;
                                $status = $order ? $order->get_status() : ($o['status'] ?? '');
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(self::order_url((int) $o['id'])); ?>">#<?php echo esc_html($o['number'] ?? $o['id']); ?></a></td>
                                    <td><?php echo esc_html(sprintf(/* translators: %s: value */ __('%s ago', 'magesentinel-monitor'), human_time_diff((int) $o['placed_at'], time()))); ?></td>
                                    <td class="ms-wrap"><?php echo esc_html($first ? $first->get_name() . (count($items) > 1 ? ' +' . (count($items) - 1) : '') : '—'); ?></td>
                                    <td><?php echo esc_html($order ? $order->get_payment_method_title() : '—'); ?></td>
                                    <td class="ms-num"><?php echo isset($o['total']) ? esc_html(html_entity_decode(wp_strip_all_tags(wc_price($o['total'], ['currency' => $o['currency'] ?? ''])))) : '—'; ?></td>
                                    <td><span class="ms-chip <?php echo $status === 'cancelled' ? 'ms-good' : 'ms-watch'; ?>"><?php echo esc_html(function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div>
                <div class="ms-card ms-appear" style="--d:15">
                    <div class="ms-head"><h2><?php echo esc_html(__('Recent setting changes', 'magesentinel-monitor')); ?></h2></div>
                    <?php if (!$changes) : ?>
                        <p class="ms-empty"><?php echo esc_html(__('No changes recorded yet.', 'magesentinel-monitor')); ?></p>
                    <?php else : ?>
                        <ul class="ms-tl">
                            <?php foreach (array_slice($changes, 0, 5) as $c) : ?>
                                <li><i></i><span><?php echo esc_html(self::setting_label($c['path'])); ?><br><?php if (!empty($c['detail'])) : ?><small class="ms-tl-detail"><?php echo esc_html($c['detail']); ?></small><?php else : ?><code><?php echo esc_html($c['path']); ?></code><?php endif; ?></span><small><?php echo esc_html(sprintf(/* translators: %s: value */ __('%s ago', 'magesentinel-monitor'), human_time_diff(strtotime($c['changed_at']), time()))); ?></small></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="ms-note"><?php echo esc_html(__('Names only, never values.', 'magesentinel-monitor')); ?></p>
                </div>
            </section>
        </div>
        <?php
    }

    public static function settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        $s = MageSentinel_Monitor::settings();
        $a = MageSentinel_Monitor::activity();
        $conn = self::connection();
        $name = MageSentinel_Monitor::OPTION;
        $hasToken = $s['token_hash'] !== '';
        $selfCheck = MageSentinel_Monitor_Self_Check::state();
        $saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'; // phpcs:ignore WordPress.Security.NonceVerification
        $check = static function (string $text, bool $yes): string {
            return '<li class="' . ($yes ? 'ms-y' : 'ms-n') . '">' . esc_html($text) . '</li>';
        };
        ?>
        <div class="wrap ms-app" data-ms-theme="<?php echo esc_attr(self::theme()); ?>">
            <?php self::header('settings'); ?>
            <?php if (!$saved) settings_errors(); ?>
            <div class="ms-grid-settings">
                <form class="ms-stack-s" method="post" action="options.php" data-ms-settings>
                    <?php settings_fields('magesentinel_monitor'); ?>
                    <section id="alerts" class="ms-card ms-set-card ms-appear" style="--d:1">
                        <div class="ms-set-head">
                            <div><h2><?php echo esc_html(__('Email alerts', 'magesentinel-monitor')); ?></h2><p><?php echo esc_html(__('This site runs the health checks every 15 minutes and emails you when one turns red, and again when it is fixed. Free, no account: nothing leaves your server but the email.', 'magesentinel-monitor')); ?></p></div>
                        </div>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[alerts]" value="1" <?php checked($s['alerts']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__('Email me when something stops orders', 'magesentinel-monitor')); ?></b><small><?php echo esc_html($selfCheck['checked_at'] ? sprintf(/* translators: %s: value */ __('Last self-check %s ago.', 'magesentinel-monitor'), human_time_diff($selfCheck['checked_at'], time())) : __('The first self-check runs within the hour.', 'magesentinel-monitor')); ?></small></span>
                        </label>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[weekly_report]" value="1" <?php checked($s['weekly_report']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__('Weekly summary every Monday', 'magesentinel-monitor')); ?></b><small><?php echo esc_html(__('Orders, revenue and failed payments against the week before, and what needs a look.', 'magesentinel-monitor')); ?> <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=magesentinel_weekly_send'), 'magesentinel_weekly_send')); ?>"><?php echo esc_html(__('Send one now', 'magesentinel-monitor')); ?></a><?php
                                $wk = isset($_GET['ms_weekly']) ? sanitize_key((string) $_GET['ms_weekly']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
                                if ($wk !== '') echo ' · ' . esc_html($wk === 'sent' ? sprintf(/* translators: %s: value */ __('Sent to %s.', 'magesentinel-monitor'), MageSentinel_Monitor_Self_Check::recipient()) : __('Could not send the email.', 'magesentinel-monitor'));
                            ?></small></span>
                        </label>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[js_errors]" value="1" <?php checked($s['js_errors']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__('Record shoppers\' JavaScript errors on cart and checkout', 'magesentinel-monitor')); ?></b><small><?php echo esc_html(__('A few lines of script on those two pages only. Kept on this site for 14 days: the error, the script and line, the page and the browser. No cookies, no IP address, emails and long numbers masked.', 'magesentinel-monitor')); ?></small></span>
                        </label>
                        <div class="ms-field">
                            <label for="ms-alert-email"><?php echo esc_html(__('Send alerts to', 'magesentinel-monitor')); ?></label>
                            <input id="ms-alert-email" type="email" name="<?php echo esc_attr($name); ?>[alert_email]" value="<?php echo esc_attr($s['alert_email']); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                            <small><?php echo esc_html(__('Empty: the site admin address.', 'magesentinel-monitor')); ?></small>
                        </div>
                        <?php if (MageSentinel_Monitor_Self_Check::monitored()) : ?>
                            <div class="ms-note"><?php echo wp_kses(self::icon('check', 18), self::allowed_html()); ?><span><?php echo esc_html(__('MageSentinel is connected and sends your alerts, so these emails are paused to spare you duplicates. If MageSentinel stops checking in, they resume on their own.', 'magesentinel-monitor')); ?></span></div>
                        <?php endif; ?>
                    </section>
                    <section class="ms-card ms-set-card ms-appear" style="--d:2">
                        <div class="ms-set-head">
                            <div><h2><?php echo esc_html(__('Connection to MageSentinel', 'magesentinel-monitor')); ?></h2><p><?php echo esc_html(__('In MageSentinel open your store, generate a connector token and paste it here. Only a hash of it is kept on this site.', 'magesentinel-monitor')); ?></p></div>
                            <span class="ms-pill ms-pill-<?php echo esc_attr($conn['state']); ?>" title="<?php echo esc_attr($conn['detail']); ?>"><span class="ms-pulse"></span><?php echo esc_html($conn['title']); ?></span>
                        </div>
                        <?php if (!empty($conn['plans'])) : ?>
                            <div class="ms-note"><?php echo wp_kses(self::icon('card', 18), self::allowed_html()); ?><span><?php echo esc_html($conn['detail']); ?></span></div>
                        <?php endif; ?>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked($s['enabled']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__('Enable the connector', 'magesentinel-monitor')); ?></b><small><?php echo esc_html(__('Lets MageSentinel read store health and confirm its test orders.', 'magesentinel-monitor')); ?></small></span>
                        </label>
                        <div class="ms-field">
                            <label for="ms-token"><?php echo esc_html(__('Access token', 'magesentinel-monitor')); ?></label>
                            <div class="ms-tok">
                                <input id="ms-token" type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($name); ?>[token]" value="" placeholder="<?php echo esc_attr($hasToken ? __('Paste a new token to replace it', 'magesentinel-monitor') : __('Paste your connector token', 'magesentinel-monitor')); ?>">
                                <span class="ms-tok-set <?php echo $hasToken ? 'is-set' : 'is-missing'; ?>"><?php echo wp_kses(self::icon($hasToken ? 'check' : 'alert', 13), self::allowed_html()); ?><?php echo esc_html($hasToken ? __('Set', 'magesentinel-monitor') : __('Not set', 'magesentinel-monitor')); ?></span>
                            </div>
                            <small><?php echo esc_html($hasToken ? __('A token is set. Type a new one to replace it.', 'magesentinel-monitor') : __('No token set: MageSentinel cannot connect.', 'magesentinel-monitor')); ?></small>
                        </div>
                        <?php if (!$hasToken) : ?>
                            <div class="ms-note ms-note-cta"><?php echo wp_kses(self::icon('eye', 18), self::allowed_html()); ?><span><?php echo esc_html(__('No MageSentinel account yet? It places a real test purchase in your store every hour and alerts you when checkout breaks. 14 days free, no card.', 'magesentinel-monitor')); ?> <a href="<?php echo esc_url(MageSentinel_Monitor_Self_Check::SIGNUP_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Create an account', 'magesentinel-monitor')); ?></a> · <a href="<?php echo esc_url(self::pricing_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Plans & pricing', 'magesentinel-monitor')); ?></a></span></div>
                        <?php endif; ?>
                    </section>
                    <section class="ms-card ms-set-card ms-appear" style="--d:3">
                        <div class="ms-set-head"><div><h2><?php echo esc_html(__('Test orders', 'magesentinel-monitor')); ?></h2><p><?php echo esc_html(__("MageSentinel's purchases are marked as tests and never reach a customer.", 'magesentinel-monitor')); ?></p></div></div>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[allow_cancel]" value="1" <?php checked($s['allow_cancel']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__("Cancel MageSentinel's test orders automatically", 'magesentinel-monitor')); ?></b><small><?php echo esc_html(__('Only orders from a magesentinel+… address, under an hour old and unpaid. Never deleted, never anything else.', 'magesentinel-monitor')); ?></small></span>
                        </label>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[allow_captcha_skip]" value="1" <?php checked($s['allow_captcha_skip']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__("Let MageSentinel's checks past your CAPTCHA", 'magesentinel-monitor')); ?></b><small><?php echo esc_html(__('Only requests that carry this store\'s token. Every visitor still gets the CAPTCHA. Works with CAPTCHA 4WP, reCAPTCHA for WooCommerce, hCaptcha for WP and Simple Cloudflare Turnstile.', 'magesentinel-monitor')); ?></small></span>
                        </label>
                        <label class="ms-toggle">
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[allow_test_gateway]" value="1" <?php checked($s['allow_test_gateway']); ?>>
                            <span class="ms-switch" aria-hidden="true"></span>
                            <span><b><?php echo esc_html(__('Test orders through a hidden payment method', 'magesentinel-monitor')); ?></b><small><?php echo esc_html(__('For stores that take only cards or PayPal. MageSentinel\'s checks (and only they, by this store\'s token) see a payment method that takes no money, place a real order with it and cancel it within 30 minutes, with stock restored. Those orders are never deleted and never shown in your order list. Off by default. Systems connected to your store (ERP, invoicing, couriers, marketing pixels) may still see the order, then see it cancelled.', 'magesentinel-monitor')); ?></small></span>
                        </label>
                        <div class="ms-note"><?php echo wp_kses(self::icon('mail-off', 18), self::allowed_html()); ?><span><?php echo esc_html(__('WooCommerce emails (new, cancelled and failed order, customer emails) are not sent for test orders.', 'magesentinel-monitor')); ?></span></div>
                    </section>
                    <?php /* The switches save as soon as they are flipped (admin.js); the button saves the typed fields. */ ?>
                    <div class="ms-save-row" data-ms-save-row data-saved="<?php echo esc_attr(__('Saved', 'magesentinel-monitor')); ?>" data-failed="<?php echo esc_attr(__('Could not save. Try again.', 'magesentinel-monitor')); ?>">
                        <button type="submit" class="ms-btn ms-btn-primary" data-ms-save data-busy="<?php echo esc_attr(__('Saving…', 'magesentinel-monitor')); ?>"><?php echo wp_kses(self::icon('refresh', 15), self::allowed_html()); ?><span><?php echo esc_html(__('Save changes', 'magesentinel-monitor')); ?></span></button>
                        <span class="ms-saved" role="status" data-ms-status<?php echo $saved ? '' : ' hidden'; ?>><?php echo wp_kses(self::icon('check', 14), self::allowed_html()); ?><span><?php echo esc_html(__('Settings saved', 'magesentinel-monitor')); ?></span></span>
                    </div>
                </form>

                <aside class="ms-stack-s">
                    <section class="ms-card ms-set-card ms-appear" style="--d:2">
                        <div>
                            <h2><?php echo esc_html(__('What MageSentinel can see', 'magesentinel-monitor')); ?></h2>
                            <ul class="ms-can">
                                <?php
                                echo wp_kses($check(__('Versions and active plugins', 'magesentinel-monitor'), true), self::allowed_html());
                                echo wp_kses($check(__('Payment and shipping methods', 'magesentinel-monitor'), true), self::allowed_html());
                                echo wp_kses($check(__('Order and stock counts', 'magesentinel-monitor'), true), self::allowed_html());
                                echo wp_kses($check(__('Background task and error counts', 'magesentinel-monitor'), true), self::allowed_html());
                                ?>
                            </ul>
                        </div>
                        <div>
                            <h2><?php echo esc_html(__('What it never sees', 'magesentinel-monitor')); ?></h2>
                            <ul class="ms-can">
                                <?php
                                echo wp_kses($check(__('Customer details', 'magesentinel-monitor'), false), self::allowed_html());
                                echo wp_kses($check(__('Order contents and revenue', 'magesentinel-monitor'), false), self::allowed_html());
                                echo wp_kses($check(__('Error messages', 'magesentinel-monitor'), false), self::allowed_html());
                                ?>
                            </ul>
                        </div>
                    </section>
                    <section class="ms-card ms-appear" style="--d:3">
                        <dl class="ms-facts-s">
                            <div><dt><?php echo esc_html(__('Plugin version', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html(MageSentinel_Monitor::VERSION); ?></dd></div>
                            <div><dt><?php echo esc_html(__('Last contact', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html($a['last_seen'] ? sprintf(/* translators: %s: value */ __('%s ago', 'magesentinel-monitor'), human_time_diff($a['last_seen'], time())) : __('Never', 'magesentinel-monitor')); ?></dd></div>
                            <div><dt><?php echo esc_html(__('Store', 'magesentinel-monitor')); ?></dt><dd><?php echo esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)); ?></dd></div>
                        </dl>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }

    /** HPOS stores edit orders under wc-orders; legacy stores under post.php. */
    private static function order_url(int $id): string
    {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($id);
        }
        return admin_url('post.php?post=' . $id . '&action=edit');
    }

    /**
     * "Test cart & checkout" for a connected store; otherwise the free monthly
     * test, or, once it is used, when the next one is and the last result.
     */
    private static function test_button(array $conn): void
    {
        $busy = __('In progress…', 'magesentinel-monitor');
        if ($conn['state'] !== 'off') {
            echo '<button type="button" class="ms-btn ms-btn-primary" data-ms-test-start data-busy="' . esc_attr($busy) . '">' . wp_kses(self::icon('refresh', 15), self::allowed_html()) . '<span>' . esc_html(__('Test cart & checkout', 'magesentinel-monitor')) . '</span></button>';
            return;
        }
        $free = get_option(MageSentinel_Monitor::FREE_STATE);
        $next = is_array($free) ? (int) ($free['next'] ?? 0) : 0;
        if ($next > time()) {
            $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
            $url = 'https://magesentinel.com' . (strpos($locale, 'el') === 0 ? '/el' : '') . '/audit/' . rawurlencode((string) $free['id']);
            echo '<span class="ms-free-used">' . esc_html(sprintf(/* translators: %s: date */ __('Free test used this month. Next one on %s.', 'magesentinel-monitor'), date_i18n(get_option('date_format'), $next))) . ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(__('Last result', 'magesentinel-monitor')) . ' →</a></span>';
            return;
        }
        echo '<button type="button" class="ms-btn ms-btn-primary" data-ms-test-start data-busy="' . esc_attr($busy) . '" title="' . esc_attr(__('Once a month, free and without an account: MageSentinel buys from your store like a shopper, up to the last click before paying.', 'magesentinel-monitor')) . '">' . wp_kses(self::icon('refresh', 15), self::allowed_html()) . '<span>' . esc_html(__('Free test purchase', 'magesentinel-monitor')) . '</span></button>';
    }

    /** The live "Test purchase" funnel the button fills in; admin.js drives it. */
    private static function test_box(array $conn): void
    {
        // Without an account, a free test that did not pass ends with the one
        // offer that fits the moment: be told next time instead of finding out.
        $cta = $conn['state'] === 'off' ? [
            '_cta' => __('Want an email the next time this happens? MageSentinel runs this check every hour. 14 days free, no card.', 'magesentinel-monitor'),
            '_ctaLink' => __('Start the free trial', 'magesentinel-monitor'),
            '_ctaUrl' => MageSentinel_Monitor_Self_Check::SIGNUP_URL . '&utm_content=free-test-result',
        ] : [];
        ?>
        <div class="ms-test" data-ms-test hidden
            data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce('magesentinel_test')); ?>"
            data-labels="<?php echo esc_attr((string) wp_json_encode([
                'store' => __('Opening the store', 'magesentinel-monitor'), 'discovery' => __('Finding a product', 'magesentinel-monitor'), 'product' => __('Product page', 'magesentinel-monitor'),
                'cart' => __('Adding to cart', 'magesentinel-monitor'), 'cart-validation' => __('Checking the cart', 'magesentinel-monitor'), 'checkout' => __('Opening checkout', 'magesentinel-monitor'),
                'customer' => __('Filling in customer details', 'magesentinel-monitor'), 'shipping' => __('Choosing shipping', 'magesentinel-monitor'), 'payment' => __('Choosing payment', 'magesentinel-monitor'),
                'order' => __('Placing the order', 'magesentinel-monitor'), 'order-verification' => __('Confirming the order', 'magesentinel-monitor'), 'order-cleanup' => __('Cancelling the test order', 'magesentinel-monitor'),
                'login' => __('Signing in', 'magesentinel-monitor'),
                '_short' => [
                    'store' => self::flow('Store'), 'discovery' => self::flow('Search'), 'product' => self::flow('Product'), 'cart' => self::flow('Cart'), 'cart-validation' => self::flow('Cart'),
                    'checkout' => self::flow('Checkout'), 'customer' => self::flow('Customer'), 'shipping' => self::flow('Shipping'), 'payment' => self::flow('Payment'),
                    'order' => self::flow('Order'), 'order-verification' => self::flow('Confirmed'), 'order-cleanup' => self::flow('Cancelled'), 'login' => self::flow('Sign in'),
                ],
                '_queued' => __('Waiting for a free browser…', 'magesentinel-monitor'), '_start' => __('Starting…', 'magesentinel-monitor'),
                'PASS' => __('Passed: a customer can buy.', 'magesentinel-monitor'), 'FAIL' => __('Failed: a customer could not buy.', 'magesentinel-monitor'),
                'DEGRADED' => __('Passed, with warnings.', 'magesentinel-monitor'), 'WARNING' => __('Passed, with warnings.', 'magesentinel-monitor'), 'UNKNOWN' => __('Inconclusive: the check could not reach a verdict.', 'magesentinel-monitor'),
                '_slowest' => __('slowest', 'magesentinel-monitor'), '_share' => __('{step} took {share}% of the check', 'magesentinel-monitor'),
                '_view' => __('View the full run in MageSentinel', 'magesentinel-monitor'), '_slow' => __('Still running. The result will appear in MageSentinel.', 'magesentinel-monitor'),
            ] + $cta)); ?>">
            <span class="ms-test-title"><?php echo esc_html(__('Test purchase', 'magesentinel-monitor')); ?></span>
            <ol class="ms-flow" data-ms-test-bar></ol>
            <div class="ms-split" data-ms-split hidden>
                <div class="ms-split-head"><span><?php echo esc_html(__('Where the time went', 'magesentinel-monitor')); ?></span><span data-ms-split-hint></span></div>
                <div class="ms-split-bar" data-ms-split-bar></div>
            </div>
            <p class="ms-test-line" role="status" data-ms-test-line></p>
        </div>
        <?php
    }

    public static function dashboard_widget(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        wp_add_dashboard_widget('magesentinel_monitor', 'MageSentinel', [self::class, 'render_widget']);
    }

    public static function render_widget(): void
    {
        $rows = self::sorted_health();
        $v = self::verdict($rows);
        $conn = self::connection();
        $sales = MageSentinel_Monitor::sales();
        $f = MageSentinel_Monitor::facts();
        $a = MageSentinel_Monitor::activity();
        $issue = $rows && $rows[0]['state'] !== 'good' ? $rows[0] : null;
        $currency = (string) ($sales['currency'] ?? get_woocommerce_currency());
        $lastTest = $a['orders'] ? max(array_column($a['orders'], 'placed_at')) : 0;
        ?>
        <div class="ms-app ms-widget">
            <div class="ms-w-head">
                <?php echo wp_kses(self::ring($v['pct'], 'sm'), self::allowed_html()); ?>
                <div><b><?php echo esc_html($v['title']); ?></b><span><?php echo esc_html($v['sub'] . ($lastTest ? ' · ' . sprintf(/* translators: %s: value */ __('test purchase %s ago', 'magesentinel-monitor'), human_time_diff((int) $lastTest, time())) : '')); ?></span></div>
            </div>
            <?php echo wp_kses(self::strip($rows), self::allowed_html()); ?>
            <div class="ms-w-kpis">
                <div><span><?php echo esc_html(__('Orders today', 'magesentinel-monitor')); ?></span><strong data-ms-count="<?php echo esc_attr((string) (int) ($sales['total_24h'] ?? 0)); ?>"><?php echo esc_html(number_format_i18n((int) ($sales['total_24h'] ?? 0))); ?></strong></div>
                <div><span><?php echo esc_html(__('Revenue', 'magesentinel-monitor')); ?></span><strong><?php echo esc_html(html_entity_decode(wp_strip_all_tags(wc_price((float) ($sales['revenue_24h'] ?? 0), ['currency' => $currency, 'decimals' => 0])))); ?></strong></div>
                <div><span><?php echo esc_html(__('Failed', 'magesentinel-monitor')); ?></span><strong data-ms-count="<?php echo esc_attr((string) (int) $f['failed_orders_24h']); ?>"><?php echo esc_html(number_format_i18n((int) $f['failed_orders_24h'])); ?></strong></div>
            </div>
            <?php echo wp_kses(self::week_chart(array_values($sales['last_7d'] ?? []), true), self::allowed_html()); ?>
            <?php if ($issue) : ?>
                <div class="ms-w-issue"><span class="ms-st ms-<?php echo esc_attr($issue['state']); ?>"><?php echo wp_kses(self::state_icon($issue['state']), self::allowed_html()); ?></span><div><b><?php echo esc_html($issue['label']); ?></b><p><?php echo esc_html($issue['summary']); ?></p></div></div>
            <?php endif; ?>
            <?php if ($conn['state'] === 'off') : $alerts = self::alerts_status(); ?>
                <div class="ms-w-free"><span class="ms-chip<?php echo $alerts['state'] === 'good' ? ' ms-good' : ($alerts['state'] === 'watch' ? ' ms-watch' : ''); ?>"><?php echo esc_html($alerts['text']); ?></span><a href="<?php echo esc_url(MageSentinel_Monitor_Self_Check::SIGNUP_URL); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('Add real test purchases', 'magesentinel-monitor')); ?> →</a></div>
            <?php endif; ?>
            <div class="ms-w-test">
                <?php self::test_button($conn); ?>
                <?php self::test_box($conn); ?>
            </div>
            <div class="ms-w-foot"><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>"><?php echo esc_html(__('All checks', 'magesentinel-monitor')); ?> →</a><span class="ms-pill ms-pill-<?php echo esc_attr($conn['state']); ?>" title="<?php echo esc_attr($conn['detail']); ?>"><span class="ms-pulse"></span><?php echo esc_html($conn['title']); ?></span></div>
        </div>
        <?php
    }
}
