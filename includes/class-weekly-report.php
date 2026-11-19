<?php
/**
 * The Monday email: last week's orders, revenue and failed payments against
 * the week before, the health checks as they stand, and what was updated.
 * Built on this site from its own orders; nothing leaves the server but the
 * email, sent through wp_mail. MageSentinel's test orders are not counted.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Weekly_Report
{
    const HOOK = 'magesentinel_monitor_weekly_report';

    public static function boot(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'schedule']);
        add_action('admin_post_magesentinel_weekly_send', [self::class, 'send_now']);
    }

    /** Monday 08:00 in the store's own time zone, every week. */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK)) return;
        $next = new DateTimeImmutable('next monday 08:00', wp_timezone());
        wp_schedule_event($next->getTimestamp(), 'weekly', self::HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run(): void
    {
        if (!MageSentinel_Monitor::settings()['weekly_report']) return;
        self::send();
    }

    /** "Send one now" on the settings page. */
    public static function send_now(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('', 403);
        check_admin_referer('magesentinel_weekly_send');
        $ok = self::send();
        wp_safe_redirect(admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG . '-settings&ms_weekly=' . ($ok ? 'sent' : 'failed') . '#alerts'));
        exit;
    }

    private static function send(): bool
    {
        $to = MageSentinel_Monitor_Self_Check::recipient();
        if ($to === '' || !function_exists('wc_get_orders')) return false;
        $d = self::data();
        $text = self::text($d);
        // HTML for the mail client, the plain text as its fallback part.
        $alt = static function ($m) use ($text) { $m->AltBody = $text; };
        add_action('phpmailer_init', $alt);
        $ok = (bool) wp_mail($to, self::subject($d), self::html($d), ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $alt);
        return $ok;
    }

    /**
     * Orders, revenue and failed payments between two timestamps, with the
     * orders per day of the store's calendar. Revenue and orders cover the
     * orders paid or being paid; failed are the payments that did not go through.
     *
     * @return array{orders:int,revenue:float,failed:int,days:array<string,int>}
     */
    public static function totals(int $from, int $to): array
    {
        $out = ['orders' => 0, 'revenue' => 0.0, 'failed' => 0, 'days' => []];
        $tz = wp_timezone();
        for ($t = $from; $t < $to; $t += DAY_IN_SECONDS) $out['days'][wp_date('Y-m-d', $t + 3600, $tz)] = 0;
        $page = 1;
        do {
            $orders = wc_get_orders([
                'limit' => 200,
                'page' => $page++,
                'type' => 'shop_order',
                'status' => ['processing', 'completed', 'on-hold', 'failed'],
                'date_created' => $from . '...' . ($to - 1),
            ]);
            foreach ($orders as $order) {
                if (!$order instanceof WC_Order || MageSentinel_Monitor::is_test_order($order)) continue;
                if ($order->get_status() === 'failed') { $out['failed']++; continue; }
                $out['orders']++;
                $out['revenue'] += (float) $order->get_total();
                $c = $order->get_date_created();
                $day = $c ? wp_date('Y-m-d', $c->getTimestamp(), $tz) : '';
                if (isset($out['days'][$day])) $out['days'][$day]++;
            }
        } while (count($orders) === 200 && $page <= 50);
        return $out;
    }

    /** Everything the email says, computed once for both the HTML and the text part. */
    public static function data(): array
    {
        $end = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
        $start = $end - 7 * DAY_IN_SECONDS;
        $w = self::totals($start, $end);
        $p = self::totals($start - 7 * DAY_IN_SECONDS, $start);
        $rows = MageSentinel_Monitor_Admin::health_rows();
        $settings = MageSentinel_Monitor::settings();
        $tests = MageSentinel_Monitor::test_orders_since($start);
        return [
            'host' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'range' => date_i18n('j M', $start) . ' – ' . date_i18n('j M', $end - 1),
            'w' => $w,
            'p' => $p,
            'aov' => $w['orders'] ? $w['revenue'] / $w['orders'] : 0.0,
            'aov_before' => $p['orders'] ? $p['revenue'] / $p['orders'] : 0.0,
            'rows' => $rows,
            'bad' => array_values(array_filter($rows, static function ($r) { return $r['state'] !== 'good'; })),
            'updates' => MageSentinel_Monitor::recent_updates(7 * DAY_IN_SECONDS),
            'connected' => $settings['token_hash'] !== '',
            'tests' => count($tests),
            'overview' => admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG),
            'settings' => admin_url('admin.php?page=' . MageSentinel_Monitor_Admin::SLUG . '-settings#alerts'),
        ];
    }

    public static function subject(array $d): string
    {
        return sprintf(/* translators: %1$s: value, %2$s: value, %3$s: value */ __('[%1$s] Your week: %2$s orders, %3$s', 'magesentinel-monitor'), $d['host'], number_format_i18n($d['w']['orders']), self::money($d['w']['revenue']));
    }

    /** Percent change, or null when the week before had nothing to compare with. */
    private static function pct(float $now, float $before): ?int
    {
        return $before > 0 ? (int) round(100 * ($now - $before) / $before) : null;
    }


    private static function money(float $n): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($n)), ENT_QUOTES, 'UTF-8');
    }

    /** " (+12% vs the week before)", or nothing without a week to compare with. */
    private static function change(float $now, float $before): string
    {
        $pct = self::pct($now, $before);
        if ($pct === null) return '';
        if ($pct === 0) return ' (' . __('same as the week before', 'magesentinel-monitor') . ')';
        return ' (' . ($pct > 0 ? '+' : '−') . abs($pct) . '% ' . __('vs the week before', 'magesentinel-monitor') . ')';
    }

    public static function text(array $d): string
    {
        $w = $d['w'];
        $p = $d['p'];
        $lines = [
            sprintf(/* translators: %1$s: value, %2$s: value */ __('Your week on %1$s (%2$s):', 'magesentinel-monitor'), $d['host'], $d['range']),
            '',
            sprintf(/* translators: %s: value */ __('Orders: %s', 'magesentinel-monitor'), number_format_i18n($w['orders'])) . self::change($w['orders'], $p['orders']),
            sprintf(/* translators: %s: value */ __('Revenue: %s', 'magesentinel-monitor'), self::money($w['revenue'])) . self::change($w['revenue'], $p['revenue']),
        ];
        if ($w['orders']) $lines[] = sprintf(/* translators: %s: value */ __('Average order: %s', 'magesentinel-monitor'), self::money($d['aov'])) . self::change($d['aov'], $d['aov_before']);
        $lines[] = sprintf(/* translators: %s: value */ __('Failed payments: %s', 'magesentinel-monitor'), number_format_i18n($w['failed'])) . ($p['failed'] || $w['failed'] ? ' (' . sprintf(/* translators: %s: value */ __('%d the week before', 'magesentinel-monitor'), $p['failed']) . ')' : '');
        $lines[] = '';
        if ($d['bad']) {
            $lines[] = sprintf(/* translators: %1$s: value, %2$s: value */ __('Needs a look now (%1$d of %2$d checks):', 'magesentinel-monitor'), count($d['bad']), count($d['rows']));
            foreach ($d['bad'] as $r) $lines[] = '• ' . $r['label'] . ': ' . $r['summary'];
        } else {
            $lines[] = sprintf(/* translators: %s: value */ __('All %d health checks are green right now.', 'magesentinel-monitor'), count($d['rows']));
        }
        if ($d['updates']) {
            $lines[] = '';
            $lines[] = __('Updated this week:', 'magesentinel-monitor');
            foreach ($d['updates'] as $u) $lines[] = '• ' . trim($u['name'] . ' ' . $u['version']);
        }
        $lines[] = '';
        if ($d['connected'] && $d['tests']) {
            $lines[] = sprintf(/* translators: %s: value */ __('MageSentinel placed %d test purchases this week (not counted above).', 'magesentinel-monitor'), $d['tests']);
        } else {
            $lines[] = __('The numbers show that orders came in; they cannot show the ones that could not. MageSentinel places a real test purchase every hour and tells you the moment checkout breaks. 14 days free, no card:', 'magesentinel-monitor');
            $lines[] = MageSentinel_Monitor_Self_Check::SIGNUP_URL;
        }
        $lines[] = '';
        $lines[] = __('Full overview:', 'magesentinel-monitor');
        $lines[] = $d['overview'];
        $lines[] = __('Stop this weekly email:', 'magesentinel-monitor');
        $lines[] = $d['settings'];
        return implode("\n", $lines);
    }

    /** @return array{subject:string,body:string} The plain-text version, kept for callers that want one string. */
    public static function email(): array
    {
        $d = self::data();
        return ['subject' => self::subject($d), 'body' => self::text($d)];
    }

    // ------------------------------------------------------------------ HTML
    // Tables and inline styles only: that is what Gmail, Outlook and Apple Mail
    // all render the same. The look follows MageSentinel's own report email.

    const ACCENT = '#4f6ef7';
    const INK = '#111827';
    const MUTED = '#6b7280';
    const GOOD = '#16a34a';
    const WATCH = '#d97706';
    const BAD = '#dc2626';

    /**
     * Capitals made here, not with CSS: mail clients ignore the page language,
     * so text-transform keeps Greek accents (ΠΑΡΑΓΓΕΛΊΕΣ). Greek capitals take
     * no tonos; a diaeresis stays (Ϊ, Ϋ).
     */
    private static function caps(string $v): string
    {
        $v = strtr($v, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ΐ' => 'ϊ', 'ΰ' => 'ϋ', 'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω']);
        return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
    }

    private static function e(string $v): string
    {
        return esc_html($v);
    }

    /** A "+12%" chip: green up, red down; for failed payments the colours swap. */
    private static function chip(?int $pct, bool $lowerIsBetter = false): string
    {
        if ($pct === null) return '';
        if ($pct === 0) return '<span style="display:inline-block;padding:2px 7px;border-radius:99px;background:#f3f4f6;color:' . self::MUTED . ';font-size:11px;font-weight:600">±0%</span>';
        $good = $lowerIsBetter ? $pct < 0 : $pct > 0;
        $fg = $good ? self::GOOD : self::BAD;
        $bg = $good ? '#dcfce7' : '#fee2e2';
        return '<span style="display:inline-block;padding:2px 7px;border-radius:99px;background:' . $bg . ';color:' . $fg . ';font-size:11px;font-weight:700">' . ($pct > 0 ? '▲ +' : '▼ −') . abs($pct) . '%</span>';
    }

    private static function tile(string $label, string $value, string $chip, string $colour = self::INK): string
    {
        return '<td width="25%" style="padding:0 6px 12px 0;vertical-align:top">'
            . '<div style="background:#f6f7fb;border-radius:10px;padding:14px 14px 12px">'
            . '<div style="font-size:11px;font-weight:600;letter-spacing:.05em;color:' . self::MUTED . ';margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . self::e(self::caps(strpos($label, 'tile:') === 0 ? substr($label, 5) : $label)) . '</div>'
            . '<div style="font-size:22px;line-height:1.15;font-weight:700;color:' . $colour . ';white-space:nowrap">' . self::e($value) . '</div>'
            . '<div style="margin-top:6px;min-height:18px">' . $chip . '</div>'
            . '</div></td>';
    }

    /** Orders per day as bars: a table cell per day, its height the count. */
    private static function chart(array $days, array $before): string
    {
        $max = max(1, max($days), max($before ?: [0]));
        $h = 110;
        $cells = '';
        $labels = '';
        $prev = array_values($before);
        $i = 0;
        foreach ($days as $day => $n) {
            $bar = $n > 0 ? max(4, (int) round($h * $n / $max)) : 0;
            $ghost = isset($prev[$i]) && $prev[$i] > 0 ? max(3, (int) round($h * $prev[$i] / $max)) : 0;
            $cells .= '<td width="14%" style="vertical-align:bottom;text-align:center;padding:0 4px;height:' . ($h + 22) . 'px">'
                . '<div style="font-size:12px;font-weight:700;color:' . ($n ? self::INK : '#c3c8d4') . ';margin-bottom:4px">' . (int) $n . '</div>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                . '<td style="vertical-align:bottom;padding-right:2px"><div style="height:' . $ghost . 'px;background:#dfe4f5;border-radius:4px 4px 0 0;font-size:0;line-height:0">&nbsp;</div></td>'
                . '<td style="vertical-align:bottom" width="62%"><div style="height:' . $bar . 'px;background:' . self::ACCENT . ';border-radius:4px 4px 0 0;font-size:0;line-height:0">&nbsp;</div></td>'
                . '</tr></table></td>';
            $labels .= '<td style="text-align:center;font-size:11px;color:' . self::MUTED . ';padding-top:6px;border-top:1px solid #e5e7eb">' . self::e(date_i18n('D', strtotime($day . ' 12:00:00'))) . '</td>';
            $i++;
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr><tr>' . $labels . '</tr></table>'
            . '<div style="font-size:11px;color:' . self::MUTED . ';margin-top:8px">'
            . '<span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' . self::ACCENT . ';vertical-align:-1px"></span> ' . self::e(__('This week', 'magesentinel-monitor'))
            . ' &nbsp; <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#dfe4f5;vertical-align:-1px"></span> ' . self::e(__('The week before', 'magesentinel-monitor'))
            . '</div>';
    }

    private static function button(string $url, string $label, bool $primary = true): string
    {
        $style = $primary
            ? 'background:' . self::ACCENT . ';color:#ffffff;border:1px solid ' . self::ACCENT
            : 'background:#ffffff;color:' . self::ACCENT . ';border:1px solid #c9d3fb';
        return '<a href="' . esc_url($url) . '" style="display:inline-block;padding:11px 22px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;' . $style . '">' . self::e($label) . ' →</a>';
    }

    private static function section(string $title): string
    {
        return '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;color:' . self::MUTED . ';margin:26px 0 10px">' . self::e(self::caps($title)) . '</div>';
    }

    public static function html(array $d): string
    {
        $w = $d['w'];
        $p = $d['p'];
        $locale = substr((string) get_locale(), 0, 2);

        $tiles = self::tile(__('Orders', 'magesentinel-monitor'), number_format_i18n($w['orders']), self::chip(self::pct($w['orders'], $p['orders'])))
            . self::tile(__('Revenue', 'magesentinel-monitor'), self::money($w['revenue']), self::chip(self::pct($w['revenue'], $p['revenue'])))
            . self::tile(__('Average order', 'magesentinel-monitor'), $w['orders'] ? self::money($d['aov']) : '—', $w['orders'] ? self::chip(self::pct($d['aov'], $d['aov_before'])) : '')
            . self::tile(__('tile:Failed payments', 'magesentinel-monitor'), number_format_i18n($w['failed']), self::chip(self::pct($w['failed'], $p['failed']), true), $w['failed'] > 0 ? self::BAD : self::INK);

        if ($d['bad']) {
            $health = '';
            foreach ($d['bad'] as $r) {
                $c = $r['state'] === 'bad' ? self::BAD : self::WATCH;
                $health .= '<tr><td width="18" style="vertical-align:top;padding:12px 0 12px 14px"><div style="width:10px;height:10px;border-radius:50%;background:' . $c . ';margin-top:4px"></div></td>'
                    . '<td style="padding:10px 14px 10px 8px;border-bottom:1px solid #f1f2f6"><div style="font-size:14px;font-weight:600;color:' . self::INK . '">' . self::e($r['label']) . '</div>'
                    . '<div style="font-size:13px;color:#4b5563;margin-top:2px">' . self::e($r['summary']) . '</div></td></tr>';
            }
            $health = '<div style="font-size:14px;color:' . self::INK . ';margin-bottom:8px">' . self::e(sprintf(/* translators: %1$s: value, %2$s: value */ __('Needs a look now (%1$d of %2$d checks):', 'magesentinel-monitor'), count($d['bad']), count($d['rows']))) . '</div>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eceef4;border-radius:10px">' . $health . '</table>';
        } else {
            $health = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px"><tr>'
                . '<td width="28" style="padding:14px 0 14px 16px;vertical-align:middle"><div style="width:28px;height:28px;border-radius:50%;background:' . self::GOOD . ';color:#ffffff;font-size:16px;font-weight:700;line-height:28px;text-align:center">✓</div></td>'
                . '<td style="padding:14px 16px 14px 10px;font-size:14px;font-weight:600;color:#14532d">' . self::e(sprintf(/* translators: %s: value */ __('All %d health checks are green right now.', 'magesentinel-monitor'), count($d['rows']))) . '</td></tr></table>';
        }

        $updates = '';
        if ($d['updates']) {
            $items = '';
            foreach ($d['updates'] as $u) {
                $items .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f1f2f6;font-size:14px;color:' . self::INK . '">' . self::e($u['name'])
                    . (!empty($u['checkout']) ? ' <span style="display:inline-block;padding:1px 7px;border-radius:99px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:600">' . 'checkout' . '</span>' : '')
                    . '</td><td style="padding:8px 0;border-bottom:1px solid #f1f2f6;font-size:13px;color:' . self::MUTED . ';text-align:right;white-space:nowrap">' . self::e((string) $u['version']) . ' · ' . self::e(date_i18n('D j M', (int) $u['at'])) . '</td></tr>';
            }
            $updates = self::section(__('Updated this week', 'magesentinel-monitor')) . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $items . '</table>';
        }

        if ($d['connected'] && $d['tests']) {
            $cta = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px;background:#f6f7fb;border-radius:10px"><tr><td style="padding:16px 18px;font-size:14px;color:' . self::INK . '">'
                . self::e(sprintf(/* translators: %s: value */ __('MageSentinel placed %d test purchases this week (not counted above).', 'magesentinel-monitor'), $d['tests']))
                . '<div style="margin-top:12px">' . self::button(MageSentinel_Monitor::DASHBOARD_URL, __('Open MageSentinel', 'magesentinel-monitor'), false) . '</div></td></tr></table>';
        } else {
            $cta = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px;background:#eef1fe;border:1px solid #d6ddfd;border-radius:10px"><tr><td style="padding:18px 20px">'
                . '<div style="font-size:15px;font-weight:700;color:' . self::INK . ';margin-bottom:4px">' . self::e(__('What the numbers cannot show', 'magesentinel-monitor')) . '</div>'
                . '<div style="font-size:14px;line-height:1.5;color:#374151">' . self::e(__('The numbers show that orders came in; they cannot show the ones that could not. MageSentinel places a real test purchase every hour and tells you the moment checkout breaks.', 'magesentinel-monitor')) . '</div>'
                . '<div style="margin-top:14px">' . self::button(MageSentinel_Monitor_Self_Check::SIGNUP_URL, __('Try it 14 days free', 'magesentinel-monitor')) . '</div></td></tr></table>';
        }

        return '<!DOCTYPE html><html lang="' . esc_attr($locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#eef0f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">'
            . '<div style="display:none;max-height:0;overflow:hidden">' . self::e(self::text_preheader($d)) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f5"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e3e6ee">'
            // Header band
            . '<tr><td style="background:' . self::ACCENT . ';background-image:linear-gradient(135deg,#4f6ef7,#6d5ef6);padding:22px 26px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            // The mark is drawn in the mail itself: opening it loads nothing from anywhere.
            . '<td width="46" style="vertical-align:middle"><div style="width:38px;height:38px;border-radius:9px;background:#ffffff;color:' . self::ACCENT . ';font-size:20px;font-weight:800;line-height:38px;text-align:center">M</div></td>'
            . '<td style="vertical-align:middle;padding-left:12px"><div style="color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.09em;opacity:.85">' . self::e(self::caps('MageSentinel · ' . __('Weekly summary', 'magesentinel-monitor'))) . '</div>'
            . '<div style="color:#ffffff;font-size:20px;font-weight:700;margin-top:2px">' . self::e($d['host']) . '</div>'
            . '<div style="color:#ffffff;font-size:13px;opacity:.85;margin-top:1px">' . self::e($d['range']) . '</div></td>'
            . '</tr></table></td></tr>'
            // Body
            . '<tr><td style="padding:24px 26px 8px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $tiles . '</tr></table>'
            . self::section(__('Orders per day', 'magesentinel-monitor'))
            . self::chart($w['days'], $p['days'])
            . self::section(__('Health checks', 'magesentinel-monitor'))
            . $health
            . $updates
            . $cta
            . '<div style="margin:24px 0 8px">' . self::button($d['overview'], __('Open the overview', 'magesentinel-monitor')) . '</div>'
            . '</td></tr>'
            // Footer
            . '<tr><td style="padding:16px 26px 22px;border-top:1px solid #eceef4;font-size:12px;line-height:1.5;color:#9ca3af">'
            . self::e(sprintf(/* translators: %s: value */ __('Sent by the MageSentinel plugin on %s. MageSentinel test orders are not counted.', 'magesentinel-monitor'), $d['host'])) . ' '
            . '<a href="' . esc_url($d['settings']) . '" style="color:' . self::ACCENT . ';text-decoration:none">' . self::e(__('Stop this weekly email', 'magesentinel-monitor')) . '</a>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** The line mail clients show next to the subject. */
    private static function text_preheader(array $d): string
    {
        return $d['bad']
            ? sprintf(/* translators: %s: value */ __('%d checks need a look.', 'magesentinel-monitor'), count($d['bad']))
            : sprintf(/* translators: %s: value */ __('All %d health checks are green right now.', 'magesentinel-monitor'), count($d['rows']));
    }
}
