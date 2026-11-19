<?php
/**
 * Plugin Name:       MageSentinel – Checkout Monitoring for WooCommerce
 * Plugin URI:        https://magesentinel.com/docs
 * Description:       WooCommerce checkout monitoring: store health at a glance, checked every 15 minutes with an email when something stops orders. Connect it to MageSentinel for real test purchases from outside.
 * Version:           4.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 * Author:            MageSentinel
 * Author URI:        https://magesentinel.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       magesentinel-monitor
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor
{
    const VERSION = '4.2';
    const OPTION = 'magesentinel_monitor';
    /** The one-off check MageSentinel runs after a checkout-relevant update. */
    const AFTER_UPDATE_HOOK = 'magesentinel_monitor_after_update';
    /** What that check last did: when it was asked for, and what MageSentinel answered. Not autoloaded. */
    const AFTER_UPDATE_STATE = 'magesentinel_monitor_after_update';
    /** What MageSentinel did here: last contact and its recent test orders. Not autoloaded. */
    const ACTIVITY = 'magesentinel_monitor_activity';
    const HEADER = 'X-MageSentinel-Token';
    const NS = 'magesentinel/v1';
    const SLUG = 'magesentinel-monitor';
    const DASHBOARD_URL = 'https://magesentinel.com/app';
    /** Mirrors isMageSentinelIdentity() in src/monitoring/identity/guest.ts. This side protects real orders. */
    const SYNTHETIC_EMAIL = '/^magesentinel[+.][a-z0-9]+@/i';
    /** An order older than this is not ours to touch, whatever it is called. */
    const MAX_AGE_SECONDS = 3600;
    /** How many of MageSentinel's own orders the overview lists. */
    const ORDER_LOG = 15;
    /** Weeks of history behind the hour-of-week order baseline. */
    const BASELINE_WEEKS = 4;
    /** MageSentinel's test orders, by id, so order queries can leave them out without reading the billing email. */
    const TEST_IDS = 'magesentinel_monitor_test_order_ids';
    /** Longer than any window the checks read (the four-week baseline plus the week before). */
    const TEST_IDS_DAYS = 36;
    /** The hour buckets of the week except the last day, kept an hour. */
    const SALES_CLOSED = 'magesentinel_monitor_sales_closed';
    /** @var int[]|null test_order_ids() for the rest of this request; remember_test_order() adds to it. */
    private static $test_ids_memo = null;
    /** The free monthly test: the challenge MageSentinel reads back, and the last test (id, when, next allowed). */
    const FREE_PROOF = 'magesentinel_monitor_free_proof';
    const FREE_STATE = 'magesentinel_monitor_free_test';
    /** The health checks' worst result per store day, last 7 days. */
    const HEALTH_DAYS = 'magesentinel_monitor_health_days';
    /** The free monthly test purchases' times, for the Checkout speed card. */
    const FREE_SPEED = 'magesentinel_monitor_free_speed';

    /** Whether an order was placed by MageSentinel's own checks. */
    public static function is_test_order(WC_Order $order): bool
    {
        return preg_match(self::SYNTHETIC_EMAIL, (string) $order->get_billing_email()) === 1
            || $order->get_payment_method() === 'magesentinel_test';
    }

    public static function boot(): void
    {
        add_action('woocommerce_new_order', [self::class, 'remember_test_order'], 10, 2);
        add_action('woocommerce_update_order', [self::class, 'remember_test_order'], 10, 2);
        // Translations come from translate.wordpress.org; the bundled ones in
        // languages/ cover sites that have not fetched them yet.
        add_action('init', static function () {
            load_plugin_textdomain('magesentinel-monitor', false, dirname(plugin_basename(__FILE__)) . '/languages');
        });
        // Orders are read and written through WooCommerce's CRUD, never through
        // the posts tables, so the plugin is indifferent to where Woo keeps
        // them. Say so: undeclared, WooCommerce lists us as incompatible on the
        // HPOS screen and offers the shop owner a reason not to turn it on.
        add_action('before_woocommerce_init', static function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables',
                    __FILE__,
                    true
                );
            }
        });
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('updated_option', [self::class, 'record_setting_change'], 10, 3);
        add_action('added_option', [self::class, 'record_setting_change'], 10, 1);
        add_action('switch_theme', static function () { self::record_setting_change('stylesheet'); });
        add_action('upgrader_process_complete', [self::class, 'record_update'], 10, 2);
        add_action(self::AFTER_UPDATE_HOOK, [self::class, 'after_update_check']);
        add_action('template_redirect', [self::class, 'count_checkout_visit']);
        // A price fixed in the editor shows on the next page load, not in twelve hours.
        $fresh = static function () { delete_transient('magesentinel_monitor_unbuyable'); };
        add_action('save_post_product', $fresh);
        add_action('save_post_product_variation', $fresh);
        add_action('woocommerce_update_product', $fresh);
        foreach (['deleted_post', 'trashed_post', 'untrashed_post'] as $h) {
            add_action($h, static function ($id) use ($fresh) { if (in_array(get_post_type($id), ['product', 'product_variation'], true)) $fresh(); });
        }
        // Test orders must not reach the owner's or anyone's inbox: new, cancelled,
        // failed, on-hold and customer emails are all silenced for them.
        $silence = static function ($recipient, $order = null) {
            return $order instanceof WC_Order && self::is_test_order($order) ? '' : $recipient;
        };
        foreach (['new_order', 'cancelled_order', 'failed_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_cancelled_order', 'customer_failed_order', 'customer_invoice', 'customer_note', 'customer_refunded_order'] as $email) {
            add_filter('woocommerce_email_recipient_' . $email, $silence, 99, 2);
        }
    }

    /** @return array{enabled:bool,token_hash:string,allow_cancel:bool,allow_captcha_skip:bool,alerts:bool,alert_email:string} */
    public static function settings(): array
    {
        $s = get_option(self::OPTION, []);
        return [
            // The free email alerts are on until the owner switches them off.
            'alerts' => !is_array($s) || !array_key_exists('alerts', $s) || !empty($s['alerts']),
            'alert_email' => isset($s['alert_email']) ? (string) $s['alert_email'] : '',
            // The Monday summary is on until the owner switches it off.
            // Opt-in: no weekly email until the admin switches it on.
            'weekly_report' => is_array($s) && !empty($s['weekly_report']),
            'enabled' => !empty($s['enabled']),
            'token_hash' => isset($s['token_hash']) ? (string) $s['token_hash'] : '',
            'allow_cancel' => !empty($s['allow_cancel']),
            'allow_captcha_skip' => !empty($s['allow_captcha_skip']),
            // Off until the owner turns it on: the hidden test payment method.
            'allow_test_gateway' => !empty($s['allow_test_gateway']),
            // On until the owner switches it off: shoppers' JavaScript errors on cart and checkout.
            'js_errors' => !is_array($s) || !array_key_exists('js_errors', $s) || !empty($s['js_errors']),
        ];
    }

    /** @return array{last_seen:int,orders:array<int,array<string,mixed>>} */
    public static function activity(): array
    {
        $a = get_option(self::ACTIVITY, []);
        return [
            'last_seen' => isset($a['last_seen']) ? (int) $a['last_seen'] : 0,
            'orders' => isset($a['orders']) && is_array($a['orders']) ? $a['orders'] : [],
        ];
    }

    private static function save_activity(array $a): void
    {
        update_option(self::ACTIVITY, $a, false);
    }

    /** One write per minute at most: every check calls in, the page only needs "recently". */
    private static function touch(): void
    {
        $a = self::activity();
        if (time() - $a['last_seen'] < 60) return;
        $a['last_seen'] = time();
        self::save_activity($a);
    }

    private static function log_order(WC_Order $order, string $event): void
    {
        $a = self::activity();
        $id = $order->get_id();
        $entry = $a['orders'][$id] ?? ['id' => $id, 'placed_at' => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time()];
        $entry['number'] = (string) $order->get_order_number();
        $entry['status'] = $order->get_status();
        $entry['total'] = (float) $order->get_total();
        $entry['currency'] = $order->get_currency();
        $entry[$event . '_at'] = time();
        unset($a['orders'][$id]);
        $a['orders'] = [$id => $entry] + $a['orders'];
        $a['orders'] = array_slice($a['orders'], 0, self::ORDER_LOG, true);
        $a['last_seen'] = time();
        self::save_activity($a);
    }

    // ------------------------------------------------------------------ REST

    public static function routes(): void
    {
        // The free monthly test: MageSentinel reads back the one-off challenge
        // the admin's own request just set, proving the request came from this
        // site. Public, because that read is the whole proof; it returns only
        // that random value, and only for the two minutes it exists.
        register_rest_route(self::NS, '/proof', [
            'methods' => 'GET',
            'callback' => static function () {
                $c = get_transient(self::FREE_PROOF);
                return $c ? new WP_REST_Response(['challenge' => (string) $c]) : new WP_REST_Response(['error' => 'none'], 404);
            },
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/diagnostics', [
            'methods' => 'GET',
            'callback' => [self::class, 'diagnostics'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'verify_order'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [self::class, 'cancel_order'],
            'permission_callback' => [self::class, 'authorize_cancel'],
        ]);
        // Cancel (never delete), only for orders placed with the hidden test method.
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/test-cancel', [
            'methods' => 'POST',
            'callback' => [self::class, 'cancel_test_order'],
            'permission_callback' => [self::class, 'authorize_test_gateway'],
        ]);
    }

    /** The owner's opt-in for the hidden test method also allows cancelling its orders. */
    public static function authorize_test_gateway(WP_REST_Request $request)
    {
        $ok = self::authorize($request);
        if ($ok !== true) return $ok;
        if (!self::settings()['allow_test_gateway']) {
            return new WP_Error('magesentinel_test_gateway', 'MageSentinel test orders are not enabled on this store.', ['status' => 403]);
        }
        return true;
    }

    public static function cancel_test_order(WP_REST_Request $request)
    {
        $order = wc_get_order((int) $request['id']);
        if (!$order instanceof WC_Order) return new WP_Error('magesentinel_no_order', 'No such order.', ['status' => 404]);
        if (!MageSentinel_Monitor_Test_Gateway::is_gateway_order($order) || !self::is_test_order($order)) {
            return new WP_REST_Response(['error' => 'not_a_test_order'], 409);
        }
        $key = (string) $request->get_param('key');
        if ($key === '' || !hash_equals((string) $order->get_order_key(), $key)) {
            return new WP_Error('magesentinel_no_order', 'No such order.', ['status' => 404]);
        }
        if (!MageSentinel_Monitor_Test_Gateway::cancel($order, self::MAX_AGE_SECONDS)) {
            return new WP_REST_Response(['error' => 'not_a_test_order'], 409);
        }
        return new WP_REST_Response(['cancelled' => true, 'status' => 'cancelled'], 200);
    }

    /**
     * No token configured means no access: an empty shared secret would let
     * anyone read the store. Only a hash of the token is stored.
     *
     * @return true|WP_Error
     */
    public static function authorize(WP_REST_Request $request)
    {
        $s = self::settings();
        if (!$s['enabled']) {
            return new WP_Error('magesentinel_disabled', 'MageSentinel diagnostics are disabled.', ['status' => 403]);
        }
        $provided = (string) $request->get_header(self::HEADER);
        if ($s['token_hash'] === '' || $provided === '' || !hash_equals($s['token_hash'], hash('sha256', $provided))) {
            return new WP_Error('magesentinel_token', 'Invalid MageSentinel token.', ['status' => 401]);
        }
        return true;
    }

    /** A second switch, off by default: cancelling is the plugin's only write. */
    public static function authorize_cancel(WP_REST_Request $request)
    {
        $ok = self::authorize($request);
        if ($ok !== true) return $ok;
        if (!self::settings()['allow_cancel']) {
            return new WP_Error('magesentinel_cancel', 'MageSentinel is not allowed to cancel orders on this store.', ['status' => 403]);
        }
        return true;
    }

    public static function diagnostics(WP_REST_Request $request): WP_REST_Response
    {
        global $wp_version;
        self::touch();
        $facts = self::facts();

        $body = [
            'connector_version' => self::VERSION,
            'capabilities' => self::settings()['allow_test_gateway'] ? ['test_gateway'] : [],
            'collected_at' => gmdate('c'),
            'platform' => [
                'name' => 'woocommerce',
                'wordpress' => $wp_version,
                'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
                'php_version' => PHP_VERSION,
                'base_url' => home_url('/'),
                'locale' => get_locale(),
                'timezone' => wp_timezone_string(),
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
                'theme' => wp_get_theme()->get('Name'),
                'maintenance' => $facts['maintenance'],
                'disk' => $facts['disk'],
            ],
            'plugins' => $facts['plugins'],
            'payment_methods' => $facts['payments'],
            'shipping_methods' => $facts['shipping'],
            'sales' => self::sales_for_monitor(),
            'woo_status' => [
                'db_update_needed' => $facts['db_update_needed'],
                'checkout_page' => $facts['checkout_page'],
                'cart_page' => $facts['cart_page'],
                'checkout_kind' => $facts['checkout_kind'],
                'cart_kind' => $facts['cart_kind'],
                'failed_orders_24h' => $facts['failed_orders_24h'],
                'pending_payment_24h' => $facts['pending_payment_24h'],
            ],
            'scheduler' => $facts['scheduler'],
            'crash_reports' => $facts['fatal_errors'],
            // Same shape as the Magento module's, so the app's plugin list and change table read both.
            'modules' => ['third_party_count' => count($facts['plugins']), 'third_party' => $facts['plugins']],
            'config_changes' => self::setting_changes(),
            'catalog' => self::catalog(),
            'updates' => self::updates(),
            // What was actually updated in the last week, by name and version, for
            // the "what changed" timeline beside the store's checks.
            'recent_updates' => array_map(static function ($u) {
                return ['kind' => (string) $u['kind'], 'name' => (string) $u['name'], 'version' => (string) ($u['version'] ?? ''), 'checkout' => !empty($u['checkout']), 'at' => gmdate('c', (int) $u['at'])];
            }, self::recent_updates(7 * DAY_IN_SECONDS)),
            'cache' => self::cache(),
        ];

        $sku = (string) $request->get_param('sku');
        if ($sku !== '') $body['stock'] = self::stock($sku);

        return new WP_REST_Response($body, 200);
    }

    // ---------------------------------------------------------------- facts

    /** Everything the overview page and the monitor both read, collected once. */
    public static function facts(): array
    {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = [];
        $all = get_plugins();
        foreach ((array) get_option('active_plugins', []) as $file) {
            if (isset($all[$file])) $plugins[] = ['name' => $all[$file]['Name'], 'version' => $all[$file]['Version'] ?: null];
        }

        $payments = [];
        if (function_exists('WC') && WC()->payment_gateways()) {
            foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
                if ($gw->enabled === 'yes') $payments[] = ['code' => $id, 'title' => wp_strip_all_tags((string) $gw->get_title()), 'available' => self::gateway_available($gw)];
            }
        }

        $shipping = [];
        if (class_exists('WC_Shipping_Zones')) {
            $zones = WC_Shipping_Zones::get_zones();
            $zones[] = ['zone_id' => 0];
            foreach ($zones as $z) {
                $zone = WC_Shipping_Zones::get_zone((int) $z['zone_id']);
                if (!$zone) continue;
                foreach ($zone->get_shipping_methods(true) as $m) {
                    $shipping[] = ['code' => $m->id . ':' . $m->instance_id, 'title' => wp_strip_all_tags((string) $m->get_title()), 'zone' => $zone->get_zone_name()];
                }
            }
        }

        $disk = null;
        $free = @disk_free_space(ABSPATH);
        $total = @disk_total_space(ABSPATH);
        if (is_numeric($free) && is_numeric($total) && $total > 0) {
            $disk = ['free_bytes' => (int) $free, 'total_bytes' => (int) $total, 'used_percent' => (int) round(100 * (1 - $free / $total))];
        }

        return [
            'plugins' => $plugins,
            'payments' => $payments,
            'shipping' => $shipping,
            'maintenance' => file_exists(ABSPATH . '.maintenance'),
            'disk' => $disk,
            'db_update_needed' => class_exists('WC_Install') && method_exists('WC_Install', 'needs_db_update') ? (bool) WC_Install::needs_db_update() : null,
            'checkout_page' => self::page_ok('checkout'),
            'cart_page' => self::page_ok('cart'),
            'checkout_kind' => self::page_kind('checkout', 'woocommerce/checkout', 'woocommerce_checkout'),
            'cart_kind' => self::page_kind('cart', 'woocommerce/cart', 'woocommerce_cart'),
            'failed_orders_24h' => self::orders_since('failed', DAY_IN_SECONDS),
            'failed_orders_1h' => self::orders_since('failed', HOUR_IN_SECONDS),
            'pending_payment_24h' => self::orders_since('pending', DAY_IN_SECONDS),
            'gateways_24h' => self::gateway_outcomes(),
            'test_mode' => self::test_mode_gateways(),
            'scheduler' => self::scheduler(),
            'fatal_errors' => self::fatal_errors(),
        ];
    }

    /**
     * Paid and failed orders per payment method over 24 hours. One method
     * failing while the others take money is a broken gateway, not a quiet day.
     *
     * @return array<string,array{title:string,failed:int,paid:int}>
     */
    private static function gateway_outcomes(int $seconds = DAY_IN_SECONDS): array
    {
        if (!function_exists('wc_get_orders')) return [];
        global $wpdb;
        $q = self::order_sql();
        $now = time();
        [$method, $title, $join] = $q['hpos']
            ? ['o.payment_method', 'o.payment_method_title', '']
            : ['pm.meta_value', 'pt.meta_value', "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = o.ID AND pm.meta_key = '_payment_method' LEFT JOIN {$wpdb->postmeta} pt ON pt.post_id = o.ID AND pt.meta_key = '_payment_method_title'"];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate; names come from order_sql()
        $rows = $wpdb->get_results(
            "SELECT {$method} AS method, MAX({$title}) AS title, {$q['status']} AS status, COUNT(*) AS n
             FROM {$q['from']} {$join}
             WHERE " . self::real_order_sql($q) . " AND {$q['status']} IN ('wc-failed', 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending')
               AND " . self::date_sql($q, $now - $seconds, $now) . "
             GROUP BY method, status",
            ARRAY_A
        );
        // phpcs:enable
        $out = [];
        foreach ((array) $rows as $r) {
            $id = (string) $r['method'];
            if ($id === '') continue;
            if (!isset($out[$id])) $out[$id] = ['title' => wp_strip_all_tags((string) $r['title']) ?: $id, 'failed' => 0, 'paid' => 0, 'pending' => 0];
            $st = (string) $r['status'];
            $out[$id][$st === 'wc-failed' ? 'failed' : ($st === 'wc-pending' ? 'pending' : 'paid')] += (int) $r['n'];
        }
        return $out;
    }

    /**
     * The last 7 days per payment method, every enabled method listed even
     * with no orders: a method nobody pays with any more is the finding.
     *
     * @return array<string,array{title:string,failed:int,paid:int,pending:int}>
     */
    public static function payments_by_method(): array
    {
        $c = get_transient('magesentinel_monitor_by_method');
        if (is_array($c)) return $c;
        $out = self::gateway_outcomes(7 * DAY_IN_SECONDS);
        if (function_exists('WC') && WC()->payment_gateways()) {
            foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
                if ($gw->enabled === 'yes' && !isset($out[$id])) $out[$id] = ['title' => wp_strip_all_tags((string) $gw->get_title()) ?: (string) $id, 'failed' => 0, 'paid' => 0, 'pending' => 0];
            }
        }
        uasort($out, static function ($a, $b) { return ($b['paid'] + $b['failed']) <=> ($a['paid'] + $a['failed']); });
        set_transient('magesentinel_monitor_by_method', $out, 10 * MINUTE_IN_SECONDS);
        return $out;
    }

    /**
     * The certificate shoppers get (behind a CDN, the CDN's), read by opening
     * a TLS connection to the store's own address. Checked twice a day, hourly
     * in its last two weeks so a renewal shows up soon. Null when unreadable.
     *
     * @return array{expires:int,issuer:string}|null
     */
    public static function ssl(): ?array
    {
        $c = get_transient('magesentinel_monitor_ssl');
        if (is_array($c)) return !empty($c['ok']) ? $c : null;
        $out = ['ok' => false];
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host !== '' && strpos(home_url('/'), 'https://') === 0 && function_exists('stream_socket_client') && function_exists('openssl_x509_parse')) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $sock = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
            if ($sock) {
                $params = stream_context_get_params($sock);
                fclose($sock); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a TLS socket, not a file; WP_Filesystem has no sockets.
                $cert = $params['options']['ssl']['peer_certificate'] ?? null;
                $x = $cert ? openssl_x509_parse($cert) : false;
                if (is_array($x) && !empty($x['validTo_time_t'])) {
                    $out = ['ok' => true, 'expires' => (int) $x['validTo_time_t'], 'issuer' => (string) ($x['issuer']['O'] ?? $x['issuer']['CN'] ?? '')];
                }
            }
        }
        $soon = !empty($out['ok']) && $out['expires'] - time() < 14 * DAY_IN_SECONDS;
        set_transient('magesentinel_monitor_ssl', $out, !empty($out['ok']) && !$soon ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return !empty($out['ok']) ? $out : null;
    }

    /**
     * How long this server takes to look up a name, nameserver by nameserver
     * in the order /etc/resolv.conf lists them. The system resolver waits out
     * a dead first nameserver (its "timeout", 5 s by default) on every lookup
     * before asking the next, so payments, couriers and updates all stall.
     * A random name under wordpress.org forces a real lookup past any cache;
     * "no such name" is an answer as good as an address.
     */
    public static function dns(): ?array
    {
        $c = get_transient('magesentinel_monitor_dns');
        if (is_array($c)) return !empty($c['ok']) ? $c : null;
        $out = ['ok' => false];
        $conf = @file_get_contents('/etc/resolv.conf'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local system file, not a URL.
        if (is_string($conf) && function_exists('stream_socket_client')) {
            $servers = [];
            $timeout = 5;
            foreach (preg_split('/\R/', $conf) as $line) {
                if (preg_match('/^\s*nameserver\s+([0-9a-fA-F:.]+)/', $line, $m)) $servers[] = $m[1];
                if (preg_match('/^\s*options\b.*\btimeout:(\d+)/', $line, $m)) $timeout = max(1, (int) $m[1]);
            }
            $servers = array_slice($servers, 0, 3); // the resolver uses at most three
            if ($servers) {
                $rows = [];
                $total = 0.0;
                $answered = false;
                foreach ($servers as $ns) {
                    $ms = self::dns_ask($ns, 2.0);
                    $rows[] = ['ip' => $ns, 'ms' => $ms];
                    if (!$answered) $total += $ms === null ? $timeout * 1000 : $ms;
                    if ($ms !== null) $answered = true;
                }
                $out = ['ok' => true, 'servers' => $rows, 'answered' => $answered, 'ms' => $answered ? (int) round($total) : null, 'timeout' => $timeout];
            }
        }
        set_transient('magesentinel_monitor_dns', $out, 15 * MINUTE_IN_SECONDS);
        return !empty($out['ok']) ? $out : null;
    }

    /** One A query straight to a nameserver: milliseconds to its answer, null when none came. */
    private static function dns_ask(string $ns, float $wait): ?int
    {
        $name = 'ms-' . wp_generate_password(10, false) . '.wordpress.org';
        $id = wp_rand(0, 65535);
        $q = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);
        foreach (explode('.', strtolower($name)) as $label) $q .= chr(strlen($label)) . $label;
        $q .= "\0" . pack('nn', 1, 1);
        $host = strpos($ns, ':') !== false ? '[' . $ns . ']' : $ns;
        $start = microtime(true);
        $sock = @stream_socket_client('udp://' . $host . ':53', $errno, $errstr, $wait);
        if (!$sock) return null;
        stream_set_timeout($sock, (int) $wait, (int) (($wait - floor($wait)) * 1e6));
        fwrite($sock, $q); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a UDP socket, not a file.
        $ms = null;
        while (microtime(true) - $start < $wait) {
            $r = fread($sock, 512); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a UDP socket, not a file.
            if ($r === false || $r === '') break;
            if (strlen($r) >= 12 && unpack('n', substr($r, 0, 2))[1] === $id) { $ms = (int) round((microtime(true) - $start) * 1000); break; }
        }
        fclose($sock); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a UDP socket, not a file.
        return $ms;
    }

    /**
     * Shoppers who reached checkout with something in the cart, counted once a
     * day each. Counts only: a hash of the session decides "already counted"
     * and is dropped at the end of the day. Bots and MageSentinel's own checks
     * are left out.
     */
    public static function count_checkout_visit(): void
    {
        if (is_admin() || wp_doing_ajax() || !function_exists('is_checkout') || !is_checkout()) return;
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay'))) return;
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty() || !WC()->session) return;
        if (isset($_SERVER['HTTP_X_MAGESENTINEL_TOKEN'])) return;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pingdom|uptime/i', $ua)) return;
        $day = wp_date('Y-m-d');
        $who = substr(hash('sha256', (string) WC()->session->get_customer_id() . wp_salt('auth') . $day), 0, 16);
        $f = get_option('magesentinel_monitor_funnel', []);
        if (!is_array($f)) $f = [];
        if (empty($f['since'])) $f['since'] = time();
        if (($f['seen_day'] ?? '') !== $day) { $f['seen_day'] = $day; $f['seen'] = []; }
        if (isset($f['seen'][$who])) return;
        if (count($f['seen']) < 5000) $f['seen'][$who] = 1;
        $f['days'][$day] = (int) ($f['days'][$day] ?? 0) + 1;
        $f['days'] = array_slice($f['days'] ?? [], -10, null, true);
        update_option('magesentinel_monitor_funnel', $f, false);
    }

    /** @return array{started:int,since:int} Checkout visits of the last 7 days of the store's calendar, today included. */
    public static function checkout_visits(): array
    {
        $f = get_option('magesentinel_monitor_funnel', []);
        $n = 0;
        for ($d = 0; $d < 7; $d++) $n += (int) ($f['days'][wp_date('Y-m-d', time() - $d * DAY_IN_SECONDS)] ?? 0);
        return ['started' => $n, 'since' => (int) ($f['since'] ?? 0)];
    }

    /**
     * Products the catalogue offers as in stock that nobody can put in the
     * cart: a simple product without a price, or a variable one none of whose
     * variations has a price. Read twice a day.
     *
     * @return array{count:int,items:array<int,array{id:int,title:string}>}
     */
    public static function unbuyable(): array
    {
        $c = get_transient('magesentinel_monitor_unbuyable');
        if (is_array($c)) return $c;
        global $wpdb;
        // Cached in the magesentinel_monitor_unbuyable transient above; no WP API answers this join.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT p.ID AS id, p.post_title AS title FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value IN ('instock','onbackorder')
             JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
             JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND ((t.slug = 'simple' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pr WHERE pr.post_id = p.ID AND pr.meta_key = '_price' AND pr.meta_value <> ''))
                 OR (t.slug = 'variable' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} vp ON vp.post_id = v.ID AND vp.meta_key = '_price' AND vp.meta_value <> ''
                        WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status = 'publish')))
             GROUP BY p.ID ORDER BY p.post_modified DESC LIMIT 201",
            ARRAY_A
        );
        $rows = is_array($rows) ? $rows : [];
        $out = ['count' => count($rows), 'items' => array_map(static function ($r) { return ['id' => (int) $r['id'], 'title' => (string) $r['title']]; }, array_slice($rows, 0, 5))];
        set_transient('magesentinel_monitor_unbuyable', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Enabled payment methods still in test or sandbox mode: on a live store
     * they decline real cards, or take the order without taking the money.
     * Read from each gateway's own switch, never its keys.
     *
     * @return array<int,array{code:string,title:string}>
     */
    private static function test_mode_gateways(): array
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) return [];
        $yes = static function ($v): bool {
            return $v === true || $v === 1 || (is_string($v) && in_array(strtolower($v), ['yes', '1', 'on', 'true', 'sandbox', 'test', 'testing'], true));
        };
        $out = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
            if ($gw->enabled !== 'yes') continue;
            $test = false;
            foreach (['testmode', 'test_mode', 'sandbox', 'sandbox_mode', 'is_sandbox', 'use_sandbox'] as $k) {
                if ((isset($gw->settings[$k]) && $yes($gw->settings[$k])) || (property_exists($gw, $k) && $yes($gw->$k))) { $test = true; break; }
            }
            foreach (['environment', 'mode'] as $k) {
                if (isset($gw->settings[$k]) && in_array(strtolower((string) $gw->settings[$k]), ['sandbox', 'test', 'testing'], true)) $test = true;
            }
            // PayPal Payments keeps its switch outside the gateway's settings.
            if (strpos((string) $id, 'ppcp') === 0) {
                $ppcp = get_option('woocommerce-ppcp-settings', []);
                $common = get_option('woocommerce-ppcp-data-common', []);
                if ((is_array($ppcp) && !empty($ppcp['sandbox_on'])) || (is_array($common) && !empty($common['use_sandbox']))) $test = true;
            }
            // WooPayments: its own test and sandbox (dev) modes.
            if ($id === 'woocommerce_payments' && class_exists('WC_Payments') && method_exists('WC_Payments', 'mode')) {
                try { $m = WC_Payments::mode(); if ($m->is_test() || (method_exists($m, 'is_dev') && $m->is_dev())) $test = true; } catch (Throwable $e) {}
            }
            if ($test) $out[] = ['code' => (string) $id, 'title' => wp_strip_all_tags((string) $gw->get_title()) ?: (string) $id];
        }
        return $out;
    }

    /**
     * Updates of what a purchase runs through: WooCommerce, the theme, and
     * the plugins behind checkout, payment and shipping. Recorded by name and
     * version; the most common reason a checkout that worked yesterday breaks.
     */
    public static function record_update($upgrader, $extra): void
    {
        if (!is_array($extra) || ($extra['action'] ?? '') !== 'update') return;
        $items = [];
        if (($extra['type'] ?? '') === 'plugin') {
            if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $files = isset($extra['plugins']) ? (array) $extra['plugins'] : (isset($extra['plugin']) ? [$extra['plugin']] : []);
            foreach ($files as $f) {
                $f = (string) $f;
                $path = WP_PLUGIN_DIR . '/' . $f;
                $d = is_file($path) ? get_plugin_data($path, false, false) : [];
                $items[] = ['kind' => 'plugin', 'id' => $f, 'name' => !empty($d['Name']) ? (string) $d['Name'] : dirname($f), 'version' => (string) ($d['Version'] ?? '')];
            }
        } elseif (($extra['type'] ?? '') === 'theme') {
            $themes = isset($extra['themes']) ? (array) $extra['themes'] : (isset($extra['theme']) ? [$extra['theme']] : []);
            foreach ($themes as $t) {
                $th = wp_get_theme((string) $t);
                $items[] = ['kind' => 'theme', 'id' => (string) $t, 'name' => (string) ($th->get('Name') ?: $t), 'version' => (string) $th->get('Version')];
            }
        }
        if (!$items) return;
        $log = get_option('magesentinel_monitor_updates', []);
        if (!is_array($log)) $log = [];
        $relevant = false;
        foreach ($items as $it) {
            $it['checkout'] = self::checkout_relevant($it['kind'], $it['id']);
            $it['at'] = time();
            $relevant = $relevant || $it['checkout'];
            array_unshift($log, $it);
        }
        update_option('magesentinel_monitor_updates', array_slice($log, 0, 20), false);

        // Connected: ask MageSentinel for a test purchase once the update has
        // settled, instead of waiting for the next scheduled check. Two minutes
        // later from WP-Cron, never inside the update itself: the upgrader must
        // not wait on an outside call, the new code has to be the one loaded,
        // and a bulk update fires this once per plugin but schedules one check.
        if ($relevant && self::connected() && !wp_next_scheduled(self::AFTER_UPDATE_HOOK)) {
            wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, self::AFTER_UPDATE_HOOK);
        }
    }

    /** The connector is switched on and holds a token. */
    public static function connected(): bool
    {
        $s = self::settings();
        return $s['enabled'] && $s['token_hash'] !== '';
    }

    /**
     * The check after an update: at most one every 15 minutes from here, and
     * MageSentinel still applies its own start limit and the plan's daily
     * budget. The answer is kept so the overview can say the check started.
     */
    public static function after_update_check(): void
    {
        if (!self::connected() || get_transient('magesentinel_monitor_after_update_lock')) return;
        set_transient('magesentinel_monitor_after_update_lock', 1, 15 * MINUTE_IN_SECONDS);
        $r = MageSentinel_Monitor_Admin::request_check('update');
        update_option(self::AFTER_UPDATE_STATE, [
            'at' => time(),
            'code' => $r['code'],
            'job' => isset($r['body']['data']['jobId']) ? (string) $r['body']['data']['jobId'] : '',
        ], false);
    }

    /** @return array{at:int,code:int,job:string} */
    public static function after_update_state(): array
    {
        $s = get_option(self::AFTER_UPDATE_STATE, []);
        return [
            'at' => isset($s['at']) ? (int) $s['at'] : 0,
            'code' => isset($s['code']) ? (int) $s['code'] : 0,
            'job' => isset($s['job']) ? (string) $s['job'] : '',
        ];
    }

    private static function checkout_relevant(string $kind, string $id): bool
    {
        if ($kind === 'theme') return in_array($id, [get_stylesheet(), get_template()], true);
        $dir = strtok($id, '/');
        if ($dir === 'woocommerce') return true;
        if (preg_match('/pay|stripe|klarna|viva|mollie|square|checkout|cart|shipping|courier|gateway|bank|cardlink|revolut|adyen|braintree|acs|elta|geniki|boxnow|skroutz/i', (string) $dir)) return true;
        // The plugin that ships an enabled payment or shipping method, whatever it is called.
        if (function_exists('WC') && WC()->payment_gateways()) {
            $root = wp_normalize_path(WP_PLUGIN_DIR . '/' . $dir . '/');
            foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
                if ($gw->enabled !== 'yes') continue;
                try { $file = (new ReflectionClass($gw))->getFileName(); } catch (Throwable $e) { continue; }
                if ($file && strpos(wp_normalize_path($file), $root) === 0) return true;
            }
        }
        return false;
    }

    /** @return array<int,array{kind:string,id:string,name:string,version:string,checkout:bool,at:int}> */
    public static function recent_updates(int $seconds): array
    {
        $log = get_option('magesentinel_monitor_updates', []);
        $since = time() - $seconds;
        return array_values(array_filter(is_array($log) ? $log : [], static function ($u) use ($since) {
            return is_array($u) && (int) ($u['at'] ?? 0) >= $since;
        }));
    }

    /** @return array<int,array<string,mixed>> */
    public static function test_orders_since(int $ts): array
    {
        return array_values(array_filter(self::activity()['orders'], static function ($o) use ($ts) {
            return (int) ($o['placed_at'] ?? 0) >= $ts;
        }));
    }

    /**
     * Whether WooCommerce itself would offer this enabled gateway, the
     * gateway's own is_available(): false for a gateway left without API keys,
     * outside its currency, or switched off by its own rules. Asked outside a
     * checkout, a gateway whose answer depends on the cart (a minimum amount,
     * the chosen shipping method) may answer for an empty cart, and one that
     * needs the session may throw — that is null, "cannot tell", never false.
     * Read by MageSentinel as evidence behind a failed payment step only; it
     * never turns a health check red on its own.
     */
    private static function gateway_available($gw): ?bool
    {
        if (!is_object($gw) || !method_exists($gw, 'is_available')) return null;
        try {
            return (bool) $gw->is_available();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * How the cart or checkout page is built: the WooCommerce block, the
     * classic shortcode, or something else (a page builder, a custom
     * template). The two WooCommerce versions share almost no markup, so a
     * change between them explains a checkout that breaks without any update.
     */
    private static function page_kind(string $page, string $block, string $shortcode): ?string
    {
        $id = function_exists('wc_get_page_id') ? (int) wc_get_page_id($page) : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (!$post) return null;
        $content = (string) $post->post_content;
        if (function_exists('has_block') && has_block($block, $post)) return 'blocks';
        if (function_exists('has_shortcode') && has_shortcode($content, $shortcode)) return 'shortcode';
        return 'other';
    }

    private static function page_ok(string $page): bool
    {
        $id = function_exists('wc_get_page_id') ? (int) wc_get_page_id($page) : 0;
        return $id > 0 && get_post_status($id) === 'publish';
    }

    private static function orders_since(string $status, int $seconds): ?int
    {
        if (!function_exists('wc_get_orders')) return null;
        global $wpdb;
        $q = self::order_sql();
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count; names come from order_sql()
        $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} = %s AND " . self::date_sql($q, $now - $seconds, $now), 'wc-' . $status));
        return $n === null ? null : (int) $n;
    }

    /** WP-Cron and WooCommerce's Action Scheduler: emails, webhooks and stock sync run through them. */
    private static function scheduler(): array
    {
        $now = time();
        $overdue = 0;
        $oldest = null;
        foreach ((array) _get_cron_array() as $ts => $hooks) {
            if ((int) $ts > $now - 600) continue;
            $overdue += count((array) $hooks);
            $oldest = $oldest === null ? (int) $ts : min($oldest, (int) $ts);
        }
        $out = [
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'wp_cron_overdue' => $overdue,
            'wp_cron_oldest_overdue_minutes' => $oldest === null ? null : (int) floor(($now - $oldest) / 60),
        ];
        if (function_exists('as_get_scheduled_actions')) {
            $count = static function (array $args): int {
                return count(as_get_scheduled_actions($args + ['per_page' => 500, 'return_format' => 'ids'], 'ids'));
            };
            // WooCommerce's own housekeeping (fetching block patterns, admin
            // notes, marketing feeds) fails whenever wordpress.org is slow and
            // touches nothing a shopper does, so it does not count.
            $failed = as_get_scheduled_actions(['status' => 'failed', 'date' => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS), 'date_compare' => '>=', 'per_page' => 500], 'ids');
            $hooks = [];
            foreach ($failed as $id) {
                $hook = class_exists('ActionScheduler') ? (string) ActionScheduler::store()->fetch_action($id)->get_hook() : '';
                if (preg_match('/^(fetch_patterns|wc[-_]admin|woocommerce_admin|woocommerce_run_on_woocommerce_admin|wc_admin_|woocommerce_cleanup_|woocommerce_remote_|woocommerce_marketing)/', $hook)) continue;
                $hooks[$hook] = ($hooks[$hook] ?? 0) + 1;
            }
            arsort($hooks);
            $out['actions_failed_24h'] = array_sum($hooks);
            $out['actions_failed_hooks'] = array_slice(array_keys($hooks), 0, 3);
            $out['actions_past_due'] = $count(['status' => 'pending', 'date' => gmdate('Y-m-d H:i:s', $now - HOUR_IN_SECONDS), 'date_compare' => '<=']);
        }
        return $out;
    }

    /**
     * WooCommerce's own record of PHP fatal errors, as a count. Never the
     * content: a fatal error log line can carry what a customer submitted.
     */
    private static function fatal_errors(): ?array
    {
        $dir = defined('WC_LOG_DIR') ? WC_LOG_DIR : wp_upload_dir()['basedir'] . '/wc-logs/';
        $files = glob(trailingslashit($dir) . 'fatal-errors-*.log') ?: [];
        $since = time() - DAY_IN_SECONDS;
        $total = 0;
        $newest = null;
        $resolved = 0;
        $sources = [];
        $active = array_map(static function ($f) { return strtok($f, '/'); }, (array) get_option('active_plugins', []));
        foreach ($files as $file) {
            if (@filemtime($file) < $since) continue;
            try {
                $h = new SplFileObject($file, 'r');
            } catch (Exception $e) {
                continue;
            }
            while (!$h->eof()) {
                $line = (string) $h->fgets();
                if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s/', $line, $m)) continue;
                $at = strtotime($m[1]);
                if ($at === false || $at < $since) continue;
                // Only the plugin or theme folder name is kept, never the message.
                $source = 'wordpress';
                if (preg_match('#/wp-content/(plugins|themes)/([^/\s]+)/#', $line, $sm)) {
                    $source = $sm[2];
                    // A plugin that has since been deactivated can no longer crash the store.
                    if ($sm[1] === 'plugins' && !in_array($sm[2], $active, true)) {
                        $resolved++;
                        continue;
                    }
                }
                $total++;
                $sources[$source] = ($sources[$source] ?? 0) + 1;
                $newest = $newest === null ? $at : max($newest, $at);
            }
            $h = null;
        }
        arsort($sources);
        return [
            'window_hours' => 24,
            'total' => $total,
            'by_type' => array_map(static function ($src, $n) { return ['type' => 'PHP fatal error in ' . $src, 'count' => $n]; }, array_keys($sources), array_values($sources)),
            'newest_at' => $newest === null ? null : gmdate('c', $newest),
            'resolved_inactive' => $resolved,
        ];
    }

    /**
     * Where orders live and what their columns are called, for the read-only
     * aggregate queries below: the HPOS table when it is the authoritative
     * store, the posts table otherwise. On the posts table every column that
     * is meta costs a join per order, so 'from' joins only what the filter
     * needs and 'from_total' adds the order total for revenue.
     *
     * @return array{from:string,from_total:string,id:string,date:string,status:string,total:string,email:string,where:string,hpos:bool}
     */
    public static function order_sql(): array
    {
        global $wpdb;
        $hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        if ($hpos) {
            return [
                'from' => "{$wpdb->prefix}wc_orders o",
                'from_total' => "{$wpdb->prefix}wc_orders o",
                'id' => 'o.id',
                'date' => 'o.date_created_gmt',
                'status' => 'o.status',
                'total' => 'o.total_amount',
                'email' => 'o.billing_email',
                'where' => "o.type = 'shop_order'",
                'hpos' => true,
            ];
        }
        // The billing email is needed only while the test-order list is not built yet.
        $from = "{$wpdb->posts} o" . (self::test_order_ids() === null ? " LEFT JOIN {$wpdb->postmeta} e ON e.post_id = o.ID AND e.meta_key = '_billing_email'" : '');
        return [
            'from' => $from,
            'from_total' => $from . " LEFT JOIN {$wpdb->postmeta} t ON t.post_id = o.ID AND t.meta_key = '_order_total'",
            'id' => 'o.ID',
            'date' => 'o.post_date_gmt',
            'status' => 'o.post_status',
            'total' => 't.meta_value',
            'email' => 'e.meta_value',
            'where' => "o.post_type = 'shop_order'",
            'hpos' => false,
        ];
    }

    /**
     * SQL that keeps real orders only: a registered order status (what
     * wc_get_orders() returns by default) and not one of MageSentinel's own
     * test orders, left out by id (primary key) from the list kept below,
     * or by billing email until that list is built.
     */
    public static function real_order_sql(array $q): string
    {
        global $wpdb;
        $statuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];
        $in = implode(',', array_map(static function ($s) use ($wpdb) { return $wpdb->prepare('%s', $s); }, $statuses));
        $sql = "{$q['where']} AND {$q['status']} IN ({$in})";
        $ids = self::test_order_ids();
        if ($ids === null) {
            return $sql . " AND COALESCE({$q['email']}, '') NOT LIKE 'magesentinel+%@%' AND COALESCE({$q['email']}, '') NOT LIKE 'magesentinel.%@%'";
        }
        return $ids ? $sql . " AND {$q['id']} NOT IN (" . implode(',', array_map('intval', $ids)) . ')' : $sql;
    }

    /**
     * MageSentinel's own test orders of the last TEST_IDS_DAYS days, by id.
     * Recorded as they are created; built once from the billing emails the
     * first time it is needed (the only email scan). Null while it cannot be
     * built, and the queries then fall back to matching the email.
     *
     * @return int[]|null
     */
    public static function test_order_ids(): ?array
    {
        if (self::$test_ids_memo !== null) return self::$test_ids_memo;
        $list = get_option(self::TEST_IDS);
        if (!is_array($list) || !isset($list['ids'])) {
            $list = self::build_test_order_ids();
            if ($list === null) return null;
        }
        $cut = time() - self::TEST_IDS_DAYS * DAY_IN_SECONDS;
        $ids = array_keys(array_filter($list['ids'], static function ($ts) use ($cut) { return $ts >= $cut; }));
        self::$test_ids_memo = array_map('intval', $ids);
        return self::$test_ids_memo;
    }

    /** One scan by billing email over the window, then the list is kept up to date by remember_test_order(). */
    private static function build_test_order_ids(): ?array
    {
        global $wpdb;
        if (!function_exists('wc_get_orders')) return null;
        $hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $since = gmdate('Y-m-d H:i:s', time() - self::TEST_IDS_DAYS * DAY_IN_SECONDS);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off read-only scan
        $rows = $hpos
            ? $wpdb->get_results($wpdb->prepare("SELECT id, TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', date_created_gmt) AS ts FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND date_created_gmt > %s AND (billing_email LIKE 'magesentinel+%%@%%' OR billing_email LIKE 'magesentinel.%%@%%')", $since), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare("SELECT o.ID AS id, TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', o.post_date_gmt) AS ts FROM {$wpdb->posts} o JOIN {$wpdb->postmeta} e ON e.post_id = o.ID AND e.meta_key = '_billing_email' WHERE o.post_type = 'shop_order' AND o.post_date > %s AND o.post_date_gmt > %s AND (e.meta_value LIKE 'magesentinel+%%@%%' OR e.meta_value LIKE 'magesentinel.%%@%%')", gmdate('Y-m-d H:i:s', time() - self::TEST_IDS_DAYS * DAY_IN_SECONDS - 14 * HOUR_IN_SECONDS), $since), ARRAY_A);
        // phpcs:enable
        if ($wpdb->last_error) return null;
        $list = ['ids' => []];
        foreach ((array) $rows as $r) $list['ids'][(int) $r['id']] = (int) $r['ts'];
        update_option(self::TEST_IDS, $list, false);
        return $list;
    }

    /** Hooked to order creation and saves: a test order goes on the list the moment its email says so. */
    public static function remember_test_order($orderId, $order = null): void
    {
        $list = get_option(self::TEST_IDS);
        if (!is_array($list) || !isset($list['ids'])) return; // built on first use, from the emails
        // The posts store passes only the id on save; the order was just saved, so this is a cache hit.
        if (!$order instanceof WC_Order && function_exists('wc_get_order')) $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order || !self::is_test_order($order)) return;
        $id = (int) $order->get_id();
        if (isset($list['ids'][$id])) return;
        $created = $order->get_date_created();
        $list['ids'][$id] = $created ? $created->getTimestamp() : time();
        $cut = time() - self::TEST_IDS_DAYS * DAY_IN_SECONDS;
        $list['ids'] = array_filter($list['ids'], static function ($ts) use ($cut) { return $ts >= $cut; });
        update_option(self::TEST_IDS, $list, false);
        if (self::$test_ids_memo !== null) self::$test_ids_memo[] = $id;
    }

    /**
     * Orders created in (from, to]. On the posts store the GMT date is not in
     * the type_status_date index, so the local post_date (which is) narrows
     * the range first; 14 hours either side covers every timezone offset.
     */
    public static function date_sql(array $q, int $from, int $to): string
    {
        global $wpdb;
        $sql = $wpdb->prepare("{$q['date']} > %s AND {$q['date']} <= %s", gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (empty($q['hpos'])) {
            $sql .= $wpdb->prepare(' AND o.post_date BETWEEN %s AND %s', gmdate('Y-m-d H:i:s', $from - 14 * HOUR_IN_SECONDS), gmdate('Y-m-d H:i:s', $to + 14 * HOUR_IN_SECONDS));
        }
        return $sql;
    }

    /**
     * Real trade as counts only, never order rows: hourly for the last day,
     * daily for the last week, and the payment state of the last day's orders.
     * MageSentinel's own test orders are left out so they cannot pass for trade.
     *
     * One grouped query (hour x status) instead of loading the week's orders
     * as objects: the cost no longer grows with the number of orders.
     */
    public static function sales(): array
    {
        if (!function_exists('wc_get_orders')) return ['error' => 'woocommerce not loaded'];
        global $wpdb;
        try {
            $now = time();
            $q = self::order_sql();
            $dayAgo = gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
            $bucketSql = static function (int $from, int $to) use ($wpdb, $q, $dayAgo) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate; table and column names come from order_sql()
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT TIMESTAMPDIFF(HOUR, '1970-01-01 00:00:00', {$q['date']}) AS h, {$q['status']} AS status,
                            ({$q['date']} > %s) AS recent, COUNT(*) AS n, SUM({$q['total']}) AS total
                     FROM {$q['from_total']}
                     WHERE " . self::real_order_sql($q) . " AND " . self::date_sql($q, $from, $to) . "
                     GROUP BY h, status, recent",
                    $dayAgo
                ), ARRAY_A);
                // phpcs:enable
                if ($wpdb->last_error) throw new RuntimeException('sales query');
                return (array) $rows;
            };
            // The six days before the last 24 hours change little: read once an
            // hour and kept, so each run reads only the last day afresh. A refund
            // on an older day shows within the hour.
            $firstDay = (int) strtotime(gmdate('Y-m-d', $now - 6 * DAY_IN_SECONDS) . ' 00:00:00 UTC');
            $closed = get_transient(self::SALES_CLOSED);
            if (!is_array($closed) || ($closed['first_day'] ?? 0) !== $firstDay || !isset($closed['until'], $closed['rows'])) {
                $until = intdiv($now - DAY_IN_SECONDS, HOUR_IN_SECONDS) * HOUR_IN_SECONDS;
                $closed = ['first_day' => $firstDay, 'until' => $until, 'rows' => $bucketSql($firstDay - 1, $until)];
                set_transient(self::SALES_CLOSED, $closed, HOUR_IN_SECONDS);
            }
            $buckets = array_merge($closed['rows'], $bucketSql((int) $closed['until'], $now));
            $lastWeek = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$q['from']} WHERE " . self::real_order_sql($q) . " AND " . self::date_sql($q, $now - 8 * DAY_IN_SECONDS, $now - 7 * DAY_IN_SECONDS));
            // The last order: only inside the newest hour the grouped query found (a tiny index range); the whole history only when the week was empty.
            $newest = $buckets ? max(array_map('intval', array_column($buckets, 'h'))) * HOUR_IN_SECONDS : null;
            $lastAtSql = $newest !== null
                ? $wpdb->get_var("SELECT MAX({$q['date']}) FROM {$q['from']} WHERE " . self::real_order_sql($q) . " AND " . self::date_sql($q, $newest - 1, $newest + HOUR_IN_SECONDS))
                : $wpdb->get_var("SELECT MAX({$q['date']}) FROM {$q['from']} WHERE " . self::real_order_sql($q));
            // phpcs:enable

            $hours = [];
            $days = [];
            for ($d = 6; $d >= 0; $d--) $days[gmdate('Y-m-d', $now - $d * DAY_IN_SECONDS)] = ['orders' => 0, 'revenue' => 0.0];
            $states = [];
            $total24h = 0;
            $revenue24h = 0.0;
            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null;
            foreach ((array) $buckets as $b) {
                $ts = (int) $b['h'] * HOUR_IN_SECONDS;
                $n = (int) $b['n'];
                $status = (string) preg_replace('/^wc-/', '', (string) $b['status']);
                $counts = !in_array($status, ['cancelled', 'failed', 'refunded', 'checkout-draft'], true);
                $day = gmdate('Y-m-d', $ts);
                if (isset($days[$day])) {
                    $days[$day]['orders'] += $n;
                    if ($counts) $days[$day]['revenue'] += (float) $b['total'];
                }
                if (!(int) $b['recent']) continue;
                $total24h += $n;
                if ($counts) $revenue24h += (float) $b['total'];
                $hour = gmdate('Y-m-d\TH:00:00\Z', $ts);
                $hours[$hour] = ($hours[$hour] ?? 0) + $n;
                // pending = created and never paid: the state a broken card gateway leaves behind.
                $state = $status === 'pending' ? 'pending_payment' : str_replace('-', '_', $status);
                $states[$state] = ($states[$state] ?? 0) + $n;
            }
            ksort($hours);
            $last24h = [];
            foreach ($hours as $hour => $count) $last24h[] = ['hour' => $hour, 'count' => $count];
            $last7d = [];
            foreach ($days as $day => $v) $last7d[] = ['day' => $day, 'count' => $v['orders'], 'revenue' => round($v['revenue'], 2)];

            $lastTs = $lastAtSql ? strtotime($lastAtSql . ' UTC') : false;
            return [
                'last_24h' => $last24h,
                'last_7d' => $last7d,
                'total_24h' => $total24h,
                'revenue_24h' => round($revenue24h, 2),
                'currency' => $currency,
                'same_window_last_week' => $lastWeek,
                'last_order_at' => $lastTs ? gmdate('c', $lastTs) : null,
                'minutes_since_last_order' => $lastTs ? (int) floor(($now - $lastTs) / 60) : null,
                'payment_stuck' => $total24h > 0 ? [
                    'by_state' => $states,
                    'stuck' => $states['pending_payment'] ?? 0,
                    'total' => $total24h,
                ] : null,
            ];
        } catch (Throwable $e) {
            return ['error' => 'sales could not be read'];
        }
    }

    /**
     * How many real orders this store usually takes in each hour of the week
     * (site timezone), averaged over the last four weeks. Keyed "N-G": ISO
     * weekday 1-7 and hour 0-23. Null when it cannot be read. Cached six
     * hours: a four-week average barely moves in that time.
     *
     * @return array<string,float>|null
     */
    public static function hourly_baseline(): ?array
    {
        $cached = get_transient('magesentinel_monitor_baseline');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $q = self::order_sql();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate, cached for an hour
        $rows = $wpdb->get_results(
            "SELECT TIMESTAMPDIFF(HOUR, '1970-01-01 00:00:00', {$q['date']}) AS h, COUNT(*) AS n
             FROM {$q['from']}
             WHERE " . self::real_order_sql($q) . " AND {$q['status']} NOT IN ('wc-checkout-draft') AND " . self::date_sql($q, time() - self::BASELINE_WEEKS * WEEK_IN_SECONDS, time()) . "
             GROUP BY h",
            ARRAY_A
        );
        // phpcs:enable
        if ($wpdb->last_error) return null;
        $tz = wp_timezone();
        $out = [];
        foreach ((array) $rows as $r) {
            $key = (new DateTimeImmutable('@' . ((int) $r['h'] * HOUR_IN_SECONDS)))->setTimezone($tz)->format('N-G');
            $out[$key] = ($out[$key] ?? 0) + (int) $r['n'] / self::BASELINE_WEEKS;
        }
        set_transient('magesentinel_monitor_baseline', $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Revenue per store day for this week and the one before, for the
     * "Revenue this week vs last week" card. Test orders, cancelled, failed
     * and refunded orders are left out, as in the other revenue figures.
     *
     * @return array{this:float[],last:float[],days:string[],currency:string}
     */
    public static function revenue_weeks(): array
    {
        $cached = get_transient('magesentinel_monitor_revenue_weeks');
        if (is_array($cached)) return $cached;
        $tz = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);
        $this_ = array_fill(0, 7, 0.0);
        $last = array_fill(0, 7, 0.0);
        $days = [];
        for ($i = 6; $i >= 0; $i--) $days[] = $today->modify("-$i days")->format('Y-m-d');
        if (function_exists('wc_get_orders')) {
            $from = $today->modify('-13 days')->getTimestamp();
            foreach (wc_get_orders(['limit' => -1, 'type' => 'shop_order', 'date_created' => $from . '...' . time()]) as $order) {
                if (!$order instanceof WC_Order) continue;
                if (preg_match(self::SYNTHETIC_EMAIL, (string) $order->get_billing_email()) === 1) continue;
                if (in_array($order->get_status(), ['cancelled', 'failed', 'refunded', 'checkout-draft'], true)) continue;
                $created = $order->get_date_created();
                if (!$created) continue;
                $day = (new DateTimeImmutable('@' . $created->getTimestamp()))->setTimezone($tz)->setTime(0, 0);
                $ago = (int) $day->diff($today)->format('%a');
                if ($ago <= 6) $this_[6 - $ago] += (float) $order->get_total();
                elseif ($ago <= 13) $last[13 - $ago] += (float) $order->get_total();
            }
        }
        $out = [
            'this' => array_map(static function ($v) { return round($v, 2); }, $this_),
            'last' => array_map(static function ($v) { return round($v, 2); }, $last),
            'days' => $days,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
        ];
        set_transient('magesentinel_monitor_revenue_weeks', $out, 10 * MINUTE_IN_SECONDS);
        return $out;
    }

    /**
     * Products that sold in the last 30 days and now have none (or almost
     * none) left: money walking away. With a rough estimate of what the
     * sold-out ones would bring in for the rest of the month at the same pace.
     *
     * @return array{items:array<int,array{id:int,name:string,sold:int,left:int,url:string}>,missed:float,currency:string}
     */
    public static function selling_out_of_stock(): array
    {
        $cached = get_transient('magesentinel_monitor_oos_selling');
        if (is_array($cached)) return $cached;
        $sold = [];
        $revenue = [];
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders(['limit' => -1, 'type' => 'shop_order', 'status' => ['wc-processing', 'wc-completed', 'wc-on-hold'], 'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS)]);
            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) continue;
                if (preg_match(self::SYNTHETIC_EMAIL, (string) $order->get_billing_email()) === 1) continue;
                foreach ($order->get_items() as $item) {
                    if (!$item instanceof WC_Order_Item_Product) continue;
                    $id = (int) ($item->get_variation_id() ?: $item->get_product_id());
                    if (!$id) continue;
                    $sold[$id] = ($sold[$id] ?? 0) + (int) $item->get_quantity();
                    $revenue[$id] = ($revenue[$id] ?? 0) + (float) $item->get_total();
                }
            }
        }
        $items = [];
        $missed = 0.0;
        $threshold = max(1, (int) get_option('woocommerce_notify_low_stock_amount', 2));
        $daysLeft = (int) wp_date('t') - (int) wp_date('j') + 1;
        foreach ($sold as $id => $qty) {
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
            if (!$product || $product->get_status() !== 'publish' && !$product->is_type('variation')) continue;
            $out = $product->get_stock_status() === 'outofstock';
            $left = $product->managing_stock() ? max(0, (int) $product->get_stock_quantity()) : ($out ? 0 : null);
            if (!$out && ($left === null || $left > $threshold)) continue;
            if ($out) { $left = 0; $missed += $revenue[$id] / 30 * $daysLeft; }
            $parent = $product->is_type('variation') ? $product->get_parent_id() : $id;
            $items[] = ['id' => $id, 'name' => wp_strip_all_tags($product->get_name()), 'sold' => $qty, 'left' => (int) $left, 'url' => (string) get_edit_post_link($parent, 'raw')];
        }
        usort($items, static function ($a, $b) { return [$a['left'], -$a['sold']] <=> [$b['left'], -$b['sold']]; });
        $out = [
            'items' => array_slice($items, 0, 5),
            'more' => max(0, count($items) - 5),
            'missed' => round($missed),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
        ];
        set_transient('magesentinel_monitor_oos_selling', $out, HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Record a health reading in its store day; seven days are kept. Each day
     * keeps its worst result and the checks behind it, how many readings had
     * no problem, and the worst result of each hour as 24 characters (-, o, w, b).
     */
    public static function record_health_day(array $rows): void
    {
        $rank = ['good' => 0, 'watch' => 1, 'bad' => 2];
        $worst = 'good';
        $bad = 0;
        $what = [];
        foreach ($rows as $r) {
            $st = (string) ($r['state'] ?? 'good');
            if (($rank[$st] ?? 0) > $rank[$worst]) $worst = $st;
            if ($st === 'bad') { $bad++; $what[] = (string) ($r['label'] ?? ''); }
        }
        $day = wp_date('Y-m-d');
        $hour = (int) wp_date('G');
        $log = get_option(self::HEALTH_DAYS, []);
        $log = is_array($log) ? $log : [];
        $prev = $log[$day] ?? ['state' => 'good', 'bad' => 0, 'checks' => 0, 'clean' => 0, 'h' => '', 'what' => []];
        $entry = $prev;
        if (!isset($log[$day]) || $rank[$worst] > ($rank[$prev['state']] ?? 0) || ($worst === $prev['state'] && $bad > (int) $prev['bad'])) {
            $entry['state'] = $worst;
            $entry['bad'] = $bad;
            $entry['what'] = $what;
        }
        // A day begun before 3.4 counted readings but not clean ones: count both afresh.
        $counted = isset($prev['h'], $prev['clean']);
        $entry['checks'] = ($counted ? (int) $prev['checks'] : 0) + 1;
        $entry['clean'] = ($counted ? (int) $prev['clean'] : 0) + ($worst === 'bad' ? 0 : 1);
        $hours = str_pad((string) ($prev['h'] ?? ''), 24, '-');
        $mark = ['good' => 'o', 'watch' => 'w', 'bad' => 'b'][$worst];
        $order = ['-' => -1, 'o' => 0, 'w' => 1, 'b' => 2];
        if ($order[$mark] > ($order[$hours[$hour]] ?? -1)) $hours[$hour] = $mark;
        $entry['h'] = $hours;
        $log[$day] = $entry;
        ksort($log);
        update_option(self::HEALTH_DAYS, array_slice($log, -7, null, true), false);
    }

    /** @return array<int,array{day:string,state:?string,bad:int,what:string[],n:int,clean:int,h:string}> the last 7 store days, oldest first; state null = no check that day. */
    public static function health_days(): array
    {
        $log = get_option(self::HEALTH_DAYS, []);
        $log = is_array($log) ? $log : [];
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $e = $log[$day] ?? [];
            // Days recorded before 3.4 have no hours or clean count: show them as a day, not as 0% clean.
            $hasHours = isset($e['h'], $e['clean']);
            $out[] = [
                'day' => $day, 'state' => $e['state'] ?? null, 'bad' => (int) ($e['bad'] ?? 0),
                'what' => array_values(array_filter((array) ($e['what'] ?? []))),
                'n' => $hasHours ? (int) ($e['checks'] ?? 0) : 0, 'clean' => $hasHours ? (int) $e['clean'] : 0,
                'h' => $hasHours ? (string) $e['h'] : '',
            ];
        }
        return $out;
    }

    /** A finished free test purchase, kept for the Checkout speed card (30 at most). */
    public static function record_free_speed(string $id, string $status, int $ms, bool $slow): void
    {
        $log = get_option(self::FREE_SPEED, []);
        $log = is_array($log) ? $log : [];
        foreach ($log as $r) if (($r['id'] ?? '') === $id) return;
        $log[] = ['id' => $id, 'at' => gmdate('c'), 'status' => $status, 'ms' => $ms, 'slow' => $slow];
        update_option(self::FREE_SPEED, array_slice($log, -30), false);
    }

    /** @return array<int,array{at:string,status:string,ms:int,slow:bool}> */
    public static function free_speed(): array
    {
        $log = get_option(self::FREE_SPEED, []);
        return is_array($log) ? array_values($log) : [];
    }

    /** The monitor gets order counts, never money: revenue stays on this site's own overview. */
    private static function sales_for_monitor(): array
    {
        $sales = self::sales();
        unset($sales['revenue_24h']);
        if (isset($sales['last_7d'])) {
            foreach ($sales['last_7d'] as &$day) unset($day['revenue']);
            unset($day);
        }
        return $sales;
    }

    /** What is on sale and what cannot be bought. Counts only. */
    public static function catalog(): array
    {
        global $wpdb;
        $published = (int) (wp_count_posts('product')->publish ?? 0);
        $meta = static function (string $value) use ($wpdb): int {
            // Stock counts for the diagnostics snapshot, which is itself cached; no WP API counts these.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status'
                 WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND m.meta_value = %s",
                $value
            ));
        };
        $threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $low = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock'
             JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes'
             JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = '_stock'
             WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND CAST(q.meta_value AS SIGNED) <= %d",
            $threshold
        ));
        return [
            'published' => $published,
            'out_of_stock' => $meta('outofstock'),
            'on_backorder' => $meta('onbackorder'),
            'low_stock' => $low,
            'low_stock_threshold' => $threshold,
        ];
    }

    /** Pending core, WooCommerce and plugin updates: the usual source of a checkout that breaks overnight. */
    public static function updates(): array
    {
        global $wp_version;
        $plugins = [];
        $t = get_site_transient('update_plugins');
        if (is_object($t) && !empty($t->response)) {
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all = get_plugins();
            foreach ($t->response as $file => $info) {
                if (!isset($all[$file]) || !is_plugin_active($file)) continue;
                $plugins[] = ['name' => $all[$file]['Name'], 'version' => $all[$file]['Version'], 'new_version' => $info->new_version ?? null];
            }
        }
        $core = get_site_transient('update_core');
        $coreNew = null;
        if (is_object($core) && !empty($core->updates)) {
            foreach ($core->updates as $u) {
                if (($u->response ?? '') === 'upgrade') { $coreNew = $u->current ?? null; break; }
            }
        }
        return [
            'wordpress' => $wp_version,
            'wordpress_update' => $coreNew,
            'plugins' => $plugins,
            'checked_at' => is_object($t) && !empty($t->last_checked) ? gmdate('c', (int) $t->last_checked) : null,
        ];
    }

    /**
     * What a browser gets from the storefront: whether plain http:// is sent
     * on to https://, and which security headers come with the page. Read
     * from the store's own address, twice a day. Null when unreadable.
     *
     * @return array{redirects:?bool,headers:array<string,bool>}|null
     */
    public static function web_security(): ?array
    {
        $c = get_transient('magesentinel_monitor_websec');
        if (is_array($c)) return !empty($c['ok']) ? $c : null;
        $out = ['ok' => false];
        $home = home_url('/');
        if (strpos($home, 'https://') === 0) {
            $args = ['timeout' => 8, 'redirection' => 0, 'user-agent' => 'MageSentinel-Monitor/' . self::VERSION . ' (self-check)'];
            $page = wp_remote_get($home, $args);
            if (!is_wp_error($page)) {
                $h = static function (string $name) use ($page): string { return strtolower((string) wp_remote_retrieve_header($page, $name)); };
                $plain = wp_remote_get('http://' . substr($home, 8), $args);
                $redirects = null;
                if (!is_wp_error($plain)) {
                    $code = (int) wp_remote_retrieve_response_code($plain);
                    $to = (string) wp_remote_retrieve_header($plain, 'location');
                    $redirects = $code >= 300 && $code < 400 && strpos($to, 'https://') === 0;
                }
                $out = [
                    'ok' => true,
                    'redirects' => $redirects,
                    'headers' => [
                        'Strict-Transport-Security' => $h('strict-transport-security') !== '',
                        'X-Content-Type-Options' => $h('x-content-type-options') === 'nosniff',
                        'X-Frame-Options' => $h('x-frame-options') !== '' || strpos($h('content-security-policy'), 'frame-ancestors') !== false,
                        'Referrer-Policy' => $h('referrer-policy') !== '',
                    ],
                ];
            }
        }
        set_transient('magesentinel_monitor_websec', $out, !empty($out['ok']) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return !empty($out['ok']) ? $out : null;
    }

    /** Which page cache fronts the store, and whether objects are cached outside the database. */
    public static function cache(): array
    {
        $known = [
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer',
            'breeze/breeze.php' => 'Breeze',
            'nitropack/main.php' => 'NitroPack',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
        ];
        $active = (array) get_option('active_plugins', []);
        $plugin = null;
        foreach ($known as $file => $name) {
            if (in_array($file, $active, true)) { $plugin = $name; break; }
        }
        return [
            'page_cache' => $plugin,
            'object_cache' => (bool) wp_using_ext_object_cache(),
            'debug_display' => defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY),
            'https' => strpos(home_url('/'), 'https://') === 0,
        ];
    }

    /**
     * Settings that change what a shopper can buy or pay, recorded by name and
     * time only. A gateway's settings row holds its API keys, so never values.
     */
    public static function record_setting_change($option, $old = null, $new = null): void
    {
        $option = (string) $option;
        // Which plugin, not just "the plugin list changed": a plugin switched
        // on or off is the change a broken checkout is most often traced to.
        $detail = '';
        if ($option === 'active_plugins' && is_array($old) && is_array($new)) {
            $name = static function (string $file): string {
                $all = function_exists('get_plugins') ? get_plugins() : [];
                return isset($all[$file]['Name']) ? (string) $all[$file]['Name'] : dirname($file);
            };
            $parts = [];
            foreach (array_diff($new, $old) as $f) $parts[] = '+' . $name((string) $f);
            foreach (array_diff($old, $new) as $f) $parts[] = '−' . $name((string) $f);
            $detail = implode(', ', $parts);
        }
        $watched = $option === 'active_plugins' || $option === 'stylesheet'
            || preg_match('/^woocommerce_(.+_settings|currency|calc_taxes|prices_include_tax|tax_.*|enable_guest_checkout|enable_checkout_login_reminder|checkout_.*|cart_page_id|checkout_page_id|terms_page_id|allowed_countries|specific_allowed_countries|ship_to_countries|default_customer_address|manage_stock|hold_stock_minutes|enable_coupons|price_.*)$/', $option) === 1;
        if (!$watched) return;
        $log = get_option('magesentinel_monitor_changes', []);
        if (!is_array($log)) $log = [];
        array_unshift($log, ['path' => $option, 'detail' => $detail, 'scope' => 'default', 'scope_id' => 0, 'changed_at' => gmdate('c')]);
        // One entry per setting per minute: saving a settings page fires every field.
        $seen = [];
        $log = array_values(array_filter($log, static function ($e) use (&$seen) {
            $k = $e['path'] . ($e['detail'] ?? '') . substr($e['changed_at'], 0, 16);
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        }));
        update_option('magesentinel_monitor_changes', array_slice($log, 0, 30), false);
    }

    /** @return array<int,array{path:string,scope:string,scope_id:int,changed_at:string}> */
    public static function setting_changes(): array
    {
        $log = get_option('magesentinel_monitor_changes', []);
        $since = time() - 7 * DAY_IN_SECONDS;
        return array_values(array_filter(is_array($log) ? $log : [], static function ($e) use ($since) {
            return strtotime($e['changed_at']) >= $since;
        }));
    }

    private static function stock(string $sku): array
    {
        $id = wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : null;
        if (!$product) return ['sku' => $sku, 'found' => false];
        return [
            'sku' => $sku,
            'found' => true,
            'type' => $product->get_type(),
            'qty' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'is_in_stock' => $product->is_in_stock(),
            'manage_stock' => $product->managing_stock(),
            'backorders' => $product->get_backorders(),
        ];
    }

    // --------------------------------------------------------------- orders

    /**
     * The order MageSentinel placed, or the same "not found" for anything else,
     * so the endpoint cannot be used to discover which order ids are real.
     *
     * @return WC_Order|WP_Error
     */
    private static function our_order(WP_REST_Request $request)
    {
        $none = new WP_Error('magesentinel_no_order', 'No MageSentinel order with that number.', ['status' => 404]);
        $order = wc_get_order((int) $request['id']);
        if (!$order instanceof WC_Order) return $none;
        if (preg_match(self::SYNTHETIC_EMAIL, (string) $order->get_billing_email()) !== 1) return $none;
        // The key from the confirmation URL proves the caller saw this order's thank-you page.
        $key = (string) $request->get_param('key');
        if ($key === '' || !hash_equals((string) $order->get_order_key(), $key)) return $none;
        $created = $order->get_date_created();
        if (!$created || (time() - $created->getTimestamp()) > self::MAX_AGE_SECONDS) {
            return new WP_Error('magesentinel_too_old', 'That order is too old for the monitor.', ['status' => 409]);
        }
        return $order;
    }

    private static function describe(WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = ['sku' => $product ? $product->get_sku() : null, 'name' => $item->get_name(), 'quantity' => (int) $item->get_quantity()];
        }
        return [
            'increment_id' => (string) $order->get_order_number(),
            'found' => true,
            'status' => $order->get_status(),
            'total' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'items' => $items,
        ];
    }

    public static function verify_order(WP_REST_Request $request)
    {
        $order = self::our_order($request);
        if (is_wp_error($order)) return $order;
        self::log_order($order, 'verified');
        return new WP_REST_Response(self::describe($order), 200);
    }

    /**
     * Cancel, never delete: a deleted order leaves gaps that break ERP exports
     * and myDATA submissions. Anything paid, shipped or completed has left the
     * checkout and entered the merchant's accounting; cancelling it is not cleanup.
     */
    public static function cancel_order(WP_REST_Request $request)
    {
        $order = self::our_order($request);
        if (is_wp_error($order)) return $order;
        if ($order->get_date_paid() || in_array($order->get_status(), ['completed', 'refunded', 'cancelled'], true)) {
            return new WP_Error('magesentinel_refused', sprintf('Refusing to cancel an order in status "%s".', $order->get_status()), ['status' => 409]);
        }
        $order->update_status('cancelled', 'Test order placed by MageSentinel monitoring, cancelled automatically.');
        $fresh = wc_get_order($order->get_id());
        self::log_order($fresh, 'cancelled');
        $body = self::describe($fresh);
        $body['cancelled'] = $body['status'] === 'cancelled';
        $body['state'] = $body['status'];
        return new WP_REST_Response($body, 200);
    }

}

