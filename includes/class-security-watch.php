<?php
/**
 * Two security checks that need memory between page loads:
 *
 * - Login activity: failed and successful logins per hour for 7 days, the
 *   usernames bots try and the addresses they come from (masked to the
 *   network: the last part of an IPv4 address, the last 80 bits of IPv6).
 * - File integrity: fingerprints of the files an attacker edits first
 *   (wp-config.php, index.php, .htaccess, the core loader files and the
 *   theme's cart and checkout templates) against a baseline. Core and theme
 *   updates move the baseline on their own; any other change waits for the
 *   owner to accept it.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Security_Watch
{
    const LOGINS = 'magesentinel_monitor_logins';
    const BASELINE = 'magesentinel_monitor_integrity';
    const HOURS = 168;
    const TOP = 30;
    const CORE = ['index.php', 'wp-login.php', 'wp-load.php', 'wp-settings.php', 'wp-blog-header.php'];

    public static function boot(): void
    {
        add_action('wp_login_failed', [self::class, 'login_failed'], 10, 1);
        add_action('wp_login', [self::class, 'login_ok'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'after_upgrade'], 20, 2);
        add_action('admin_post_magesentinel_integrity_accept', [self::class, 'accept']);
    }

    // ------------------------------------------------------------- logins

    private static function ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return preg_replace('/\.\d+$/', '.x', $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', (string) inet_ntop((string) inet_pton($ip)));
            return implode(':', array_slice(array_pad($parts, 3, '0'), 0, 3)) . '::/48';
        }
        return '?';
    }

    public static function login_failed($username): void
    {
        $u = substr(sanitize_user((string) $username, true), 0, 60);
        self::bump(false, $u === '' ? '(empty)' : $u, null);
    }

    public static function login_ok($login, $user = null): void
    {
        self::bump(true, '', $user instanceof \WP_User ? $user : null);
    }

    private static function bump(bool $ok, string $username, ?\WP_User $user): void
    {
        $d = self::logins();
        $h = gmdate('YmdH');
        $b = $d['hours'][$h] ?? ['ok' => 0, 'fail' => 0, 'u' => [], 'ip' => []];
        $ip = self::ip();
        if ($ok) {
            $b['ok']++;
            if ($user && user_can($user, 'manage_options')) {
                array_unshift($d['admins'], ['t' => time(), 'user' => (string) $user->user_login, 'ip' => $ip]);
                $d['admins'] = array_slice($d['admins'], 0, 10);
            }
        } else {
            $b['fail']++;
            $b['u'][$username] = ($b['u'][$username] ?? 0) + 1;
            $b['ip'][$ip] = ($b['ip'][$ip] ?? 0) + 1;
            foreach (['u', 'ip'] as $k) {
                if (count($b[$k]) > self::TOP) { arsort($b[$k]); $b[$k] = array_slice($b[$k], 0, self::TOP, true); }
            }
        }
        $d['hours'][$h] = $b;
        $cut = gmdate('YmdH', time() - self::HOURS * HOUR_IN_SECONDS);
        $d['hours'] = array_filter($d['hours'], static function ($k) use ($cut) { return (string) $k > $cut; }, ARRAY_FILTER_USE_KEY);
        if (!$d['since']) $d['since'] = time();
        update_option(self::LOGINS, $d, false);
    }

    public static function logins(): array
    {
        $d = get_option(self::LOGINS, []);
        return [
            'hours' => is_array($d['hours'] ?? null) ? $d['hours'] : [],
            'admins' => is_array($d['admins'] ?? null) ? $d['admins'] : [],
            'since' => (int) ($d['since'] ?? 0),
        ];
    }

    /** Totals over the last $hours hours. */
    public static function login_totals(int $hours): array
    {
        $d = self::logins();
        $from = gmdate('YmdH', time() - ($hours - 1) * HOUR_IN_SECONDS);
        $t = ['ok' => 0, 'fail' => 0, 'u' => [], 'ip' => []];
        foreach ($d['hours'] as $h => $b) {
            if ((string) $h < $from) continue;
            $t['ok'] += (int) $b['ok'];
            $t['fail'] += (int) $b['fail'];
            foreach (['u', 'ip'] as $k) foreach ((array) $b[$k] as $name => $n) $t[$k][$name] = ($t[$k][$name] ?? 0) + (int) $n;
        }
        arsort($t['u']);
        arsort($t['ip']);
        return $t + ['admins' => $d['admins'], 'since' => $d['since']];
    }

    public static function logins_row(): array
    {
        $base = ['key' => 'logins', 'icon' => 'lock', 'label' => __('Login attempts', 'magesentinel-monitor'), 'link' => admin_url('admin.php?page=magesentinel-monitor#ms-logins'), 'link_text' => __('See the logins', 'magesentinel-monitor')];
        $t = self::login_totals(24);
        if (!$t['since']) {
            return ['key' => 'logins', 'icon' => 'lock', 'label' => $base['label'], 'state' => 'good', 'summary' => __('Counting failed logins from now on.', 'magesentinel-monitor')];
        }
        $topIp = $t['ip'] ? (int) reset($t['ip']) : 0;
        if ($t['fail'] >= 100 || $topIp >= 50) {
            $user = $t['u'] ? (string) key($t['u']) : '';
            $line = sprintf(
                /* translators: %1$d: failed logins, %2$s: most tried username, %3$d: its attempts, %4$d: distinct addresses */
                __('%1$d failed logins in 24 hours, most for "%2$s" (%3$d), from %4$d networks. A login limiter or two-factor login stops this.', 'magesentinel-monitor'),
                $t['fail'], $user, (int) ($t['u'][$user] ?? 0), count($t['ip'])
            );
            return $base + ['state' => $t['fail'] >= 1000 ? 'bad' : 'watch', 'summary' => $line];
        }
        return ['key' => 'logins', 'icon' => 'lock', 'label' => $base['label'], 'state' => 'good', 'summary' => sprintf(/* translators: %1$d: failed logins, %2$d: successful logins */ __('%1$d failed and %2$d successful logins in 24 hours.', 'magesentinel-monitor'), $t['fail'], $t['ok'])];
    }

    // ---------------------------------------------------------- integrity

    /** @return array<string,string> label => absolute path, for the files that exist */
    public static function files(): array
    {
        $root = rtrim(ABSPATH, '/\\') . '/';
        $out = [];
        foreach (self::CORE as $f) if (is_file($root . $f)) $out[$f] = $root . $f;
        if (is_file($root . 'wp-config.php')) $out['wp-config.php'] = $root . 'wp-config.php';
        elseif (is_file(dirname($root) . '/wp-config.php')) $out['../wp-config.php'] = dirname($root) . '/wp-config.php';
        if (is_file($root . '.htaccess')) $out['.htaccess'] = $root . '.htaccess';
        foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $dir) {
            foreach (['cart', 'checkout'] as $sub) {
                foreach ((array) glob($dir . '/woocommerce/' . $sub . '/*.php') as $file) {
                    $out['theme: ' . basename($dir) . '/woocommerce/' . $sub . '/' . basename((string) $file)] = (string) $file;
                }
            }
        }
        return $out;
    }

    private static function group(string $label): string
    {
        if (in_array($label, self::CORE, true)) return 'core';
        return strpos($label, 'theme: ') === 0 ? 'theme' : 'config';
    }

    /** @return array<string,string> label => sha256 */
    private static function fingerprints(): array
    {
        $out = [];
        foreach (self::files() as $label => $path) {
            if (!is_readable($path) || filesize($path) > 4 * 1024 * 1024) continue;
            $out[$label] = (string) hash_file('sha256', $path);
        }
        return $out;
    }

    public static function baseline(): array
    {
        $b = get_option(self::BASELINE, []);
        return is_array($b) && isset($b['files']) && is_array($b['files']) ? $b : [];
    }

    /** Records the current files as known, for every group or only the ones given. */
    public static function rebaseline(array $groups = []): void
    {
        $now = self::fingerprints();
        $old = self::baseline();
        $files = $old['files'] ?? [];
        if (!$groups) {
            $files = $now;
        } else {
            foreach ($files as $label => $h) if (in_array(self::group($label), $groups, true)) unset($files[$label]);
            foreach ($now as $label => $h) if (in_array(self::group($label), $groups, true)) $files[$label] = $h;
        }
        update_option(self::BASELINE, ['files' => $files, 'at' => time()], false);
    }

    /** WordPress and theme updates replace these files on purpose. */
    public static function after_upgrade($upgrader, $extra): void
    {
        $type = is_array($extra) ? (string) ($extra['type'] ?? '') : '';
        if (!self::baseline()) return;
        if ($type === 'core') self::rebaseline(['core']);
        if ($type === 'theme') self::rebaseline(['theme']);
    }

    /** @return array{changed:string[],added:string[],removed:string[]} */
    public static function diff(): array
    {
        $base = self::baseline()['files'] ?? [];
        $now = self::fingerprints();
        $changed = $added = [];
        foreach ($now as $label => $h) {
            if (!isset($base[$label])) $added[] = $label;
            elseif (!hash_equals((string) $base[$label], $h)) $changed[] = $label;
        }
        $removed = array_values(array_diff(array_keys($base), array_keys($now)));
        return ['changed' => $changed, 'added' => $added, 'removed' => $removed];
    }

    public static function integrity_row(): array
    {
        $base = ['key' => 'integrity', 'icon' => 'file', 'label' => __('Key files unchanged', 'magesentinel-monitor')];
        if (!self::baseline()) {
            self::rebaseline();
            return $base + ['state' => 'good', 'summary' => sprintf(/* translators: %d: number of files */ __('Fingerprints recorded for %d files: wp-config.php, index.php, .htaccess, the WordPress loader files and the theme\'s cart and checkout templates.', 'magesentinel-monitor'), count(self::baseline()['files'] ?? []))];
        }
        $d = self::diff();
        $all = array_merge($d['changed'], $d['added'], $d['removed']);
        if (!$all) {
            $at = (int) (self::baseline()['at'] ?? 0);
            return $base + ['state' => 'good', 'summary' => sprintf(/* translators: %1$d: number of files, %2$s: when */ __('%1$d files match their fingerprints from %2$s ago.', 'magesentinel-monitor'), count(self::baseline()['files']), human_time_diff($at, time()))];
        }
        $core = array_filter($all, static function ($l) { return self::group($l) === 'core'; });
        $parts = [];
        if ($d['changed']) $parts[] = sprintf(/* translators: %s: file names */ __('changed: %s', 'magesentinel-monitor'), implode(', ', array_slice($d['changed'], 0, 4)));
        if ($d['added']) $parts[] = sprintf(/* translators: %s: file names */ __('new: %s', 'magesentinel-monitor'), implode(', ', array_slice($d['added'], 0, 4)));
        if ($d['removed']) $parts[] = sprintf(/* translators: %s: file names */ __('gone: %s', 'magesentinel-monitor'), implode(', ', array_slice($d['removed'], 0, 4)));
        $summary = sprintf(/* translators: %s: the list of changes */ __('Files changed outside a WordPress or theme update (%s). If nobody on your side changed them, compare them with a clean copy now: skimmers and backdoors start here.', 'magesentinel-monitor'), implode('; ', $parts));
        return $base + [
            'state' => $core ? 'bad' : 'watch',
            'summary' => $summary,
            'link' => wp_nonce_url(admin_url('admin-post.php?action=magesentinel_integrity_accept'), 'magesentinel_integrity_accept'),
            'link_text' => __('I made these changes: accept them', 'magesentinel-monitor'),
        ];
    }

    public static function accept(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Not allowed.', 'magesentinel-monitor'));
        check_admin_referer('magesentinel_integrity_accept');
        self::rebaseline();
        wp_safe_redirect(admin_url('admin.php?page=magesentinel-monitor'));
        exit;
    }
}
