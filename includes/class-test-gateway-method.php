<?php
/** Loaded by MageSentinel_Monitor_Test_Gateway once WooCommerce is up. */

defined('ABSPATH') || exit;

final class MageSentinel_Monitor_Test_Gateway_Method extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = MageSentinel_Monitor_Test_Gateway::ID;
        $this->method_title = 'MageSentinel test order';
        $this->method_description = 'Used only by MageSentinel checks that carry this store\'s token. Takes no payment.';
        $this->title = 'MageSentinel test order';
        $this->description = '';
        $this->has_fields = false;
        $this->enabled = 'yes';
        $this->supports = ['products'];
    }

    public function is_available()
    {
        return MageSentinel_Monitor_Test_Gateway::is_trusted_request();
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || !MageSentinel_Monitor_Test_Gateway::is_trusted_request()) {
            return ['result' => 'failure'];
        }
        // On hold, like a bank transfer: an order exists, nothing is paid.
        $order->update_status('on-hold', 'Test order placed by MageSentinel monitoring. No payment taken; it will be deleted automatically.');
        wc_reduce_stock_levels($order_id);
        MageSentinel_Monitor_Test_Gateway::remember($order);
        if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart();
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }
}
