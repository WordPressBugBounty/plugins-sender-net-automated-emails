<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Send catalog prices; Sender applies them to matching open carts. */
class Sender_Cart_Price_Sync
{
    const ACTION = 'sender_refresh_cart_prices';
    const GROUP = 'sender-cart-prices';

    private $api;
    private $queuedProducts = [];

    public function __construct($api)
    {
        $this->api = $api;
        add_action('woocommerce_product_object_updated_props', [$this, 'productChanged'], 10, 2);
        add_action(self::ACTION, [$this, 'process'], 10, 2);
        add_action('wc_product_start_scheduled_sale', [$this, 'queueProduct'], 20);
        add_action('wc_product_end_scheduled_sale', [$this, 'queueProduct'], 20);
        add_action('wc_after_products_starting_sales', [$this, 'queueProducts']);
        add_action('wc_after_products_ending_sales', [$this, 'queueProducts']);
    }

    public function productChanged($product, $properties): void
    {
        $priceProperties = ['price', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to'];
        if (array_intersect($properties, $priceProperties)) {
            $this->queueProduct($product->get_id());
        }
    }

    public function queueProducts($ids): void
    {
        foreach ($ids as $id) {
            $this->queueProduct($id);
        }
    }

    public function queueProduct($id): void
    {
        if (isset($this->queuedProducts[$id])) {
            return;
        }

        $this->queuedProducts[$id] = true;
        $this->schedule((int) $id, 0, 10);
    }

    public function process($productId, $attempt = 0): void
    {
        if (!get_option('sender_allow_tracking') || !get_option('sender_api_key')
            || !get_option('sender_store_register') || get_option('sender_account_disconnected')) {
            return;
        }

        // Read the latest price on every attempt, rather than replaying an old price.
        $updatedAt = sprintf('%.6F', microtime(true));
        $product = wc_get_product($productId);
        if (!$product || !$product->is_type(['simple', 'variation'])) {
            return;
        }

        $price = $product->get_price();
        if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
            return;
        }

        $regularPrice = $product->get_regular_price();
        $onSale = $product->is_on_sale() && is_numeric($regularPrice) && (float) $regularPrice > (float) $price;
        $result = $this->api->senderSyncCartPrices([
            'event_id' => wp_generate_uuid4(),
            'currency' => get_woocommerce_currency(),
            'products' => [[
                'product_id' => (int) $product->get_id(),
                'price' => (string) $price,
                'old_price' => $onSale ? (string) $regularPrice : null,
                'price_updated_at' => $updatedAt,
            ]],
        ]);

        if ($result === false && $attempt < 4) {
            $this->schedule((int) $productId, $attempt + 1, 60 * (2 ** $attempt));
        }
    }

    private function schedule(int $productId, int $attempt, int $delay): void
    {
        // Let plugins initialize their schedulers before choosing a queue.
        if (!did_action('init') || doing_action('init')) {
            $schedule = function () use ($productId, $attempt, $delay, &$schedule) {
                remove_action('wp_loaded', $schedule);
                $this->schedule($productId, $attempt, $delay);
            };
            add_action('wp_loaded', $schedule);
            return;
        }

        $args = [$productId, $attempt];
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            as_schedule_single_action(time() + $delay, self::ACTION, $args, self::GROUP, true);
        } elseif (!wp_next_scheduled(self::ACTION, $args)) {
            wp_schedule_single_event(time() + $delay, self::ACTION, $args);
        }
    }
}
