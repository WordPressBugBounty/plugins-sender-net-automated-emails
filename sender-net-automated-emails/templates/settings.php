<?php
    $groups = (array)get_option('sender_groups_data') ?: [];
    $mapping = (array)get_option('sender_role_group_map') ?: [];
    $mappingOn = (bool)get_option('sender_enable_role_group_mapping');
    $roles = get_editable_roles();
    $logDownloadUrl = '';
?>

<link rel="preconnect" href="https://fonts.gstatic.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<div class="sender-container <?php if (!$apiKey || get_option('sender_account_disconnected')) {
    echo 'sender-single-column sender-d-flex';
} ?>">
    <div class="sender-flex-column">
        <?php if (get_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS)) : ?>
            <div id="sender-sync-notice" class="notice notice-info is-dismissible">
                <p>
                    <strong>
                        <?php _e('Synchronizing your shop data with Sender.', 'sender-net-automated-emails'); ?>
                        <a href="https://app.sender.net/settings/connected-stores" target="_blank">
                            <?php _e('See your store information', 'sender-net-automated-emails'); ?>
                        </a>
                    </strong>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!$apiKey || get_option('sender_account_disconnected')) { ?>
            <form method="post" action=''
                  class="sender-box sender-br-5 sender-api-key sender-d-flex sender-flex-dir-column"
                  novalidate="novalidate">
                <div class="sender-login-image">
                    <img src="<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/logo.svg'; ?>"
                         class="sender-logo" alt="Sender logo">
                </div>

                <div class="sender-flex-center-column sender-h-100 sender-d-flex sender-flex-dir-column">

                    <div class="sender-d-flex">
                        <label for="sender_api_key" class="sender-label sender-form-label">
                            <?php _e('Enter your API key', 'sender-net-automated-emails'); ?>
                        </label>
                    </div>
                    <input name="sender_api_key" type="text" id="sender_api_key"
                           placeholder="<?php esc_attr_e('Paste your API key here', 'sender-net-automated-emails'); ?>"
                           class="sender-input sender-text-input sender-mb-20 sender-br-5">
                    <input type="submit" name="submit" id="submit"
                           class="sender-cta-button sender-large sender-mb-20 sender-br-5"
                           value="<?php _e('Begin', 'sender-net-automated-emails'); ?>">

                    <?php wp_nonce_field('sender_admin_referer'); ?>

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
                                <div id="sender-modal-content"
                                     class="sender-label sender-select-label sender-form-label">
                                    <p><?php _e("This will disconnect the store from your Sender account.", "sender-net-automated-emails"); ?></p>
                                    <form id="sender-modal-form" method="post" action="">
                                        <div style="margin: 15px 0 25px;">
                                            <label class="sender-label" for="delete-subscribers-checkbox">
                                                <input class="sender-checkbox" type="checkbox"
                                                       id="delete-subscribers-checkbox" name="delete-subscribers">
                                                <span class="sender-visible-checkbox sender-visible-checkbox-modal"
                                                      style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)">
                                                </span>
                                                <span><?php _e("Delete subscribers associated with this store", "sender-net-automated-emails"); ?></span>
                                            </label>
                                        </div>
                                        <div id="sender-modal-buttons">
                                            <button id="no-disconnected"
                                                    class="sender-cta-button sender-medium sender-br-5 sender-modal-action-btn sender-modal-action"><?php _e("No", "sender-net-automated-emails"); ?></button>
                                            <input name="sender_account_disconnected" type="hidden"
                                                   id="sender_account_disconnected" value="true">
                                            <input type="submit" name="submit"
                                                   class="sender-cta-button sender-medium sender-br-5 sender-modal-action-btn"
                                                   value="<?php _e("Yes", "sender-net-automated-emails"); ?>">
                                        </div>
                                        <?php wp_nonce_field('sender_admin_referer'); ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($wooEnabled) { ?>
                <form method="post" class="sender-flex-dir-column sender-d-flex sender-h-100" action=''
                      id="sender-form-settings">
                    <div class="sender-dual-box-wrapper sender-box">
                        <div class="sender-plugin-settings sender-br-5 sender-p-relative">
                            <div class="sender-header sender-mb-20"><?php _e('WooCommerce settings', 'sender-net-automated-emails'); ?></div>
                            <!-- Enable tracking -->
                            <div class="sender-options sender-d-flex sender-flex-dir-column">
                                <div class="sender-option sender-d-flex sender-p-relative sender-mb-20">
                                    <input type="hidden" name="sender_allow_tracking" value="0">

                                    <label class="sender-label sender-checkbox-label sender-p-relative">
                                        <input class="sender-checkbox"
                                               type="checkbox"
                                               id="sender_allow_tracking"
                                               name="sender_allow_tracking"
                                               value="1"
                                                <?php checked(get_option('sender_allow_tracking'), 1); ?>>
                                        <span class="sender-visible-checkbox"
                                              style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)"></span>
                                        <span><?php _e('Enable tracking', 'sender-net-automated-emails'); ?></span>
                                    </label>
                                </div>

                                <!-- Guidance note -->
                                <div class="sender-note sender-mb-20 sender-ml-10" style="font-size: 13px; line-height: 1.7; color: #555;">
                                    <strong><?php _e('Tip:', 'sender-net-automated-emails'); ?></strong>
                                    <?php _e('Enabling tracking allows the plugin to monitor customer activity such as cart behavior and checkout progress.', 'sender-net-automated-emails'); ?><br>
                                    <div style="margin-top: 8px;">
                                        <?php _e('When enabled, new carts and subscriber profiles will be automatically created and updated in real time.', 'sender-net-automated-emails'); ?>
                                    </div>
                                </div>

                                <!-- Purchase group -->
                                <div class="sender-option sender-mb-20">
                                    <div class="">
                                        <label class="sender-label sender-select-label sender-form-label"
                                               for="sender_customers_list"><?php _e("Save Customers who made a purchase to:", 'sender-net-automated-emails') ?>
                                        </label>
                                        <div class="sender-select-wrap sender-p-relative">
                                            <select form="sender-form-settings"
                                                    class="sender-woo-lists sender-br-5 select2-custom"
                                                    name="sender_customers_list" <?php if (!get_option('sender_allow_tracking')) {
                                                echo 'disabled';
                                            } ?> id="sender_customers_list"
                                                    value="<?= get_option('sender_customers_list') ?>">
                                                <option value="0"><?php _e("Select a list", 'sender-net-automated-emails') ?></option>
                                                <?php foreach ($groups as $groupId => $groupTitle): ?>
                                                    <option <?= get_option('sender_customers_list') == $groupId ? 'selected' : '' ?>
                                                            value="<?= $groupId ?>"><?= $groupTitle ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Registration group -->
                                <div id="sender-registration-list-wrap" class="sender-option sender-mb-20">
                                    <label class="sender-label sender-select-label sender-form-label"
                                           for="sender_registration_list"><?php _e("Save New registrations to:", 'sender-net-automated-emails'); ?>
                                    </label>
                                    <div class="sender-select-wrap sender-p-relative">
                                        <select form="sender-form-settings"
                                                name="sender_registration_list"
                                                class="sender-woo-lists sender-br-5 select2-custom"
                                                id="sender_registration_list"
                                                value="<?= get_option('sender_registration_list') ?>">
                                            <option value="0"><?php _e("Select a list", 'sender-net-automated-emails'); ?></option>
                                            <?php foreach ($groups as $groupId => $groupTitle): ?>
                                                <option <?= get_option('sender_registration_list') == $groupId ? 'selected' : '' ?>
                                                        value="<?= $groupId ?>"><?= $groupTitle ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Registration role => group -->
                                <div class="sender-option sender-d-flex sender-p-relative sender-mb-20 sender-mt-20">
                                    <input type="hidden" value="0" name="sender_enable_role_group_mapping">
                                    <label for="sender_enable_role_group_mapping"
                                           class="sender-label sender-checkbox-label sender-p-relative">
                                        <input class="sender-checkbox" type="checkbox" id="sender_enable_role_group_mapping"
                                               name="sender_enable_role_group_mapping"
                                               value="1" <?php checked($mappingOn); ?>>
                                        <span class="sender-visible-checkbox"
                                              style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)">
            </span>
                                        <span><?php _e('Assign registration group by user role', 'sender-net-automated-emails'); ?></span>
                                    </label>
                                </div>

                                <!-- Guidance note -->
                                <div class="sender-note sender-mb-20 sender-ml-10" style="font-size: 13px; line-height: 1.7; color: #555;">
                                    <strong><?php _e('Tip:', 'sender-net-automated-emails'); ?></strong>
                                    <?php _e('WordPress includes built-in roles like <em>Administrator</em>, <em>Editor</em>, and <em>Subscriber</em>.', 'sender-net-automated-emails'); ?>
                                    <?php _e('WooCommerce adds roles such as <em>Customer</em> and <em>Shop Manager</em>.', 'sender-net-automated-emails'); ?><br>
                                    <div style="margin-top: 8px;">
                                        <?php _e('When this option is enabled, new users will automatically be assigned to a Sender group based on their role.', 'sender-net-automated-emails'); ?>
                                    </div>
                                    <div style="margin-top: 8px;">
                                        <?php _e('These role-to-group mappings will also be used when exporting your store data to the Sender application.', 'sender-net-automated-emails'); ?>
                                    </div>
                                    <div style="margin-top: 8px; color: #666;">
                                        <?php _e('Note: Enabling role-based group mapping will disable the "Save New registrations to" option, since both options cannot be active at the same time.', 'sender-net-automated-emails'); ?>
                                    </div>
                                </div>


                                <div id="sender-role-group-repeater-wrap" class="sender-wide-control">
                                    <!-- Table-like header -->
                                    <div class="sender-role-group-header sender-d-flex sender-mb-20 sender-bold-text">
                                        <div style="width:45%;"><?php _e('User Role', 'sender-net-automated-emails'); ?></div>
                                        <div style="width:45%;"><?php _e('Sender Group', 'sender-net-automated-emails'); ?></div>
                                        <div style="width:10%;"></div>
                                    </div>

                                    <div id="sender-role-group-repeater">
                                        <?php
                                        $roles = get_editable_roles();
                                        $mapping = (array)get_option('sender_role_group_map') ?: [];

                                        if (!empty($mapping)):
                                            foreach ($mapping as $roleSlug => $groupId):
                                                ?>
                                                <div class="sender-role-group-row sender-d-flex sender-mb-20 sender-align-center">
                                                    <select name="sender_role_group_map_roles[]" class="sender-br-5 select2-custom sender-role-select" style="width:45%">
                                                        <option value="0"><?php _e('Select role', 'sender-net-automated-emails'); ?></option>
                                                        <?php foreach ($roles as $slug => $info): ?>
                                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($roleSlug, $slug); ?>>
                                                                <?php echo esc_html($info['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>

                                                    <select name="sender_role_group_map_groups[]" class="sender-br-5 select2-custom sender-group-select" style="width:45%">
                                                        <option value="0"><?php _e('Select group', 'sender-net-automated-emails'); ?></option>
                                                        <?php foreach ($groups as $groupIdKey => $groupTitle): ?>
                                                            <option value="<?php echo esc_attr($groupIdKey); ?>" <?php selected($groupId, $groupIdKey); ?>>
                                                                <?php echo esc_html($groupTitle); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>

                                                    <button type="button" class="sender-remove-role-group sender-cta-button sender-small sender-br-5">
                                                        <?php _e('Remove', 'sender-net-automated-emails'); ?>
                                                    </button>
                                                </div>
                                            <?php
                                            endforeach;
                                        else:
                                            ?>
                                            <div class="sender-empty-state sender-note sender-mt-10 sender-ml-10" style="font-size:13px; line-height:1.7; color:#777; text-align:center; padding:12px; border:1px dashed #ddd; border-radius:5px;">
                                                <?php _e('No role mappings added yet. Click “Add Role Mapping” to create your first one.', 'sender-net-automated-emails'); ?>
                                            </div>
                                        <?php
                                        endif;
                                        ?>
                                    </div>

                                    <button type="button" id="sender-add-role-group" class="sender-secondary-button sender-medium sender-br-5 sender-mt-20">
                                        <?php _e('Add Role Mapping', 'sender-net-automated-emails'); ?>
                                    </button>
                                </div>
                            </div>

                        </div>

                        <div class="sender-plugin-settings sender-br-5 sender-p-relative">
                            <div class="sender-header sender-mb-20"><?php _e('Register to our newsletter', 'sender-net-automated-emails') ?></div>
                            <p><strong><?php _e('Enable tracking must be active', 'sender-net-automated-emails') ?></strong></p>
                            <div class="sender-options sender-d-flex sender-flex-dir-column">

                            <div class="sender-option sender-d-flex sender-p-relative sender-mb-20">
                                <input type="hidden" value="0" name="sender_subscribe_label">
                                <label for="sender_subscribe_label"
                                       class="sender-label sender-checkbox-label sender-p-relative">
                                    <input class="sender-checkbox sender-label-subscribe" type="checkbox"
                                           id="sender_subscribe_label"
                                           value="1" name="sender_subscribe_label"
                                            <?php if (get_option('sender_subscribe_label')) {
                                                echo 'checked';
                                            } ?>>
                                    <span class="sender-visible-checkbox"
                                          style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)"></span>
                                    <span><?php _e('Show subscription checkbox on checkout and registration pages', 'sender-net-automated-emails'); ?></span>
                                </label>
                            </div>

                            <!-- Guidance note for auto-subscribe -->
                            <div class="sender-note sender-mb-20 sender-ml-10" style="font-size: 13px; line-height: 1.7; color: #555;">
                                <strong><?php _e('Tip:', 'sender-net-automated-emails'); ?></strong>
                                <?php _e('When enabled, all new users — including those signing up or logging in via third-party plugins like <em>Nextend Social Login</em> — are automatically added to your selected Sender list or role-based group.', 'sender-net-automated-emails'); ?>
                                <div style="margin-top: 8px;">
                                    <?php _e('When disabled, no subscriber is created during registration or login, regardless of opt-in value.', 'sender-net-automated-emails'); ?>
                                </div>
                                <div style="margin-top: 8px;">
                                    <?php _e('Note: This only applies to users who register without adding items to their cart. Once a cart is created, Sender still adds the user with active transactional email status for automations like cart recovery and advance filters', 'sender-net-automated-emails'); ?>
                                </div>
                            </div>


                            <!-- Pre-check subscription box option -->
                            <div class="sender-option sender-d-flex sender-p-relative sender-mb-20" id="sender-precheck-option">
                                <input type="hidden" value="0" name="sender_checkbox_newsletter_on_checkout">
                                <label for="sender_checkbox_newsletter_on_checkout"
                                       class="sender-label sender-checkbox-label sender-p-relative sender-mb-20">
                                    <input class="sender-checkbox sender-label-subscribe" type="checkbox"
                                           id="sender_checkbox_newsletter_on_checkout"
                                           value="1" name="sender_checkbox_newsletter_on_checkout"
                                            <?php if (get_option('sender_checkbox_newsletter_on_checkout')) {
                                                echo 'checked';
                                            } ?>>
                                    <span class="sender-visible-checkbox"
                                          style="background-image: url(<?php echo plugin_dir_url(dirname(__FILE__)) . 'assets/images/white_check.png'; ?>)"></span>
                                    <span><?php _e('Pre-check subscription box on checkout and registration', 'sender-net-automated-emails'); ?></span>
                                </label>
                                <small class="sender-note"><?php _e('Requires "Show subscription checkbox" enabled.', 'sender-net-automated-emails'); ?></small>
                            </div>


                            <!-- Newsletter label text -->
                            <div class="sender-option sender-mb-20" id="sender-newsletter-label-section">
                                <div class="sender-subscriber-label-input">
                                    <label for="sender_subscribe_to_newsletter_string" class="sender-label sender-form-label">
                                        <?php _e('Subscription checkbox label text', 'sender-net-automated-emails'); ?>
                                    </label>

                                    <input maxlength="255" name="sender_subscribe_to_newsletter_string" type="text"
                                           class="sender-input sender-text-input sender-mb-10 sender-br-5 sender-label-subscribe"
                                           id="sender_subscribe_to_newsletter_string"
                                           placeholder="<?php esc_attr_e('e.g. Subscribe to our newsletter', 'sender-net-automated-emails'); ?>"
                                           value="<?php echo esc_attr(get_option('sender_subscribe_to_newsletter_string')); ?>">

                                    <div class="sender-note" style="font-size: 13px; line-height: 1.6; color: #555;">
                                        <?php _e('This text appears next to the subscription checkbox on checkout and registration pages. You can customize it to better match your brand voice.', 'sender-net-automated-emails'); ?>
                                    </div>

                                    </div>
                                </div>

                                <!--Save-->
                                <div class="sender-btn-wrap sender-d-flex sender-mt-20 sender-pt-20">
                                    <input type="submit" name="submit" id="submit"
                                           class="sender-cta-button sender-medium sender-mb-20 sender-br-5"
                                           value="<?php _e('Save Woocommerce settings', 'sender-net-automated-emails'); ?>">
                                </div>

                            </div>
                            <?php wp_nonce_field('sender_admin_referer'); ?>

                    </div>

                </div>
            </form>
                <div class="sender-flex-dir-column sender-box sender-br-5 sender-d-flex sender-justified-between sender-mt-20">
                    <form method="post" class="sender-flex-dir-column sender-d-flex sender-h-100" action=''
                          id="sender-export-data">
                        <div class="sender-mb-20">
                            <?php
                            $isSyncRunning = get_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS);
                            if ($isSyncRunning) {
                                $disableSubmit = 'disabled';
                                $noticeMessage = esc_html__('A job is running to sync data with Sender application.', 'sender-net-automated-emails');
                            } else {
                                $disableSubmit = '';
                                $noticeMessage = esc_html__('Import all subscribers, orders, and products from your WooCommerce store into your Sender account.', 'sender-net-automated-emails');
                            }
                            ?>
                            <input name="sender_wocommerce_sync" type="hidden" id="sender_wocommerce_sync" value="0"
                                   class="sender-input sender-text-input sender-br-5">
                            <div class="sender-btn-wrap sender-d-flex sender-flex-justify-start">
                                <input type="submit" name="submit" id="sender-submit-sync"
                                       class="sender-cta-button sender-medium sender-br-5 sender-height-fit"
                                       value="<?php _e('Sync with Sender', 'sender-net-automated-emails') ?>" <?php echo $disableSubmit; ?>>
                                <div class="sender-default-text" id="sender-import-text">
                                    <?php echo $noticeMessage; ?>
                                    <a target="_blank" class="sender-link"
                                       href="https://app.sender.net/settings/connected-stores"><?php _e('See your store information', 'sender-net-automated-emails') ?></a>
                                    <span style="display: block"><?php _e('Last time synchronized:', 'sender-net-automated-emails') ?> <strong
                                                style="display: block"><?php echo get_option('sender_synced_data_date'); ?></strong></span>
                                    <br>
                                    <span>When completed a debug information file would be available to download</span>
                                </div>
                            </div>

                            <!-- SYNC log file -->
                            <?php
                            $logFilePath = plugin_dir_path(__FILE__) . '../export-log.txt';
                            $logDownloadUrl = plugins_url('export-log.txt', dirname(__FILE__));

                            if (file_exists($logFilePath) && !get_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS)) {
                                ?>
                                <div class="sender-option sender-mb-20 sender-mt-20">
                                    <a href="<?php echo esc_url($logDownloadUrl); ?>"
                                       class="sender-secondary-button sender-medium sender-br-5"
                                       download>
                                        <?php _e('Download Sync Log', 'sender-net-automated-emails'); ?>
                                    </a>
                                </div>
                                <?php
                            }
                            ?>

                        </div>
                        <?php wp_nonce_field('sender_admin_referer'); ?>
                    </form>

                    <div class="sender-default-text sender-mb-20">
                        <a href="https://help.sender.net/knowledgebase/the-documentation-for-woocommerce-plugin/"
                           target="_blank" class="sender-link">
                            <?php _e('Click here', 'sender-net-automated-emails'); ?>
                        </a>
                        <?php _e('for documentation of WooCommerce plugin', 'sender-net-automated-emails'); ?>
                    </div>
                </div>

                <div class="sender-box sender-br-5 sender-mt-20 sender-p-20" style="width:50%!important">
                    <h3 class="sender-header">Debug Information</h3>

                    <p class="sender-note" style="margin-bottom:12px;">
                        If you contact Sender support, please download and attach the debug file below.
                        It contains configuration details that help us diagnose issues quickly.
                    </p>

                    <a href="<?php echo admin_url('admin-post.php?action=sender_debug_download'); ?>"
                       class="sender-secondary-button sender-medium sender-br-5">
                        Download Debug File
                    </a>
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

    .sender-note {
        font-size: 14px;
        color: #666;
        margin-left: 10px;
        display: inline-block;
        vertical-align: middle;
    }

    .sender-role-group-header {
        font-weight: 600;
        color: #222;
        font-size: 14px;
        margin-top: 10px;
    }
    .sender-role-group-row select {
        min-width: 160px;
    }


