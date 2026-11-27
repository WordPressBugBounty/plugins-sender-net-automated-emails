<?php
if (!defined('ABSPATH')) {
    exit;
}

// Match the display name used by the block editor and Widgets.
$senderFormName = '<strong>Sender.net Form</strong>';
?>
<div class="sender-forms-guide">
    <div class="sender-forms-intro">
        <div class="sender-default-text">
            <h2 id="sender-forms-title" class="sender-panel-title"><?php esc_html_e('Forms', 'sender-net-automated-emails'); ?></h2>
            <p><?php esc_html_e('Add an active embedded form from Sender to your WordPress site.', 'sender-net-automated-emails'); ?></p>
        </div>
        <a class="sender-secondary-button sender-medium sender-br-5" href="https://app.sender.net/forms" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e('Manage forms in Sender', 'sender-net-automated-emails'); ?>
        </a>
    </div>

    <h3 class="sender-header"><?php esc_html_e('Add a form to WordPress', 'sender-net-automated-emails'); ?></h3>
    <ul class="sender-forms-methods sender-default-text sender-br-5">
        <li>
            <span class="sender-forms-icon sender-br-5 dashicons dashicons-layout" aria-hidden="true"></span>
            <div>
                <h4><?php esc_html_e('Block editor', 'sender-net-automated-emails'); ?></h4>
                <p><?php
                    /* translators: %s: Sender form block name. */
                    printf(esc_html__('Open a page or post, insert %s, and select your form.', 'sender-net-automated-emails'), $senderFormName);
                ?></p>
            </div>
        </li>
        <li>
            <span class="sender-forms-icon sender-br-5 dashicons dashicons-screenoptions" aria-hidden="true"></span>
            <div>
                <h4><?php esc_html_e('WordPress widgets', 'sender-net-automated-emails'); ?></h4>
                <p><?php
                    /* translators: %1$s: WordPress Widgets menu path. %2$s: Sender form widget name. */
                    printf(esc_html__('If your theme has widget areas, go to %1$s, add %2$s, and select your form.', 'sender-net-automated-emails'),
                        '<strong>' . esc_html__('Appearance → Widgets', 'sender-net-automated-emails') . '</strong>', $senderFormName);
                ?></p>
            </div>
        </li>
        <li>
            <span class="sender-forms-icon sender-br-5 dashicons dashicons-images-alt2" aria-hidden="true"></span>
            <div>
                <h4><?php esc_html_e('Divi Builder', 'sender-net-automated-emails'); ?></h4>
                <p><?php
                    /* translators: %s: Sender form module name. */
                    printf(esc_html__('Add the %s module and select your form in its settings.', 'sender-net-automated-emails'),
                        '<strong>' . esc_html__('Sender.net Form', 'sender-net-automated-emails') . '</strong>');
                ?></p>
            </div>
        </li>
    </ul>

    <p class="sender-forms-help sender-default-text sender-br-5">
        <span class="dashicons dashicons-info" aria-hidden="true"></span>
        <span><strong><?php esc_html_e('Form missing?', 'sender-net-automated-emails'); ?></strong>
            <?php esc_html_e('Check that it’s an active embedded form in Sender. Then reopen your editor.', 'sender-net-automated-emails'); ?></span>
    </p>
</div>
