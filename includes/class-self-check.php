<?php
/**
 * The free, standalone half of the plugin: the health checks run on this site
 * every 15 minutes, and the shop owner gets an email when one turns red and another
 * when everything is clear again. No account, no outside service: nothing
 * leaves the server except that email, sent through the site's own wp_mail.
 *
 * Once the store is connected to MageSentinel and MageSentinel is calling in,
 * these emails stop: MageSentinel alerts on the same things, plus what a site
 * cannot see from the inside (a real purchase from outside, the storefront as
 * shoppers get it). If MageSentinel stops calling in, the emails resume.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Self_Check
{
    const HOOK = 'magesentinel_monitor_self_check';
    /**
     * Every 15 minutes. The run reads settings and counts, and the costly parts
     * (the TLS handshake, the unbuyable-products scan) keep their own 12-hour
     * cache, so four runs an hour cost the site little and a broken checkout
     * is reported within a quarter of an hour instead of up to an hour later.
     * WP-Cron rides on page views, so a quiet site runs it on its next visit.
     */
    const SCHEDULE = 'magesentinel_15min';
    const INTERVAL = 15 * MINUTE_IN_SECONDS;
    /** Last result and what the owner was last told. Not autoloaded. */
    const STATE = 'magesentinel_monitor_self_check';
    /** Tagged so a signup from the plugin is counted as such, not as the shop's own domain. */
    const SIGNUP_URL = 'https://magesentinel.com/signup?from=wordpress-plugin&utm_source=wporg&utm_medium=plugin';
    const PRICING_URL = 'https://magesentinel.com/pricing?utm_source=wporg&utm_medium=plugin';
    /** Below this many orders a week, a quiet spell says nothing. */
    const SILENCE_MIN_WEEKLY_ORDERS = 14;
    /** Orders the store would normally have taken since the last one before the quiet counts as stopped. */
    const SILENCE_EXPECTED = 5;
    /** Held while a run is in progress, so WP-Cron and the failed-payment trigger never run it twice at once. */
    const LOCK = 'magesentinel_monitor_run_lock';
    const LOCK_TTL = 10 * MINUTE_IN_SECONDS;
    /** What this request wrote into the lock, so it releases only its own. */
    private static $lock_value = null;
    /** Rows about how the store is set up; the rest are about how it is behaving. */
    const SETUP_KEYS = ['storefront', 'pages', 'payments', 'shipping', 'testmode', 'db', 'https', 'redirect', 'ssl', 'debug', 'unbuyable', 'admin_user'];

    public static function boot(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        // Activation schedules it; this covers an update from 0.3, which has no activation.
        add_action('init', [self::class, 'schedule']);
        // Three failed payments inside an hour cannot wait for the next run.
        add_action('woocommerce_order_status_failed', [self::class, 'on_failed_order'], 20, 2);
    }

    public static function on_failed_order($orderId, $order = null): void
    {
        if ($order instanceof WC_Order && MageSentinel_Monitor::is_test_order($order)) return;
        if (get_transient('magesentinel_monitor_burst_check')) return;
        if (!function_exists('wc_get_orders')) return;
        $now = time();
        $n = count(wc_get_orders(['limit' => 3, 'return' => 'ids', 'type' => 'shop_order', 'status' => ['failed'], 'date_created' => ($now - HOUR_IN_SECONDS) . '...' . $now]));
        if ($n < 3) return;
        set_transient('magesentinel_monitor_burst_check', 1, 15 * MINUTE_IN_SECONDS);
        self::run();
    }

    /** @param array<string,array{interval:int,display:string}> $schedules */
    public static function cron_schedules($schedules): array
    {
        $schedules = is_array($schedules) ? $schedules : [];
        $schedules[self::SCHEDULE] = ['interval' => self::INTERVAL, 'display' => 'Every 15 minutes (MageSentinel)'];
        return $schedules;
    }

    public static function schedule(): void
    {
        // The activation hook runs before boot() on the activating request, so
        // the recurrence may not be registered yet.
        if (!has_filter('cron_schedules', [self::class, 'cron_schedules'])) {
            add_filter('cron_schedules', [self::class, 'cron_schedules']);
        }
        // Sites installed before 1.4 carry the hourly event: move it over.
        $current = wp_get_schedule(self::HOOK);
        if ($current !== false && $current !== self::SCHEDULE) wp_clear_scheduled_hook(self::HOOK);
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** @return array{checked_at:int,bad:array<string,string>,notified_at:int,notified:array<string,string>} */
    public static function state(): array
    {
        $s = get_option(self::STATE, []);
        return [
            'checked_at' => isset($s['checked_at']) ? (int) $s['checked_at'] : 0,
            'bad' => isset($s['bad']) && is_array($s['bad']) ? $s['bad'] : [],
            'notified_at' => isset($s['notified_at']) ? (int) $s['notified_at'] : 0,
            'notified' => isset($s['notified']) && is_array($s['notified']) ? $s['notified'] : [],
            // The newest update the owner was already told about.
            'updates_told' => isset($s['updates_told']) ? (int) $s['updates_told'] : 0,
        ];
    }

    /** MageSentinel is connected and has called in within three hours: it does the alerting. */
    public static function monitored(): bool
    {
        $s = MageSentinel_Monitor::settings();
        $a = MageSentinel_Monitor::activity();
        return $s['enabled'] && $s['token_hash'] !== '' && $a['last_seen'] > 0 && time() - $a['last_seen'] <= 3 * HOUR_IN_SECONDS;
    }

    /** Emails go out: switched on, somewhere to send them, and MageSentinel is not already alerting. */
    public static function emails_active(): bool
    {
        $s = MageSentinel_Monitor::settings();
        return $s['alerts'] && self::recipient() !== '' && !self::monitored();
    }

    public static function recipient(): string
    {
        $s = MageSentinel_Monitor::settings();
        $to = $s['alert_email'] !== '' ? $s['alert_email'] : (string) get_option('admin_email');
        return is_email($to) ? $to : '';
    }

    /**
     * Orders stopped: since the last order, this store would normally have
     * taken five or more, going by the same hours of the week over the last
     * four weeks (a quiet Sunday night is expected, a quiet Monday noon is
     * not). Never under six hours. Null when the store sells too little to tell.
     *
     * @return array{hours:int,per_day:float,expected:?float,silent:bool}|null
     */
    public static function silence(array $sales): ?array
    {
        if (!isset($sales['last_7d'], $sales['minutes_since_last_order']) || $sales['minutes_since_last_order'] === null) return null;
        $week = array_sum(array_column($sales['last_7d'], 'count'));
        if ($week < self::SILENCE_MIN_WEEKLY_ORDERS) return null;
        $perDay = $week / 7;
        $minutes = (int) $sales['minutes_since_last_order'];
        $out = ['hours' => (int) floor($minutes / 60), 'per_day' => round($perDay, 1)];
        $baseline = MageSentinel_Monitor::hourly_baseline();
        if ($baseline) {
            $expected = self::expected_orders($baseline, time() - $minutes * MINUTE_IN_SECONDS, time());
            return $out + ['expected' => round($expected, 1), 'silent' => $minutes >= 6 * 60 && $expected >= self::SILENCE_EXPECTED];
        }
        // No baseline to read: five average gaps, never under six hours.
        return $out + ['expected' => null, 'silent' => $minutes > max(6 * 60, 5 * 24 * 60 / $perDay)];
    }

    /** Orders the baseline expects between two times, hour slice by hour slice. Stops counting once it is clearly enough. */
    public static function expected_orders(array $baseline, int $from, int $to): float
    {
        $tz = wp_timezone();
        $from = max($from, $to - 14 * DAY_IN_SECONDS);
        $sum = 0.0;
        for ($t = $from; $t < $to && $sum < 10 * self::SILENCE_EXPECTED;) {
            $next = min($to, (intdiv($t, HOUR_IN_SECONDS) + 1) * HOUR_IN_SECONDS);
            $key = (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('N-G');
            $sum += ($baseline[$key] ?? 0) * ($next - $t) / HOUR_IN_SECONDS;
            $t = $next;
        }
        return $sum;
    }

    /** The scheduled run: check, remember, and tell the owner about what changed. */
    public static function run(): void
    {
        if (!self::lock()) return;
        try {
            self::run_locked();
        } finally {
            self::unlock();
        }
    }

    /**
     * An INSERT on the options table's unique name is atomic, unlike
     * add_option() or a transient: only one request gets the lock, the others
     * return at once. The value is "time:token". A lock older than LOCK_TTL
     * is left from a run that died, and one conditional UPDATE takes it over.
     * Only the holder's own token releases it, so a run that outlived its
     * lock cannot release the next holder's.
     */
    private static function lock(): bool
    {
        global $wpdb;
        $now = time();
        $value = $now . ':' . bin2hex(random_bytes(8));
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- the lock must bypass the object cache to be atomic
        $got = (bool) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value));
        if (!$got) {
            $got = (bool) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
                $value, self::LOCK, $now - self::LOCK_TTL
            ));
        }
        // phpcs:enable
        self::$lock_value = $got ? $value : null;
        return $got;
    }

    private static function unlock(): void
    {
        global $wpdb;
        if (self::$lock_value === null) return;
        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK, 'option_value' => self::$lock_value]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see lock()
        self::$lock_value = null;
    }

    private static function run_locked(): void
    {
        $rows = MageSentinel_Monitor_Admin::health_rows();
        MageSentinel_Monitor::record_health_day($rows);
        $bad = [];
        foreach ($rows as $r) {
            if ($r['state'] === 'bad') $bad[$r['key']] = $r['label'] . ': ' . $r['summary'] . (!empty($r['link']) ? "\n  " . $r['link'] : '');
        }
        $state = self::state();
        $state['checked_at'] = time();
        $state['bad'] = $bad;

        if (self::emails_active()) {
            $new = array_diff_key($bad, $state['notified']);
            if ($new) {
                if (self::send(self::problem_email($bad, count($rows)))) { $state['notified'] = $bad; $state['notified_at'] = time(); }
            } elseif (!$bad && $state['notified']) {
                if (self::send(self::clear_email(count($rows)))) { $state['notified'] = []; $state['notified_at'] = time(); }
            } else {
                // Something fixed, the rest unchanged: no mail, but a relapse of the fixed one mails again.
                $state['notified'] = array_intersect_key($state['notified'], $bad);
            }
        } else {
            // MageSentinel (or nobody) is alerting: remember nothing as told, so if
            // the local emails come back on, a problem still standing is reported.
            $state['notified'] = [];
        }
        // Something checkout runs through was updated: one email asking for a
        // test purchase. Not when MageSentinel is connected: it tests hourly.
        $updates = array_values(array_filter(MageSentinel_Monitor::recent_updates(DAY_IN_SECONDS), static function ($u) use ($state) {
            return !empty($u['checkout']) && (int) $u['at'] > $state['updates_told'];
        }));
        if ($updates) {
            if (self::emails_active()) self::send(self::update_email($updates));
            $state['updates_told'] = max(array_map(static function ($u) { return (int) $u['at']; }, $updates));
        }
        update_option(self::STATE, $state, false);
    }

    /** @return array{subject:string,body:string} */
    private static function update_email(array $updates): array
    {
        $lines = [sprintf(/* translators: %s: value */ __('These were just updated on %s:', 'magesentinel-monitor'), self::host()), ''];
        foreach ($updates as $u) $lines[] = '• ' . trim($u['name'] . ' ' . $u['version']);
        $lines[] = '';
        $lines[] = __('Updates are the most common reason a checkout that worked yesterday stops working. Place a test order now: add a product to the cart, go through checkout and pay.', 'magesentinel-monitor');
        $lines[] = '';
        $lines[] = __('See every check on your store:', 'magesentinel-monitor');
        $lines[] = admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG);
        $lines = array_merge($lines, self::footer());
        return [
            'subject' => sprintf(/* translators: %s: value */ __('[%s] Checkout plugins were updated: place a test order', 'magesentinel-monitor'), self::host()),
            'body' => implode("\n", $lines),
        ];
    }


    private static function host(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** @return array{subject:string,body:string} */
    private static function problem_email(array $bad, int $total): array
    {
        $lines = [
            sprintf(/* translators: %s: value */ __('MageSentinel checked %s and found something that stops or risks orders:', 'magesentinel-monitor'), self::host()),
            '',
        ];
        $setup = array_intersect_key($bad, array_flip(self::SETUP_KEYS));
        $behaviour = array_diff_key($bad, $setup);
        // Two kinds of problem, two kinds of fix: a setting to change, or something to look into.
        foreach ([
            [$setup, __('A setting is wrong (fix it in the admin):', 'magesentinel-monitor')],
            [$behaviour, __('The store is behaving differently than usual (look into it):', 'magesentinel-monitor')],
        ] as [$group, $heading]) {
            if (!$group) continue;
            if ($setup && $behaviour) $lines[] = $heading;
            foreach ($group as $line) $lines[] = '• ' . $line;
            $lines[] = '';
        }
        $lines[] = sprintf(/* translators: %1$s: value, %2$s: value */ __('%1$d of %2$d checks are healthy. All checks:', 'magesentinel-monitor'), $total - count($bad), $total);
        $lines[] = admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG);
        $lines = array_merge($lines, self::footer());
        return [
            'subject' => sprintf(/* translators: %s: value */ __('[%s] Something is stopping or risking orders', 'magesentinel-monitor'), self::host()),
            'body' => implode("\n", $lines),
        ];
    }

    /** @return array{subject:string,body:string} */
    private static function clear_email(int $total): array
    {
        $lines = array_merge([
            sprintf(/* translators: %1$s: value, %2$s: value, %3$s: value */ __('The problem MageSentinel reported on %1$s is gone: %2$d of %2$d checks are healthy again.', 'magesentinel-monitor'), self::host(), $total),
            '',
            admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG),
        ], self::footer());
        return [
            'subject' => sprintf(/* translators: %s: value */ __('[%s] All clear again', 'magesentinel-monitor'), self::host()),
            'body' => implode("\n", $lines),
        ];
    }

    private static function footer(): array
    {
        return [
            '',
            '—',
            __('These checks run on your own site every 15 minutes. They read settings and counts, so they cannot tell whether a shopper can actually pay. MageSentinel can: it places a real test purchase from outside, every hour, and cancels it. 14 days free, no card:', 'magesentinel-monitor'),
            self::SIGNUP_URL,
            '',
            __('Change or stop these emails:', 'magesentinel-monitor'),
            admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG . '-settings'),
        ];
    }

    /** @param array{subject:string,body:string} $mail */
    private static function send(array $mail): bool
    {
        return (bool) wp_mail(self::recipient(), $mail['subject'], $mail['body']);
    }
}
