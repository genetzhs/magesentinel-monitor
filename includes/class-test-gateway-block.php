<?php
/** Loaded by MageSentinel_Monitor_Test_Gateway once WooCommerce is up. */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Test_Gateway_Block extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
{
    protected $name = MageSentinel_Monitor_Test_Gateway::ID;

    public function initialize() {}

    public function is_active()
    {
        return MageSentinel_Monitor_Test_Gateway::is_trusted_request();
    }

    public function get_payment_method_script_handles()
    {
        $h = 'magesentinel-test-gateway';
        wp_register_script($h, '', ['wc-blocks-registry', 'wp-element'], MageSentinel_Monitor::VERSION, true);
        wp_add_inline_script($h, "(function(){var r=window.wc&&window.wc.wcBlocksRegistry;var e=window.wp&&window.wp.element;if(!r||!e)return;var l=e.createElement('span',null,'MageSentinel test order');r.registerPaymentMethod({name:'" . MageSentinel_Monitor_Test_Gateway::ID . "',label:l,content:e.createElement('span',null,''),edit:e.createElement('span',null,''),canMakePayment:function(){return true;},ariaLabel:'MageSentinel test order',supports:{features:['products']}});})();");
        return [$h];
    }

    public function get_payment_method_data()
    {
        return ['title' => 'MageSentinel test order', 'supports' => ['products']];
    }
}
