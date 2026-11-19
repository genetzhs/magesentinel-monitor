<?php
/**
 * JavaScript errors from real shoppers on the cart and checkout pages.
 *
 * A few lines of script, printed only on those two pages, report uncaught
 * errors, rejected promises and scripts that fail to load to this site's own
 * REST route. Nothing leaves the server. Kept: the message (emails and long
 * numbers masked), the script address without its query, the line, the page
 * and the browser family. No cookies, no IP address, no visitor id.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Js_Errors
{
    const OPTION = 'magesentinel_monitor_js_errors';
    const HANDLE = 'magesentinel-monitor-errors';
    const DAYS = 14;
    const MAX_ERRORS = 60;
    /** Per visitor address: reports accepted in ten minutes. */
    const PER_IP = 20;
    /** Whole site: reports written in an hour, so a broken script cannot flood the database. */
    const PER_HOUR = 600;

    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_post_magesentinel_js_errors_clear', [self::class, 'clear']);
    }

    public static function enabled(): bool
    {
        return !empty(MageSentinel_Monitor::settings()['js_errors']);
    }

    public static function enqueue(): void
    {
        if (!self::enabled() || !function_exists('is_checkout')) return;
        $page = is_checkout() ? 'checkout' : (is_cart() ? 'cart' : '');
        if ($page === '') return;
        wp_register_script(self::HANDLE, false, [], MageSentinel_Monitor::VERSION, false);
        wp_enqueue_script(self::HANDLE);
        $cfg = wp_json_encode(['u' => rest_url('magesentinel/v1/js-error'), 'p' => $page]);
        wp_add_inline_script(self::HANDLE, '(function(c){var n=0,seen={};'
            . 'function send(m,s,l,k){if(n>=5)return;m=String(m||"").slice(0,300);s=String(s||"");'
            . 'if(!m||/^(chrome|moz|safari|safari-web|edge)-extension:|^webkit-masked-url:/.test(s))return;'
            . 'if(m==="Script error."&&!s)return;var key=m+"|"+s+"|"+l;if(seen[key])return;seen[key]=1;n++;'
            . 'var b=JSON.stringify({m:m,s:s.split("#")[0].split("?")[0],l:l|0,c:k|0,p:c.p});'
            . 'try{if(navigator.sendBeacon&&navigator.sendBeacon(c.u,new Blob([b],{type:"application/json"})))return}catch(e){}'
            . 'try{fetch(c.u,{method:"POST",body:b,headers:{"Content-Type":"application/json"},keepalive:true,credentials:"omit"})}catch(e){}}'
            . 'window.addEventListener("error",function(e){var t=e&&e.target;'
            . 'if(t&&t!==window&&t.tagName==="SCRIPT"){send("Script failed to load",t.src,0,0);return}'
            . 'if(e&&e.message)send(e.message,e.filename,e.lineno,e.colno)},true);'
            . 'window.addEventListener("unhandledrejection",function(e){var r=e&&e.reason,st=r&&r.stack?String(r.stack):"",'
            . 'f=(st.match(/https?:\/\/[^\s)]+/)||[""])[0].replace(/:\d+:\d+$/,"");'
            . 'send("Unhandled promise rejection: "+(r&&r.message?r.message:r),f,0,0)})})(' . $cfg . ');');
    }

    public static function routes(): void
    {
        register_rest_route('magesentinel/v1', '/js-error', [
            'methods' => 'POST',
            'callback' => [self::class, 'receive'],
            // Shoppers are not logged in; the route takes only bounded, sanitised text and is rate limited.
            'permission_callback' => '__return_true',
        ]);
    }

    public static function receive(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!self::enabled()) return new \WP_REST_Response(null, 204);
        $body = json_decode((string) $req->get_body(), true);
        if (!is_array($body)) return new \WP_REST_Response(null, 204);

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        $ipKey = 'ms_jse_' . substr(hash('sha256', $ip . wp_salt('nonce')), 0, 20);
        $seen = (int) get_transient($ipKey);
        if ($seen >= self::PER_IP) return new \WP_REST_Response(null, 204);
        set_transient($ipKey, $seen + 1, 10 * MINUTE_IN_SECONDS);
        $hourKey = 'ms_jse_hour_' . gmdate('YmdH');
        $hour = (int) get_transient($hourKey);
        if ($hour >= self::PER_HOUR) return new \WP_REST_Response(null, 204);
        set_transient($hourKey, $hour + 1, HOUR_IN_SECONDS);

        $msg = self::scrub((string) ($body['m'] ?? ''));
        if ($msg === '') return new \WP_REST_Response(null, 204);
        $src = (string) ($body['s'] ?? '');
        $src = preg_match('#^https?://#i', $src) ? substr(strtok(strtok($src, '#'), '?'), 0, 200) : '';
        $line = max(0, min(10000000, (int) ($body['l'] ?? 0)));
        $page = in_array($body['p'] ?? '', ['cart', 'checkout'], true) ? $body['p'] : 'checkout';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '';
        if (preg_match('/bot|crawl|spider|headless|lighthouse/i', $ua)) return new \WP_REST_Response(null, 204);
        self::record($msg, $src, $line, $page, self::browser($ua), time());
        return new \WP_REST_Response(null, 204);
    }

    /** Emails, long numbers and tokens in a message are the shopper's, not the bug's. */
    private static function scrub(string $m): string
    {
        $m = wp_strip_all_tags($m);
        $m = preg_replace('/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', '[email]', $m);
        $m = preg_replace('/\d{6,}/', '[number]', (string) $m);
        $m = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[token]', (string) $m);
        return trim(substr((string) $m, 0, 300));
    }

    private static function browser(string $ua): string
    {
        if (stripos($ua, 'Edg/') !== false) return 'Edge';
        if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
        if (stripos($ua, 'SamsungBrowser') !== false) return 'Samsung';
        if (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS') !== false) return 'Firefox';
        if (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome/') !== false) return 'Chrome';
        if (stripos($ua, 'Safari/') !== false) return 'Safari';
        return 'Other';
    }

    public static function record(string $msg, string $src, int $line, string $page, string $browser, int $now): void
    {
        $d = self::data();
        $key = substr(md5($msg . '|' . $src . '|' . $line), 0, 16);
        $hour = gmdate('YmdH', $now);
        $e = $d['errors'][$key] ?? ['m' => $msg, 's' => $src, 'l' => $line, 'p' => [], 'b' => [], 'h' => [], 'n' => 0, 'first' => $now, 'last' => $now];
        $e['n']++;
        $e['last'] = $now;
        $e['p'][$page] = ($e['p'][$page] ?? 0) + 1;
        $e['b'][$browser] = ($e['b'][$browser] ?? 0) + 1;
        $e['h'][$hour] = ($e['h'][$hour] ?? 0) + 1;
        $d['errors'][$key] = $e;
        self::prune($d, $now);
        update_option(self::OPTION, $d, false);
    }

    private static function prune(array &$d, int $now): void
    {
        $cut = gmdate('YmdH', $now - self::DAYS * DAY_IN_SECONDS);
        foreach ($d['errors'] as $k => &$e) {
            $e['h'] = array_filter($e['h'], static function ($n, $h) use ($cut) { return (string) $h > $cut; }, ARRAY_FILTER_USE_BOTH);
            if (!$e['h']) unset($d['errors'][$k]);
        }
        unset($e);
        if (count($d['errors']) > self::MAX_ERRORS) {
            uasort($d['errors'], static function ($a, $b) { return $b['last'] <=> $a['last']; });
            $d['errors'] = array_slice($d['errors'], 0, self::MAX_ERRORS, true);
        }
    }

    public static function data(): array
    {
        $d = get_option(self::OPTION, []);
        if (!is_array($d) || !isset($d['errors']) || !is_array($d['errors'])) $d = ['errors' => []];
        return $d;
    }

    private static function count_since(array $e, int $since): int
    {
        $from = gmdate('YmdH', $since);
        $n = 0;
        foreach ($e['h'] as $h => $c) if ((string) $h >= $from) $n += (int) $c;
        return $n;
    }

    /**
     * The errors seen in the last 14 days, most frequent in the last 24 hours first.
     *
     * @return array<int,array{m:string,s:string,l:int,pages:string,browsers:string,n24:int,n:int,last:int}>
     */
    public static function summary(): array
    {
        $now = time();
        $out = [];
        foreach (self::data()['errors'] as $e) {
            arsort($e['b']);
            $out[] = [
                'm' => (string) $e['m'], 's' => (string) $e['s'], 'l' => (int) $e['l'],
                'pages' => implode(', ', array_keys($e['p'])),
                'browsers' => implode(', ', array_map(static function ($b, $n) { return $b . ' ' . $n; }, array_keys($e['b']), $e['b'])),
                'n24' => self::count_since($e, $now - DAY_IN_SECONDS + HOUR_IN_SECONDS),
                'n1' => self::count_since($e, $now),
                'n' => array_sum($e['h']),
                'last' => (int) $e['last'],
            ];
        }
        usort($out, static function ($a, $b) { return [$b['n24'], $b['last']] <=> [$a['n24'], $a['last']]; });
        return $out;
    }

    public static function where(array $e): string
    {
        if ($e['s'] === '') return '';
        $path = (string) wp_parse_url($e['s'], PHP_URL_PATH);
        $file = $path !== '' ? basename($path) : $e['s'];
        return $file . ($e['l'] ? ':' . $e['l'] : '');
    }

    /** The health row: on the cart or checkout, any error is lost money. */
    public static function row(): ?array
    {
        if (!self::enabled()) return null;
        $base = ['key' => 'js_errors', 'icon' => 'code', 'label' => __('Checkout JavaScript errors', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=magesentinel-monitor#ms-js-errors'), 'link_text' => __('See the errors', 'magesentinel-monitor')];
        $errors = array_values(array_filter(self::summary(), static function ($e) { return $e['n24'] > 0; }));
        if (!$errors) {
            return ['key' => 'js_errors', 'icon' => 'code', 'label' => $base['label'], 'state' => 'good', 'summary' => __('No JavaScript errors from shoppers on the cart or checkout in 24 hours.', 'magesentinel-monitor')];
        }
        $total = array_sum(array_column($errors, 'n24'));
        $top = $errors[0];
        $where = self::where($top);
        $line = sprintf(
            /* translators: %1$d: occurrences, %2$d: distinct errors, %3$s: the most frequent error, %4$s: file and line, %5$d: its occurrences */
            _n('%1$d JavaScript error from shoppers on the cart or checkout in 24 hours (%2$d distinct). Most frequent: "%3$s"%4$s, %5$d×.', '%1$d JavaScript errors from shoppers on the cart or checkout in 24 hours (%2$d distinct). Most frequent: "%3$s"%4$s, %5$d×.', $total, 'magesentinel-monitor'),
            $total, count($errors), (strlen($top['m']) > 90 ? wp_html_excerpt($top['m'], 89, '…') : $top['m']), $where !== '' ? ' (' . $where . ')' : '', $top['n24']
        );
        $active = $top['n1'] >= 3;
        return $base + ['state' => ($total >= 20 || $active) ? 'bad' : 'watch', 'summary' => $line];
    }

    public static function clear(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Not allowed.', 'magesentinel-monitor'));
        check_admin_referer('magesentinel_js_errors_clear');
        delete_option(self::OPTION);
        wp_safe_redirect(admin_url('admin.php?page=magesentinel-monitor#ms-js-errors'));
        exit;
    }
}
