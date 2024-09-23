<link rel="preconnect" href="https://fonts.gstatic.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<div class="sender-container <?php if (!$apiKey || get_option('sender_account_disconnected')) {
    echo 'sender-single-column sender-d-flex';
} ?>">
    <div class="sender-flex-column">
        <?php
        // Check if the sync has finished
        $syncFinished = get_transient(Sender_Helper::TRANSIENT_SYNC_FINISHED);
        if ($syncFinished) {
            echo '<div id="sender-data-sync-notice" class="notice notice-success is-dismissible"><p>';
            _e('Synced data completed.', 'sender-net-automated-emails');
            echo '</p></div>';
            echo '<br>';
            delete_transient(Sender_Helper::TRANSIENT_SYNC_FINISHED);
        }
        ?>
        <?php if (!$apiKey || get_option('sender_account_disconnected')) { ?>
            <form method="post" action=''
                  class="sender-box sender-br-5 sender-api-key sender-d-flex sender-flex-dir-column"
                  novalidate="novalidate">
                <div class="sender-login-image">
                    <img src="<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/logo.svg'; ?>"
                         class="sender-logo" alt="Sender logo">
                </div>

                <div class="sender-flex-center-column sender-h-100 sender-d-flex sender-flex-dir-column">

                    <?php if (get_option('sender_account_message')) { ?>
                        <div class="sender-mb-20 sender-notification sender-is-danger sender-br-5">
                            <?php echo get_option('sender_account_message') ?>
                        </div>
                    <?php } ?>
                    <div class="sender-d-flex">
                        <label for="sender_api_key" class="sender-label sender-form-label">
                            <?php _e('Enter your API key', 'sender-net-automated-emails'); ?>
                        </label>
                    </div>
                    <input name="sender_api_key" type="text" id="sender_api_key" placeholder="<?php esc_attr_e('Paste your API key here', 'sender-net-automated-emails'); ?>"
                           class="sender-input sender-text-input sender-mb-20 sender-br-5">
                    <input type="submit" name="submit" id="submit"
                           class="sender-cta-button sender-large sender-mb-20 sender-br-5"
                           value="<?php _e('Begin', 'sender-net-automated-emails'); ?>">

                    <?php wp_nonce_field( 'sender_admin_referer' ); ?>

                    <div class="sender-api-text">
                        <?php _e('Click here', 'sender-net-automated-emails'); ?>
                        <a href="https://app.sender.net/settings/tokens" target="_blank" class="sender-link">
                            <?php _e('if you are not sure where to find it', 'sender-net-automated-emails'); ?>
                        </a>
                    </div>

                </div>
            </form>

        <?php } else { ?>
            <div class="sender-settings-layout sender-d-flex sender-flex-dir-column">
                <div class="sender-flex-dir-column sender-box sender-br-5 sender-d-flex sender-justified-between">
                    <div>
                        <div class="sender-mb-20">
                            <img src="<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/logo.svg'; ?>"
                                 class="sender-logo sender-small" alt="Sender logo">
                        </div>

                    </div>

                <div class="sender-logout sender-d-flex sender-justified-between">
                    <div class="sender-status-display sender-d-flex sender-mb-20 sender-p-relative">
                        <div class="sender-green-checkbox">
                            <span class="sender-checkbox-tick"></span>
                        </div>
                        <div class="sender-status-text sender-p-relative sender-default-text">
                            <?php
                            _e('Connected to Sender account', 'sender-net-automated-emails');
                            echo '<br><strong>' . esc_html(get_option('sender_account_title')) . '</strong>';
                            ?>
                        </div>
                    </div>

                    <div id="foobar" class="sender-btn-wrap sender-d-flex">
                        <div class="sender-mb-20">
                            <input type="button" name="submit" id="sender-confirmation"
                                   class="sender-cta-button sender-medium sender-br-5"
                                   value="<?php esc_attr_e('Change user', 'sender-net-automated-emails'); ?>">
                        </div>
                        <div id="sender-modal-wrapper" class="sender-container" style="display: none">
                            <div id="sender-modal-confirmation">
                                <div id="sender-modal-header">
                                    <h3 class="sender-header"><?php _e("Confirm Delete", "sender-net-automated-emails"); ?></h3>
                                    <span class="sender-modal-action" id="sender-modal-close">x</span>
                                </div>
                                <div id="sender-modal-content" class="sender-label sender-select-label sender-form-label">
                                    <p><?php _e("This will disconnect the store from your Sender account.", "sender-net-automated-emails"); ?></p>
                                    <form id="sender-modal-form" method="post" action="">
                                        <div style="margin: 15px 0 25px;">
                                            <label class="sender-label" for="delete-subscribers-checkbox">
                                                <input class="sender-checkbox" type="checkbox" id="delete-subscribers-checkbox" name="delete-subscribers">
                                                <span class="sender-visible-checkbox sender-visible-checkbox-modal" style="background-image: url(http://sender-dev.local/wp-content/plugins/wordpress-dev/assets/images/white_check.png)"></span>
                                                <span><?php _e("Delete subscribers associated with this store", "sender-net-automated-emails"); ?></span>
                                            </label>
                                        </div>
                                        <div id="sender-modal-buttons">
                                            <button id="no-disconnected" class="sender-cta-button sender-medium sender-br-5 sender-modal-action-btn sender-modal-action"><?php _e("No", "sender-net-automated-emails"); ?></button>
                                            <input name="sender_account_disconnected" type="hidden" id="sender_account_disconnected" value="true">
                                            <input type="submit" name="submit" class="sender-cta-button sender-medium sender-br-5 sender-modal-action-btn" value="<?php _e("Yes", "sender-net-automated-emails"); ?>">
                                        </div>
                                        <?php wp_nonce_field( 'sender_admin_referer' ); ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($wooEnabled) { ?>
                <div class="sender-plugin-settings sender-box sender-br-5 sender-p-relative">
                    <div class="sender-header sender-mb-20"><?php _e('WooCommerce settings', 'sender-net-automated-emails'); ?></div>
                    <form method="post" class="sender-flex-dir-column sender-d-flex sender-h-100" action=''
                          id="sender-form-settings">
                        <div class="sender-options sender-d-flex sender-flex-dir-column">
                            <div class="sender-option sender-d-flex sender-p-relative sender-mb-20">
                                <input type="hidden" value="0" name="sender_allow_tracking_hidden_checkbox">
                                <label for="sender_allow_tracking"
                                       class="sender-label sender-checkbox-label sender-p-relative">
                                    <input class="sender-checkbox" type="checkbox" id="sender_allow_tracking"
                                           value="sender_allow_tracking"
                                           name="sender_allow_tracking" <?php if (get_option('sender_allow_tracking')) {
                                        echo 'checked';
                                    } ?> >
                                    <span class="sender-visible-checkbox"
                                          style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)"></span>
                                    <span><?php _e('Enable tracking', 'sender-net-automated-emails'); ?></span>
                                </label>
                            </div>

                            <div class="sender-option sender-mb-20">
                                <div class="">
                                    <label class="sender-label sender-select-label sender-form-label"
                                           for="sender_customers_list"><?php _e("Save Customers who made a purchase to:", 'sender-net-automated-emails')?>
                                    </label>
                                    <div class="sender-select-wrap sender-p-relative">
                                        <select form="sender-form-settings"
                                                class="sender-woo-lists sender-br-5 select2-custom"
                                                name="sender_customers_list" <?php if (!get_option('sender_allow_tracking')) {
                                            echo 'disabled';
                                        } ?> id="sender_customers_list"
                                                value="<?= get_option('sender_customers_list') ?>">
                                            <option value="0"><?php _e("Select a list", 'sender-net-automated-emails')?></option>
                                            <?php foreach (get_option('sender_groups_data') as $groupId => $groupTitle): ?>
                                                <option <?= get_option('sender_customers_list') == $groupId ? 'selected' : '' ?>
                                                        value="<?= $groupId ?>"><?= $groupTitle ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="sender-option sender-mb-20">
                                <div>
                                    <label class="sender-label sender-select-label sender-form-label"
                                           for="sender_registration_list"><?php _e("Save New registrations to:", 'sender-net-automated-emails')?></label>
                                    <div class="sender-select-wrap sender-p-relative">
                                        <select form="sender-form-settings" <?php if (!get_option('sender_allow_tracking')) {
                                            echo 'disabled';
                                        } ?> name="sender_registration_list"
                                                class="sender-woo-lists sender-br-5 select2-custom"
                                                id="sender_registration_list"
                                                value="<?= get_option('sender_registration_list') ?>">
                                            <option value="0"><?php _e("Select a list", 'sender-net-automated-emails')?></option>
                                            <?php foreach (get_option('sender_groups_data') as $groupId => $groupTitle): ?>
                                                <option <?= get_option('sender_registration_list') == $groupId ? 'selected' : '' ?>
                                                        value="<?= $groupId ?>"><?= $groupTitle ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="sender-logout sender-d-flex sender-justified-between">
                            <div class="sender-default-text sender-mb-20">
                                <a href="https://help.sender.net/knowledgebase/the-documentation-for-woocommerce-plugin/"
                                   target="_blank" class="sender-link"><?php _e('Click here', 'sender-net-automated-emails')?></a> <?php _e('for documentation of WooCommerce plugin','sender-net-automated-emails')?>
                            </div>
                            <div class="sender-btn-wrap sender-d-flex">
                                <input type="submit" name="submit" id="submit"
                                       class="sender-cta-button sender-medium sender-mb-20 sender-br-5"
                                       value="<?php _e('Save', 'sender-net-automated-emails')?>">
                            </div>
                        </div>
                        <?php wp_nonce_field( 'sender_admin_referer' ); ?>
                    </form>

                </div>
                <div class="sender-plugin-settings sender-box sender-br-5 sender-p-relative sender-mb-20">
                    <div class="sender-header sender-mb-20"><?php _e('Subscribe to newsletter label', 'sender-net-automated-emails')?></div>
                    <p><?php _e('Change the default text showing in cart checkouts and user account profile to your custom text.', 'sender-net-automated-emails')?></p>
                    <p><strong><?php _e('Enable tracking must be active', 'sender-net-automated-emails')?></strong></p>
                    <form method="post" class="sender-flex-dir-column sender-d-flex sender-h-100" action=''
                          id="sender-form-settings">
                        <div class="sender-options sender-d-flex sender-flex-dir-column">
                            <div class="sender-option sender-d-flex sender-p-relative sender-mb-20">
                                <input type="hidden" value="0" name="sender_subscribe_label_hidden_checkbox">
                                <label for="sender_subscribe_label"
                                       class="sender-label sender-checkbox-label sender-p-relative">
                                    <input class="sender-checkbox sender-label-subscribe" type="checkbox"
                                           id="sender_subscribe_label"
                                           value="sender_subscribe_label"
                                           name="sender_subscribe_label" <?php if (get_option('sender_subscribe_label')) {
                                        echo 'checked';
                                    } ?> >
                                    <span class="sender-visible-checkbox"
                                          style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)"></span>
                                    <span><?php _e('Enable', 'sender-net-automated-emails')?></span>
                                </label>
                            </div>
                            <div class="sender-option sender-mb-20">
                                <div class="sender-subscriber-label-input">
                                    <label>
                                        <input maxlength="255" name="sender_subscribe_to_newsletter_string"
                                               type="text"
                                               class="sender-input sender-text-input sender-mb-20 sender-br-5 sender-label-subscribe"
                                               id="sender_subscribe_to_newsletter_string"
                                               value="<?php echo esc_attr(get_option('sender_subscribe_to_newsletter_string')) ?>">
                                        <input type="submit" name="submit" id="submit-label-newsletter"
                                               class="sender-cta-button sender-large sender-mb-20 sender-br-5 sender-submit-label-subscribe"
                                               value="<?php _e('Save', 'sender-net-automated-emails')?>">
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php wp_nonce_field( 'sender_admin_referer' ); ?>
                    </form>

                </div>

                <div class="sender-flex-dir-column sender-box sender-br-5 sender-d-flex sender-justified-between sender-mt-20">
                    <form method="post" class="sender-flex-dir-column sender-d-flex sender-h-100" action=''
                          id="sender-export-data">
                        <div class="sender-mb-20">
                            <?php
                            // Check if there is a running or scheduled cron job
                            if ((isset($isCronJobRunning) && $isCronJobRunning) && (isset($syncFinished) && !$syncFinished)) {
                                $disableSubmit = 'disabled';
                                $noticeMessage = esc_html__('A job is running to sync data with Sender application.', 'sender-net-automated-emails');
                            } else {
                                $disableSubmit = '';
                                $noticeMessage = esc_html__('Import all subscribers, orders, and products from your WooCommerce store into your Sender account.', 'sender-net-automated-emails');
                            }
                            ?>
                            <input name="sender_wocommerce_sync" type="hidden" id="sender_wocommerce_sync" value="0"
                                   class="sender-input sender-text-input sender-br-5">
                            <div class="sender-btn-wrap sender-d-flex">
                                <input type="submit" name="submit" id="sender-submit-sync"
                                       class="sender-cta-button sender-medium sender-br-5 sender-height-fit"
                                       value="<?php _e('Sync with Sender', 'sender-net-automated-emails')?>" <?php echo $disableSubmit; ?>>
                                <div class="sender-default-text" id="sender-import-text">
                                    <?php echo $noticeMessage; ?>
                                    <a target="_blank" class="sender-link"
                                       href="https://app.sender.net/settings/connected-stores"><?php _e('See your store information', 'sender-net-automated-emails')?></a>
                                    <span style="display: block"><?php _e('Last time synchronized:', 'sender-net-automated-emails')?> <strong
                                                style="display: block"><?php echo get_option('sender_synced_data_date'); ?></strong></span>
                                </div>
                            </div>
                        </div>
                        <?php wp_nonce_field( 'sender_admin_referer' ); ?>
                    </form>
                </div>
            <?php } else { ?>
            <div class="sender-box sender-br-5 sender-p-relative" style="padding-top:0px!important">
                <?php
                echo '<p>' . esc_html__('You can now find your', 'sender-net-automated-emails') . ' <a target="_blank" class="sender-link" href="https://app.sender.net/forms">' . esc_html__('Sender.net forms', 'sender-net-automated-emails') . '</a> ' . esc_html__('in WordPress widgets or in the page builder.', 'sender-net-automated-emails') . '</p>';
                ?>
            </div>
        </div>
    <?php } ?>
    </div>
    <?php } ?>
