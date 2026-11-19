<?php
/**
 * The shop owner's own CAPTCHA exemption for MageSentinel's checks.
 *
 * Off by default. When the owner switches it on, a storefront request that
 * carries this store's connector token (the X-MageSentinel-Token header, the
 * same secret the REST endpoints check) is served as if the CAPTCHA plugins
 * were configured to leave it alone: no widget on the form, no verification on
 * submit. Every other visitor sees exactly what they saw before.
 *
 * MageSentinel never solves or gets around a CAPTCHA. This is the store
 * telling its own CAPTCHA plugin "this one is mine", through each plugin's
 * documented allow-list hook — nothing is patched, nothing is disabled for
 * anyone else.
 *
 * Covered (the CAPTCHA plugins seen on monitored WooCommerce stores):
 * - CAPTCHA 4WP / Advanced noCaptcha & invisible Captcha (reCAPTCHA, hCaptcha, Turnstile)
 * - reCAPTCHA for WooCommerce (Elliot Sowersby)
 * - hCaptcha for WP
 * - Simple CAPTCHA Alternative with Cloudflare Turnstile
 */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Captcha
{
    /** Hooks registered by reCAPTCHA for WooCommerce at load time, with their priorities. */
    const RECAPTCHA_WOO = [
        ['woocommerce_review_order_before_payment', 'rcfwc_field_checkout', 10],
        ['woocommerce_review_order_after_payment', 'rcfwc_field_checkout', 10],
        ['woocommerce_before_checkout_billing_form', 'rcfwc_field_checkout', 10],
        ['woocommerce_after_checkout_billing_form', 'rcfwc_field_checkout', 10],
        ['woocommerce_review_order_before_submit', 'rcfwc_field_checkout', 10],
        ['render_block_woocommerce/checkout-payment-block', 'rcfwc_render_pre_block', 999],
        ['render_block_woocommerce/checkout-payment-block', 'rcfwc_render_post_block', 999],
        ['render_block_woocommerce/checkout-contact-information-block', 'rcfwc_render_pre_block', 999],
        ['render_block_woocommerce/checkout-shipping-methods-block', 'rcfwc_render_pre_block', 999],
        ['render_block_woocommerce/checkout-actions-block', 'rcfwc_render_pre_block', 999],
        ['woocommerce_checkout_process', 'rcfwc_checkout_check', 10],
        ['woocommerce_store_api_checkout_update_order_from_request', 'rcfwc_checkout_block_check', 10],
        ['woocommerce_login_form', 'rcfwc_field', 10],
        ['authenticate', 'rcfwc_woo_login_check', 21],
        ['login_form', 'rcfwc_field_admin', 10],
        ['authenticate', 'rcfwc_wp_login_check', 21],
    ];

    /** @var bool|null */
    private static $trusted = null;

    /**
     * Runs when the plugin file loads, before `plugins_loaded`: hCaptcha for WP
     * decides whether to switch itself on at `plugins_loaded`, so its filter
     * has to be in place by then.
     */
    public static function boot(): void
    {
        if (!self::is_trusted_check()) return;

        // A page served without its CAPTCHA must never be cached and handed to
        // a shopper.
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the shared constant page caches read.
        add_action('send_headers', 'nocache_headers');

        // CAPTCHA 4WP: no enabled form means no widget and no check; the pre-verify
        // filters answer "verified" for anything that still asks.
        add_filter('c4wp_add_to_enabled_forms_array', '__return_empty_array', PHP_INT_MAX);
        add_filter('c4wp_verify_captcha_pre', '__return_true', PHP_INT_MAX);
        add_filter('c4wp_verify_cloudflare_captcha_pre', '__return_true', PHP_INT_MAX);
        // hCaptcha for WP: its own allow-list, which switches the plugin off for this request.
        add_filter('hcap_whitelist_ip', '__return_true', PHP_INT_MAX);
        // Simple Cloudflare Turnstile: its own allow-list, checked on render and on submit.
        add_filter('cfturnstile_whitelisted', '__return_true', PHP_INT_MAX);
        add_filter('cfturnstile_widget_disable', '__return_true', PHP_INT_MAX);
        // …except on the block checkout, where it refuses an empty token
        // before it asks the allow-list (1.43.2): take that check off too.
        add_action('plugins_loaded', [self::class, 'unhook_turnstile_block_checkout'], PHP_INT_MAX);
        // reCAPTCHA for WooCommerce has no allow-list hook: take its named
        // callbacks off this one request once every plugin has registered them.
        add_action('plugins_loaded', [self::class, 'unhook_recaptcha_woo'], PHP_INT_MAX);
    }

    public static function unhook_turnstile_block_checkout(): void
    {
        remove_action('woocommerce_store_api_checkout_update_order_from_request', 'cfturnstile_woo_checkout_block_check', 10);
    }

    public static function unhook_recaptcha_woo(): void
    {
        foreach (self::RECAPTCHA_WOO as [$hook, $callback, $priority]) {
            remove_action($hook, $callback, $priority);
        }
        // The page-wide script too: no widget is drawn, nothing needs it.
        remove_action('wp_enqueue_scripts', 'rcfwc_script_enqueue');
        remove_action('wp_enqueue_scripts', 'rcfwc_script');
        remove_action('login_enqueue_scripts', 'rcfwc_script_login');
    }

    /**
     * The owner switched both the connector and the exemption on, and this
     * request carries the store's own token. Anything less — the switch off,
     * no token stored, a wrong or missing header — is an ordinary visitor.
     */
    public static function is_trusted_check(): bool
    {
        if (self::$trusted !== null) return self::$trusted;
        $s = MageSentinel_Monitor::settings();
        $provided = isset($_SERVER['HTTP_X_MAGESENTINEL_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_MAGESENTINEL_TOKEN'])) : '';
        self::$trusted = $s['enabled']
            && $s['allow_captcha_skip']
            && $s['token_hash'] !== ''
            && $provided !== ''
            && hash_equals($s['token_hash'], hash('sha256', $provided));
        return self::$trusted;
    }
}
