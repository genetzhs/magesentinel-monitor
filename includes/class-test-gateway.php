<?php
/**
 * The hidden test payment method (opt-in, off by default).
 *
 * On a store whose only payment methods are cards or PayPal, MageSentinel
 * cannot place a test order without charging money. When the owner switches
 * "Test orders through a hidden payment method" on, a checkout request that
 * carries this store's own connector token (the X-MageSentinel-Token header,
 * the same secret the REST endpoints check) sees one extra method,
 * "MageSentinel test order", which takes no payment. Every other request —
 * shoppers, the admin, cron, a wrong or missing token — never sees it: the
 * gateway is not even registered for them.
 *
 * An order placed with it is a test order: no emails (the plugin's silencer),
 * kept out of the admin order list for good, and cancelled (stock restored,
 * never deleted) by MageSentinel right after the check, or by the sweep below
 * after 30 minutes at the latest. Cancel, never delete: order numbers stay
 * whole and anything that already saw the order (ERP, invoicing, myDATA) sees
 * an ordinary cancelled order.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Test_Gateway
{
    const ID = 'magesentinel_test';
    /** Ids of orders placed with the method that still exist, id => created timestamp. */
    const OPEN = 'magesentinel_monitor_gateway_orders';
    const SWEEP_HOOK = 'magesentinel_sweep_test_orders';
    const SWEEP_SCHEDULE = 'magesentinel_15min';
    const MAX_AGE = 1800;
    /** Set by process_payment(): only an order our own method took can ever be cancelled here. */
    const MARK = '_magesentinel_test_gateway';
    /** The sweep never touches anything older than this, whatever else says. */
    const SWEEP_LIMIT = 7 * DAY_IN_SECONDS;
    const SAFE_STATUSES = ['on-hold', 'pending', 'checkout-draft', 'failed', 'cancelled'];

    /** @var bool|null */
    private static $trusted = null;

    public static function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [self::class, 'register']);
        add_action('woocommerce_blocks_payment_method_type_registration', [self::class, 'register_block']);
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action(self::SWEEP_HOOK, [self::class, 'sweep']);
        add_action('init', [self::class, 'schedule']);
        // The admin order list: HPOS and the posts table.
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [self::class, 'hide_from_hpos_list']);
        add_action('pre_get_posts', [self::class, 'hide_from_posts_list']);
    }

    /**
     * The owner switched the connector and the hidden method on, and this
     * request carries the store's own token.
     */
    public static function is_trusted_request(): bool
    {
        if (self::$trusted !== null) return self::$trusted;
        $s = MageSentinel_Monitor::settings();
        $provided = isset($_SERVER['HTTP_X_MAGESENTINEL_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_MAGESENTINEL_TOKEN'])) : '';
        self::$trusted = $s['enabled']
            && $s['allow_test_gateway']
            && $s['token_hash'] !== ''
            && $provided !== ''
            && hash_equals($s['token_hash'], hash('sha256', $provided));
        return self::$trusted;
    }

    /** @internal tests reset the per-request memo. */
    public static function reset(): void
    {
        self::$trusted = null;
    }

    public static function register(array $gateways): array
    {
        if (self::is_trusted_request() && class_exists('WC_Payment_Gateway')) {
            if (!class_exists('MageSentinel_Monitor_Test_Gateway_Method')) self::define_gateway();
            $gateways[] = 'MageSentinel_Monitor_Test_Gateway_Method';
        }
        return $gateways;
    }

    public static function register_block($registry): void
    {
        if (!self::is_trusted_request()) return;
        if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) return;
        if (!class_exists('MageSentinel_Monitor_Test_Gateway_Block')) self::define_block();
        $registry->register(new MageSentinel_Monitor_Test_Gateway_Block());
    }

    private static function define_gateway(): void
    {
        require_once __DIR__ . '/class-test-gateway-method.php';
    }

    private static function define_block(): void
    {
        require_once __DIR__ . '/class-test-gateway-block.php';
    }

    public static function is_gateway_order(WC_Order $order): bool
    {
        return $order->get_payment_method() === self::ID;
    }

    public static function remember(WC_Order $order): void
    {
        $order->update_meta_data(self::MARK, hash('sha256', $order->get_order_key()));
        $order->save_meta_data();
        $open = self::open();
        $created = $order->get_date_created();
        $open[(int) $order->get_id()] = $created ? $created->getTimestamp() : time();
        update_option(self::OPEN, $open, false);
    }

    /** @return array<int,int> */
    public static function open(): array
    {
        $o = get_option(self::OPEN, []);
        return is_array($o) ? array_map('intval', $o) : [];
    }

    /**
     * Every lock must open before an order is cancelled. Returns why not, or null.
     * Each one alone would keep a real order safe; together they have to agree.
     */
    public static function refusal(WC_Order $order, int $maxAge): ?string
    {
        $id = (int) $order->get_id();
        if ($order->get_payment_method() !== self::ID) return 'payment_method';
        if (!hash_equals(hash('sha256', $order->get_order_key()), (string) $order->get_meta(self::MARK))) return 'mark';
        if (preg_match(MageSentinel_Monitor::SYNTHETIC_EMAIL, (string) $order->get_billing_email()) !== 1) return 'email';
        if (!isset(self::open()[$id])) return 'not_ours';
        if ($order->get_date_paid() || (string) $order->get_transaction_id() !== '') return 'paid';
        if (!in_array($order->get_status(), self::SAFE_STATUSES, true)) return 'status';
        if (count($order->get_refunds()) > 0) return 'refunds';
        $created = $order->get_date_created();
        if (!$created || time() - $created->getTimestamp() > $maxAge) return 'age';
        return null;
    }

    private static function log(string $msg): void
    {
        if (function_exists('wc_get_logger')) wc_get_logger()->info($msg, ['source' => 'magesentinel-test-orders']);
    }

    /**
     * Cancels the order (WooCommerce restores the stock on cancel) and stops
     * tracking it. Never deletes. Only when every lock in refusal() opens.
     */
    public static function cancel(WC_Order $order, int $maxAge = 3600): bool
    {
        $why = self::refusal($order, $maxAge);
        if ($why !== null) {
            self::log(sprintf('Refused to cancel order #%d: %s', $order->get_id(), $why));
            return false;
        }
        $id = (int) $order->get_id();
        if ($order->get_status() !== 'cancelled') {
            $order->update_status('cancelled', 'MageSentinel test order, cancelled automatically. No payment was taken.');
        }
        self::log(sprintf('Cancelled MageSentinel test order #%d', $id));
        $open = self::open();
        unset($open[$id]);
        update_option(self::OPEN, $open, false);
        return true;
    }

    /** Safety net: anything the worker did not cancel is cancelled after 30 minutes. */
    public static function sweep(): int
    {
        $n = 0;
        foreach (self::open() as $id => $ts) {
            if (time() - $ts < self::MAX_AGE) continue;
            $order = wc_get_order($id);
            if ($order instanceof WC_Order && self::cancel($order, self::SWEEP_LIMIT)) {
                $n++;
                continue;
            }
            // Gone, or refused by a lock: stop tracking it, never touch it.
            $open = self::open();
            unset($open[$id]);
            update_option(self::OPEN, $open, false);
        }
        return $n;
    }

    public static function schedules(array $s): array
    {
        $s[self::SWEEP_SCHEDULE] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (MageSentinel)'];
        return $s;
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, self::SWEEP_SCHEDULE, self::SWEEP_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::SWEEP_HOOK);
    }

    /** The admin order list (HPOS): never shows an order taken by the hidden method. */
    public static function hide_from_hpos_list(array $args): array
    {
        $fq = isset($args['field_query']) && is_array($args['field_query']) ? $args['field_query'] : [];
        $fq[] = ['field' => 'payment_method', 'value' => self::ID, 'compare' => '!='];
        $args['field_query'] = $fq;
        return $args;
    }

    /** The admin order list (posts table): the same, by the payment method meta. */
    public static function hide_from_posts_list($q): void
    {
        if (!is_admin() || !$q instanceof WP_Query || !$q->is_main_query()) return;
        if ($q->get('post_type') !== 'shop_order') return;
        $mq = (array) $q->get('meta_query');
        $mq[] = ['relation' => 'OR',
            ['key' => '_payment_method', 'value' => self::ID, 'compare' => '!='],
            ['key' => '_payment_method', 'compare' => 'NOT EXISTS'],
        ];
        $q->set('meta_query', $mq);
    }
}