require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-checks.php';
require_once __DIR__ . '/includes/class-js-errors.php';
require_once __DIR__ . '/includes/class-security-watch.php';
require_once __DIR__ . '/includes/class-captcha-exemption.php';
require_once __DIR__ . '/includes/class-self-check.php';
require_once __DIR__ . '/includes/class-weekly-report.php';
require_once __DIR__ . '/includes/class-site-health.php';
require_once __DIR__ . '/includes/class-test-gateway.php';

register_activation_hook(__FILE__, [MageSentinel_Monitor_Self_Check::class, 'schedule']);
register_deactivation_hook(__FILE__, static function () {
    MageSentinel_Monitor_Self_Check::unschedule();
    wp_clear_scheduled_hook(MageSentinel_Monitor::AFTER_UPDATE_HOOK);
    MageSentinel_Monitor_Weekly_Report::unschedule();
    MageSentinel_Monitor_Test_Gateway::unschedule();
});

MageSentinel_Monitor::boot();
MageSentinel_Monitor_Admin::boot();
MageSentinel_Monitor_Captcha::boot();
MageSentinel_Monitor_Self_Check::boot();
MageSentinel_Monitor_Weekly_Report::boot();
MageSentinel_Monitor_Site_Health::boot();
MageSentinel_Monitor_Test_Gateway::boot();
MageSentinel_Monitor_Js_Errors::boot();
MageSentinel_Monitor_Security_Watch::boot();
