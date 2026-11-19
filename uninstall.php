<?php
/**
 * Deleting the plugin removes everything it stored: settings, the self-check
 * state, the counts and logs behind the overview, and its scheduled tasks.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

foreach ([
    'magesentinel_monitor',
    'magesentinel_monitor_activity',
    'magesentinel_monitor_after_update',
    'magesentinel_monitor_self_check',
    'magesentinel_monitor_changes',
    'magesentinel_monitor_funnel',
    'magesentinel_monitor_updates',
    'magesentinel_monitor_by_method',
    'magesentinel_monitor_ssl',
    'magesentinel_monitor_unbuyable',
    'magesentinel_monitor_websec',
    'magesentinel_monitor_baseline',
    'magesentinel_monitor_run_lock',
    'magesentinel_monitor_test_order_ids',
    'magesentinel_monitor_sales_closed',
    'magesentinel_monitor_js_errors',
    'magesentinel_monitor_logins',
    'magesentinel_monitor_integrity',
    'magesentinel_monitor_snoozed',
] as $magesentinel_monitor_key) {
    delete_option($magesentinel_monitor_key);
    delete_transient($magesentinel_monitor_key);
}
delete_transient('magesentinel_monitor_burst_check');
delete_transient('magesentinel_monitor_security_scan');
delete_transient('magesentinel_monitor_after_update_lock');

foreach (['magesentinel_monitor_self_check', 'magesentinel_monitor_weekly_report', 'magesentinel_monitor_after_update'] as $magesentinel_monitor_hook) {
    wp_clear_scheduled_hook($magesentinel_monitor_hook);
}
