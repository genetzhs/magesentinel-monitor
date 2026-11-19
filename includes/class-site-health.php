<?php
/**
 * The same checkout checks, in Tools → Site Health and on the command line.
 * Admins already open Site Health; this puts a broken checkout where they look.
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Site_Health
{
    public static function boot(): void
    {
        add_filter('site_status_tests', [self::class, 'register']);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('magesentinel', 'MageSentinel_Monitor_CLI');
        }
    }

    public static function register($tests): array
    {
        $tests = is_array($tests) ? $tests : [];
        $tests['direct']['magesentinel_checkout'] = [
            'label' => __('WooCommerce checkout health', 'magesentinel-monitor'),
            'test' => [self::class, 'test'],
        ];
        return $tests;
    }

    public static function test(): array
    {
        $rows = MageSentinel_Monitor_Admin::health_rows();
        $bad = array_values(array_filter($rows, static function ($r) { return $r['state'] === 'bad'; }));
        $watch = array_values(array_filter($rows, static function ($r) { return $r['state'] === 'watch'; }));

        if ($bad) {
            $status = 'critical';
            $label = __('Something is stopping your store from taking orders', 'magesentinel-monitor');
            $color = 'red';
        } elseif ($watch) {
            $status = 'recommended';
            $label = __('Your checkout works, with things worth a look', 'magesentinel-monitor');
            $color = 'orange';
        } else {
            $status = 'good';
            $label = __('Your WooCommerce checkout looks healthy', 'magesentinel-monitor');
            $color = 'blue';
        }

        $items = '';
        foreach (array_merge($bad, $watch) as $r) {
            $items .= '<li><strong>' . esc_html($r['label']) . ':</strong> ' . esc_html($r['summary']);
            if (!empty($r['link'])) $items .= ' <a href="' . esc_url($r['link']) . '">' . esc_html(__('Fix', 'magesentinel-monitor')) . '</a>';
            $items .= '</li>';
        }
        $description = '<p>' . esc_html(__('MageSentinel checks the settings and counts that quietly stop a store from taking money: pages, payment methods, failed payments, fatal errors, scheduled actions.', 'magesentinel-monitor')) . '</p>';
        if ($items) $description .= '<ul>' . $items . '</ul>';

        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => 'WooCommerce', 'color' => $color],
            'description' => $description,
            'actions' => '<p><a href="' . esc_url(admin_url('admin.php?page=' . MageSentinel_Monitor::SLUG)) . '">' . esc_html(__('Open the MageSentinel overview', 'magesentinel-monitor')) . '</a></p>',
            'test' => 'magesentinel_checkout',
        ];
    }
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Checkout health from the command line, for agencies running many sites.
     */
    final class MageSentinel_Monitor_CLI
    {
        /**
         * Prints each checkout check and its state.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : table, json, csv or yaml.
         * ---
         * default: table
         * ---
         *
         * ## EXAMPLES
         *
         *     wp magesentinel status
         *     wp magesentinel status --format=json
         *
         * Exits with status 1 when a check is red, so it can gate a deploy script.
         *
         * @when after_wp_load
         */
        public function status($args, $assoc): void
        {
            $rows = array_map(static function ($r) {
                return ['check' => $r['label'], 'state' => $r['state'], 'summary' => $r['summary']];
            }, MageSentinel_Monitor_Admin::health_rows());
            WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['check', 'state', 'summary']);
            if (array_filter($rows, static function ($r) { return $r['state'] === 'bad'; })) {
                WP_CLI::halt(1);
            }
        }
    }
}
