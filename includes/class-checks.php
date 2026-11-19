<?php
/**
 * Health checks ported from the Magento module 2.1: unsent order emails, the
 * object cache answering, injected card-skimmer scripts, PHP files among the
 * uploads and an administrator called "admin". Each returns a health row (or
 * null when it does not apply), in the shape MageSentinel_Monitor_Admin uses.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Checks
{
    /** The two file scans walk the database and the uploads folder: twice a day is enough. */
    const SCAN_CACHE = 'magesentinel_monitor_security_scan';
    const MEDIA_LIMIT = 20000;

    /**
     * What injected checkout skimmers look like in page content and options:
     * obfuscated code that loads or runs a payload. Theme and tag-manager
     * snippets need none of these.
     */
    const SKIMMER_PATTERNS = [
        'eval + atob loader' => '/eval\s*\(\s*atob\s*\(/i',
        'eval + char-code loader' => '/eval\s*\(\s*String\.from[C]harCode/i',
        'write + unescape loader' => '/document\.write\s*\(\s*unescape\s*\(/i',
        'Function + atob loader' => '/new\s+Function\s*\(\s*atob\s*\(/i',
        'WebSocket exfiltration' => '/new\s+WebSocket\s*\(\s*[\'"]wss?:/i',
        'hex-escaped script' => '/(\\\\x[0-9a-f]{2}){20,}/i',
    ];

    /** @return array<int,array<string,mixed>> the rows that apply to this site */
    public static function rows(): array
    {
        $rows = [];
        $checks = [
            'emails' => [self::class, 'emails'], 'object_cache' => [self::class, 'object_cache'],
            'skimmers' => [self::class, 'skimmers'], 'uploads_php' => [self::class, 'uploads_php'], 'admin_user' => [self::class, 'admin_user'],
            'js_errors' => ['MageSentinel_Monitor_Js_Errors', 'row'],
            'logins' => ['MageSentinel_Monitor_Security_Watch', 'logins_row'],
            'integrity' => ['MageSentinel_Monitor_Security_Watch', 'integrity_row'],
        ];
        foreach ($checks as $check) {
            try {
                $row = call_user_func($check);
            } catch (\Throwable $e) {
                $row = null;
            }
            if ($row) $rows[] = $row;
        }
        return $rows;
    }

    /** Orders of the last day that never got their "New order" email to the shop. */
    private static function emails(): ?array
    {
        if (!function_exists('WC') || !function_exists('wc_get_orders')) return null;
        $emails = WC()->mailer()->get_emails();
        $mail = $emails['WC_Email_New_Order'] ?? null;
        if (!$mail || !$mail->is_enabled()) return null;

        [$total, $unsent] = self::email_counts();
        $base = ['key' => 'emails', 'icon' => 'mail', 'label' => __('Order emails sent', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=wc-settings&tab=email')];
        if ($total === 0) return $base + ['state' => 'good', 'summary' => __('No orders needing an email in the last 24 hours.', 'magesentinel-monitor')];
        if ($unsent >= 3 && $unsent / $total >= 0.5) {
            return $base + ['state' => 'bad', 'summary' => sprintf(/* translators: %1$d: orders without an email, %2$d: all orders */ __('%1$d of %2$d orders in the last 24 hours got no "New order" email. Check the site\'s mail and WP-Cron.', 'magesentinel-monitor'), $unsent, $total)];
        }
        if ($unsent > 0) {
            return $base + ['state' => 'watch', 'summary' => sprintf(/* translators: %1$d: orders without an email, %2$d: all orders */ __('%1$d of %2$d orders in the last 24 hours got no "New order" email.', 'magesentinel-monitor'), $unsent, $total)];
        }
        return $base + ['state' => 'good', 'summary' => sprintf(/* translators: %d: number of orders */ _n('The %d order in the last 24 hours got its email.', 'All %d orders in the last 24 hours got their email.', $total, 'magesentinel-monitor'), $total)];
    }

    /**
     * Paid orders of the last day (15 minutes' grace: the email goes out with
     * the order, or with the next cron run) and how many of them never got
     * the "New order" email. One count query; the per-order fallback is kept
     * for stores whose schema does not have the flag where we expect it.
     *
     * @return array{0:int,1:int}
     */
    private static function email_counts(): array
    {
        global $wpdb;
        $q = MageSentinel_Monitor::order_sql();
        // The flag: an HPOS operational-data column since WooCommerce 7.x, plain meta on the posts store.
        $flag = $q['hpos']
            ? "LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = {$q['id']}"
            : "LEFT JOIN {$wpdb->postmeta} sm ON sm.post_id = {$q['id']} AND sm.meta_key = '_new_order_email_sent'";
        $sent = $q['hpos'] ? 'COALESCE(od.new_order_email_sent, 0) = 1' : "sm.meta_value IN ('yes', 'true', '1')";
        $suppress = $wpdb->suppress_errors(true);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count; names come from order_sql()
        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN {$sent} THEN 0 ELSE 1 END) AS unsent
             FROM {$q['from']} {$flag}
             WHERE " . MageSentinel_Monitor::real_order_sql($q) . " AND {$q['status']} IN ('wc-processing', 'wc-on-hold', 'wc-completed')
               AND " . MageSentinel_Monitor::date_sql($q, time() - DAY_IN_SECONDS, time() - 15 * MINUTE_IN_SECONDS),
            ARRAY_A
        );
        // phpcs:enable
        $failed = (bool) $wpdb->last_error;
        $wpdb->suppress_errors($suppress);
        if (!$failed && is_array($row)) return [(int) $row['total'], (int) $row['unsent']];

        $total = 0;
        $unsent = 0;
        $ids = wc_get_orders(['limit' => 500, 'return' => 'ids', 'type' => 'shop_order', 'status' => ['wc-processing', 'wc-on-hold', 'wc-completed'], 'date_created' => (time() - DAY_IN_SECONDS) . '...' . (time() - 15 * MINUTE_IN_SECONDS)]);
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order instanceof WC_Order || MageSentinel_Monitor::is_test_order($order)) continue;
            $total++;
            $was = method_exists($order, 'get_new_order_email_sent') ? $order->get_new_order_email_sent() : wc_string_to_bool((string) $order->get_meta('_new_order_email_sent'));
            if (!$was) $unsent++;
        }
        return [$total, $unsent];
    }

    /**
     * A Redis or Memcached object cache that stopped answering. The drop-in
     * falls back to memory quietly, so the site seems fine while every page
     * hits the database again, and sessions kept there are lost.
     */
    private static function object_cache(): ?array
    {
        if (!wp_using_ext_object_cache()) return null;
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        $head = is_readable($dropin) ? (string) file_get_contents($dropin, false, null, 0, 4096) : '';

        $servers = [];
        if (defined('WP_REDIS_PATH') && WP_REDIS_PATH) {
            $servers['Redis'] = ['unix://' . WP_REDIS_PATH, -1];
        } elseif (defined('WP_REDIS_HOST') || stripos($head, 'redis') !== false) {
            $host = defined('WP_REDIS_HOST') ? (string) WP_REDIS_HOST : '127.0.0.1';
            $servers['Redis'] = [strpos($host, '/') === 0 ? 'unix://' . $host : $host, defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379];
        } elseif (stripos($head, 'memcache') !== false) {
            global $memcached_servers;
            $first = is_array($memcached_servers) ? reset($memcached_servers) : null;
            $first = is_array($first) ? reset($first) : $first;
            [$host, $port] = array_pad(explode(':', is_string($first) ? $first : '127.0.0.1:11211'), 2, '11211');
            $servers['Memcached'] = [$host, (int) $port];
        }
        if (!$servers) return null;

        $base = ['key' => 'object_cache', 'icon' => 'server', 'label' => __('Object cache reachable', 'magesentinel-monitor')];
        foreach ($servers as $name => [$host, $port]) {
            if (!self::ping($host, $port)) {
                return $base + ['state' => 'bad', 'summary' => sprintf(/* translators: %s: Redis or Memcached */ __('%s does not answer. Every page goes back to the database, and the store slows down under load. Ask your host.', 'magesentinel-monitor'), $name)];
            }
        }
        return $base + ['state' => 'good', 'summary' => sprintf(/* translators: %s: Redis or Memcached */ __('%s answers.', 'magesentinel-monitor'), implode(', ', array_keys($servers)))];
    }

    private static function ping(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $target = strpos($host, 'unix://') === 0 ? $host : 'tcp://' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host) . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $errstr, 2); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreachable server is the answer, not an error
        if (!$socket) return false;
        fclose($socket); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket, not a file
        return true;
    }

    /** @return array{skimmers:array{found:string[],checked:int},uploads:array{found:string[],seen:int}} */
    private static function scan(): array
    {
        $cached = get_transient(self::SCAN_CACHE);
        if (is_array($cached) && isset($cached['skimmers'], $cached['uploads'])) return $cached;
        $scan = ['skimmers' => self::scan_skimmers(), 'uploads' => self::scan_uploads()];
        set_transient(self::SCAN_CACHE, $scan, 12 * HOUR_IN_SECONDS);
        return $scan;
    }

    /** Published content and options that print scripts, matched against the skimmer patterns. Places are named, code never shown. */
    private static function scan_skimmers(): array
    {
        global $wpdb;
        $sources = [];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- a read-only scan, cached for 12 hours
        $posts = $wpdb->get_results(
            "SELECT post_type, post_name, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'shop_order', 'attachment')
               AND (post_content LIKE '%<script%' OR post_content LIKE '%eval(%')
             LIMIT 500"
        );
        foreach ((array) $posts as $p) $sources[$p->post_type . ' ' . $p->post_name] = (string) $p->post_content;
        // Header/footer code plugins, theme options and widgets all live in options.
        $options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'
               AND (option_value LIKE '%<script%' OR option_value LIKE '%eval(%' OR option_value LIKE '%WebSocket%')
             LIMIT 500"
        );
        // phpcs:enable
        foreach ((array) $options as $o) $sources['option ' . $o->option_name] = (string) $o->option_value;

        $found = [];
        foreach ($sources as $place => $content) {
            foreach (self::SKIMMER_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $content)) { $found[] = $place . ': ' . $label; break; }
            }
        }
        return ['found' => $found, 'checked' => count($sources)];
    }

    /** PHP files in the uploads folder, where only media belong: almost always a web shell. */
    private static function scan_uploads(): array
    {
        $dir = (string) (wp_upload_dir(null, false)['basedir'] ?? '');
        if ($dir === '' || !is_dir($dir)) return ['found' => [], 'seen' => 0];
        $found = [];
        $seen = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $file) {
            if (++$seen > self::MEDIA_LIMIT) break;
            if (!preg_match('/\.(php\d?|phtml|phar)$/i', $file->getFilename())) continue;
            // Plugins drop an empty index.php ("Silence is golden") into their upload folders.
            if (strtolower($file->getFilename()) === 'index.php' && $file->getSize() < 200
                && preg_match('#^<\?php\s*(//[^\n]*|/\*.*?\*/)?\s*(\?>)?\s*$#s', (string) file_get_contents($file->getPathname()))) continue;
            $found[] = substr($file->getPathname(), strlen($dir) + 1);
        }
        return ['found' => $found, 'seen' => $seen];
    }

    private static function skimmers(): array
    {
        $s = self::scan()['skimmers'];
        $base = ['key' => 'skimmers', 'icon' => 'shield', 'label' => __('No injected scripts', 'magesentinel-monitor')];
        if ($s['found']) {
            return $base + ['state' => 'bad', 'summary' => sprintf(/* translators: %s: where the scripts were found */ __('Obfuscated script found, the way card skimmers hide: %s. Check it before the next order.', 'magesentinel-monitor'), implode('; ', array_slice($s['found'], 0, 5)))];
        }
        return $base + ['state' => 'good', 'summary' => sprintf(/* translators: %d: number of pages and settings */ __('Checked %d pages and settings that print scripts.', 'magesentinel-monitor'), $s['checked'])];
    }

    private static function uploads_php(): array
    {
        $u = self::scan()['uploads'];
        $base = ['key' => 'uploads_php', 'icon' => 'file', 'label' => __('No PHP files in uploads', 'magesentinel-monitor')];
        if ($u['found']) {
            return $base + ['state' => 'bad', 'summary' => sprintf(/* translators: %1$d: number of files, %2$s: file names */ _n('%1$d PHP file in wp-content/uploads, where only media belong. Likely a web shell: %2$s.', '%1$d PHP files in wp-content/uploads, where only media belong. Likely a web shell: %2$s.', count($u['found']), 'magesentinel-monitor'), count($u['found']), implode(', ', array_slice($u['found'], 0, 3)))];
        }
        return $base + ['state' => 'good', 'summary' => __('None found.', 'magesentinel-monitor')];
    }

    /** "admin" is the first username login bots try. */
    private static function admin_user(): array
    {
        $user = get_user_by('login', 'admin');
        $base = ['key' => 'admin_user', 'icon' => 'lock', 'label' => __('No administrator called "admin"', 'magesentinel-monitor')];
        if ($user && user_can($user, 'manage_options')) {
            return $base + ['state' => 'watch', 'summary' => __('An administrator logs in as "admin", the first name bots try. Create a new administrator with another name and delete this one, giving its content to the new user.', 'magesentinel-monitor'), 'link' => admin_url('users.php?s=admin')];
        }
        return $base + ['state' => 'good', 'summary' => __('No administrator uses the default name.', 'magesentinel-monitor')];
    }
}
