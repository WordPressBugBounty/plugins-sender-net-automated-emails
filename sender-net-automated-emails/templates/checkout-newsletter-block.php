<?php
/**
 * Newsletter control for WooCommerce Blocks checkout.
 *
 * @var string $label Current newsletter label.
 * @var string $checkboxId Shared checkbox identifier and field name.
 * @var bool $checked Default checkbox state.
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wc-block-components-checkbox wc-block-checkout__create-account">
    <label for="<?php echo esc_attr($checkboxId); ?>">
        <input type="checkbox"
               id="<?php echo esc_attr($checkboxId); ?>"
               name="<?php echo esc_attr($checkboxId); ?>"
               class="wc-block-components-checkbox__input" <?php checked($checked); ?>>
        <svg class="wc-block-components-checkbox__mark" aria-hidden="true"
             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 20">
            <path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path>
        </svg>
        <span class="wc-block-components-checkbox__label"><?php echo esc_html($label); ?></span>
    </label>
</div>