</div>
</div>

<!--Select2 sender styling-->
<style>
    .select2 {
        font-size: 13px !important;
    }

    .select2-selection, .select2-selection__clear, .select2-selection__arrow {
        height: 40px !important;
    }

    .select2-selection__rendered {
        line-height: 40px !important;
        color: #000000 !important;
    }

    .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable, .select2-results__option:hover, .select2-results__option:active, .select2-results__option:focus {
        background-color: #ff8d00 !important;
    }

    .select2-results__option {
        color: #0a0c0d;
    }

    .select2-search__field {
        border-color: transparent !important;
        box-shadow: 0 0 0 1px #8a8787 !important;
    }

    .select2-selection__arrow, .select2-selection__clear {
        font-size: 18px !important;
    }

    .select2-selection__arrow b {
        border-color: #000 transparent transparent transparent !important;
    }

    .select2-selection__clear {
        margin-right: 35px !important;
    }

    .select2-selection__arrow {
        margin-right: 5px !important;
    }

    .select2-selection__rendered {
        margin-left: 5px !important;
    }

</style>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script>
    var checkboxEl = jQuery('#sender_allow_tracking');
    var checkboxLabel = jQuery('#sender_subscribe_label');


    jQuery(document).ready(function () {
        if (checkboxEl[0] && !checkboxEl[0].checked) {
            jQuery('.sender-dropdown-wrap').addClass('sender-disabled');
            jQuery('.sender-subscriber-label-input').addClass('sender-disabled');
            jQuery('.sender-label-subscribe').prop('disabled', true);
        }

        var textField = jQuery('#sender_subscribe_to_newsletter_string');
        var submitBtn = jQuery('#submit-label-newsletter');

        textField.on('input', function() {
            if (textField.val().trim() === '') {
                submitBtn.prop('disabled', true);
                if (!textField.next('.sender-error-message').length) {
                    textField.after('<div class="sender-error-message" style="color:#b41d1d!important;margin-bottom:10px;">This field cannot be empty.</div>');
                }
            } else {
                textField.next('.sender-error-message').remove();
                submitBtn.prop('disabled', false);
            }
        });
    });

    checkboxEl.on('change', function (ev) {
        jQuery('.sender-woo-lists').prop('disabled', !jQuery(ev.currentTarget).is(':checked'));
        jQuery('.sender-dropdown-wrap').toggleClass('sender-disabled', !jQuery(ev.currentTarget).is(':checked'));
        jQuery('.sender-subscriber-label-input').toggleClass('sender-disabled', !jQuery(ev.currentTarget).is(':checked'))
            .prop('disabled', !jQuery(ev.currentTarget).is(':checked'));
        jQuery('.sender-label-subscribe').toggleClass('sender-disabled', !jQuery(ev.currentTarget).is(':checked'))
            .prop('disabled', !jQuery(ev.currentTarget).is(':checked'));
    });

    checkboxLabel.on('change', function (ev) {
        jQuery('.sender-subscriber-label-input').prop('disabled', !jQuery(ev.currentTarget).is(':checked'));
    });

    jQuery(document).ready(function ($) {
        $('#sender-confirmation').click(function (e) {
            e.preventDefault();
            showModal();
        });
    });

    function showModal() {
        var modal = document.getElementById("sender-modal-wrapper");

        if (!modal) return;

        modal.style.display = "inline-block";

        setTimeout(function () {
            modal.classList.add("active");
        }, 100);

        var closeButton = modal.querySelector("#no-disconnected");

        if (!closeButton) return;

        function hideHandle(e) {
            e.preventDefault();
            modal.classList.remove("active");
            setTimeout(function () {
                closeButton.removeEventListener("click", hideHandle);
                modal.style.display = "none";
            }, 1000);
        }

        closeButton.addEventListener("click", hideHandle);
    }

    jQuery(document).ready(function() {
        if (jQuery.fn.select2) {
            jQuery('.select2-custom').select2({
                placeholder: 'Select a list',
                allowClear: true,
                width: '100%',
            });
        }
    });

</script>