</style>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>

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

        textField.on('input', function () {
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
        jQuery('#sender_enable_role_group_mapping').toggleClass('sender-disabled', !jQuery(ev.currentTarget).is(':checked'))
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

        var deleteCheckbox = modal.querySelector("#delete-subscribers-checkbox");
        if (deleteCheckbox) deleteCheckbox.checked = false;

        modal.style.display = "inline-block";

        setTimeout(function () {
            modal.classList.add("active");
        }, 100);

        var closeButton = modal.querySelector("#no-disconnected");
        var xButton = modal.querySelector("#sender-modal-close");

        function hideModal(e) {
            e.preventDefault();
            modal.classList.remove("active");
            setTimeout(function () {
                modal.style.display = "none";
            }, 300);
        }

        if (closeButton) {
            closeButton.addEventListener("click", hideModal);
        }

        if (xButton) {
            xButton.addEventListener("click", hideModal);
        }
    }

    window.addEventListener("click", function (e) {
        var modal = document.getElementById("sender-modal-wrapper");
        if (e.target === modal) {
            modal.style.display = "none";
            modal.classList.remove("active");
        }
    });

    jQuery(document).ready(function () {
        if (jQuery.fn.select2) {
            jQuery('.select2-custom').select2({
                placeholder: 'Select a list',
                allowClear: true,
                width: '50%',
            });
        }
    });

    jQuery(function ($) {
        const $roleGroupToggle = $('#sender_enable_role_group_mapping');
        const $repeaterWrap = $('#sender-role-group-repeater-wrap');
        const $saveNewReg = $('#sender_registration_list');

        function initSelect2ForRepeater() {
            if ($.fn.select2) {
                $repeaterWrap.find('select.select2-custom').each(function () {
                    if ($(this).data('select2')) {
                        $(this).select2('destroy');
                    }
                });
                $repeaterWrap.find('select.select2-custom:not(:disabled)').select2({
                    placeholder: 'Select an option',
                    allowClear: true,
                    width: '100%',
                });
            }
        }

        function updateRoleGroupState() {
            const on = $roleGroupToggle.is(':checked');

            if (on) {
                // Enable repeater + disable "Save New registrations to"
                $repeaterWrap.removeClass('sender-disabled')
                    .find('input, select, button')
                    .prop('disabled', false);
                $saveNewReg.prop('disabled', true)
                    .closest('.sender-select-wrap')
                    .addClass('sender-disabled');
                initSelect2ForRepeater();
            } else {
                // Disable repeater + enable "Save New registrations to"
                $repeaterWrap.addClass('sender-disabled')
                    .find('input, select, button')
                    .prop('disabled', true);
                $saveNewReg.prop('disabled', false)
                    .closest('.sender-select-wrap')
                    .removeClass('sender-disabled');
            }
        }

        $roleGroupToggle.on('change', updateRoleGroupState);
        updateRoleGroupState();
    });

    jQuery(function ($) {
        const $repeater = $('#sender-role-group-repeater');
        const $roles = <?php echo json_encode(array_map(fn($r) => $r['name'], get_editable_roles())); ?>;
        const $groups = <?php echo json_encode($groups); ?>;

        $('#sender-add-role-group').on('click', function () {
            const newRow = $('<div class="sender-role-group-row sender-d-flex sender-mb-10 sender-align-center">');

            const roleSelect = $('<select name="sender_role_group_map_roles[]" class="sender-br-5 select2-custom sender-role-select" style="width:45%">')
                .append('<option value="0">Select role</option>');
            $.each($roles, function (slug, label) {
                roleSelect.append(`<option value="${slug}">${label}</option>`);
            });

            const groupSelect = $('<select name="sender_role_group_map_groups[]" class="sender-br-5 select2-custom sender-group-select" style="width:45%">')
                .append('<option value="0">Select group</option>');
            $.each($groups, function (gid, gname) {
                groupSelect.append(`<option value="${gid}">${gname}</option>`);
            });

            const removeBtn = $('<button type="button" class="sender-remove-role-group sender-cta-button sender-small sender-br-5" style="margin-left:8px;">Remove</button>');

            newRow.append(roleSelect, groupSelect, removeBtn);
            $repeater.append(newRow);

            if ($.fn.select2) {
                newRow.find('select.select2-custom').select2({ width: '100%', allowClear: true });
            }
        });

        $(document).on('click', '.sender-remove-role-group', function () {
            $(this).closest('.sender-role-group-row').remove();
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const toggleCheckbox = document.getElementById('sender_subscribe_label');
        const labelSection = document.getElementById('sender-newsletter-label-section');
        const precheckOption = document.getElementById('sender-precheck-option');

        function toggleDependentSections() {
            if (toggleCheckbox.checked) {
                labelSection.classList.remove('sender-disabled-field');
                precheckOption.classList.remove('sender-disabled-field');
            } else {
                labelSection.classList.add('sender-disabled-field');
                precheckOption.classList.add('sender-disabled-field');
            }
        }

        toggleDependentSections();

        toggleCheckbox.addEventListener('change', toggleDependentSections);
    });

    <?php if (get_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS)) : ?>
    var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";

    (function pollSenderSyncStatus() {
        const intervalId = setInterval(function () {
            jQuery.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'checkSyncStatus',
                },
                success: function (response) {
                    if (response.success) {
                        if (!response.data.is_running && response.data.is_finished) {
                            const $existingNotice = jQuery('#sender-sync-notice');

                            const successHTML = `
                                <div id="sender-sync-notice" class="notice notice-success is-dismissible">
                                    <p><strong>Sync completed successfully.</strong></p>
                                    <button type="button" class="notice-dismiss">
                                        <span class="screen-reader-text">Dismiss this notice.</span>
                                    </button>
                                </div>
                            `;

                            if ($existingNotice.length) {
                                $existingNotice
                                    .removeClass('notice-info')
                                    .addClass('notice-success')
                                    .html('<p><strong>Sync completed successfully.</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
                            } else {
                                jQuery('#sender-export-data').prepend(successHTML);
                            }

                            jQuery(document).on('click', '.notice.is-dismissible .notice-dismiss', function () {
                                jQuery(this).closest('.notice').fadeOut();
                            });

                            jQuery('#sender-submit-sync').prop('disabled', false);

                            if (!jQuery('#sender-download-log').length) {
                                const logButtonHTML = `
                                    <div id="sender-download-log" class="sender-option sender-mb-20">
                                        <a href="<?php echo esc_url($logDownloadUrl); ?>"
                                           class="sender-secondary-button sender-medium sender-br-5"
                                           download>
                                           <?php _e('Download Sync Log', 'sender-net-automated-emails'); ?>
                                        </a>
                                    </div>
                                `;
                                jQuery('#sender-export-data .sender-mb-20').last().append(logButtonHTML);
                            }

                            clearInterval(intervalId);
                        }
                    }
                }
            });
        }, 10000);
    })();
    <?php endif; ?>
</script>