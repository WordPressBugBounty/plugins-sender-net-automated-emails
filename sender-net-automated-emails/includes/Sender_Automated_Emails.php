<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once 'Sender_Helper.php';

class Sender_Automated_Emails
{
    private $availableSettings = [
        'sender_api_key' => false,
        'sender_resource_key' => false,
        'sender_allow_tracking' => false,
        'sender_account_message' => false,
        'sender_customers_list' => 0,
        'sender_registration_list' => 0,
        'sender_store_register' => false,
        'sender_account_disconnected' => false,
        'sender_wocommerce_sync' => 0,
        'sender_account_title' => false,
        'sender_account_plan_type' => false,
        'sender_groups_data' => false,
        'sender_forms_data' => false,
        'sender_synced_data_date' => false,
        'sender_subscribe_label' => false,
        'sender_subscribe_to_newsletter_string' => 'Subscribe to our newsletter',
        'sender_forms_data_last_update' => 0,
    ];

    public $senderBaseFile;
    public $senderApi;

    public function __construct($senderBaseFile)
    {
        $this->senderBaseFile = $senderBaseFile;

        if (!class_exists('Sender_API')) {
            require_once("Sender_API.php");
        }

        $this->senderApi = new Sender_API();

        $this->senderSetupOptions()
            ->senderAddFilters();

        if (!class_exists('Sender_Repository')) {
            require_once("Sender_Repository.php");
        }

        register_activation_hook($senderBaseFile, [new Sender_Repository(), 'senderCreateTables']);

        $this->senderEnqueueStyles();
        $this->senderCreateSettingsTemplates();

        if (!$this->senderApiKey() || get_option('sender_account_disconnected')) {
            return;
        }

        if ($this->senderIsWooEnabled()) {
            if (!class_exists('Sender_User')) {
                require_once 'Model/Sender_User.php';
            }
            if (!class_exists('Sender_Cart')) {
                require_once 'Model/Sender_Cart.php';
            }
        }

        $this->senderAddActions()
            ->senderSetupWooCommerce();

        $this->senderAddWebhooks();
    }

    function initialize_divi_extension() {
        require_once(__DIR__ . '/../divi-extension/includes/SenderDiviExtension.php');
    }

    private function senderAddActions()
    {
        add_action( 'divi_extensions_init', [$this, 'initialize_divi_extension'] );

        add_action('wp_enqueue_scripts', function () {
            if (!isset($_GET['et_fb'])) {
                $this->insertSdkScript(false);
            }else{
                //For divi builder
                $this->insertSdkScript();
            }
        });

        add_action('admin_enqueue_scripts', [$this, 'insertSdkScript']);

        add_action('widgets_init', [&$this, 'senderRegisterFormsWidget']);

        if (get_option('sender_allow_tracking') && $this->senderIsWooEnabled() && !is_admin()) {
            // Enqueue scripts
            add_action('wp_enqueue_scripts', [$this, 'enqueueSenderWordpressJs']);

            // User registration/login actions
            add_action('user_register', [$this, 'subscriberVisitorCreationRegister'], 10, 1);
            add_action('wp_login', [$this, 'subscriberVisitorCreationLogin'], 10, 2);

            // Track visitor script
            add_action('wp_head', [$this, 'outputSenderTrackVisitorsScript']);
        }

        add_action('activated_plugin', [&$this, 'checkWooCommerceActivation'],10, 2);
        add_action('deactivated_plugin', [&$this, 'checkWooCommerceDeactivation']);

        return $this;
    }

    public function checkWooCommerceActivation($plugin, $network_activation)
    {
        if ($plugin === 'woocommerce/woocommerce.php' || $network_activation === 'woocommerce/woocommerce.php') {
            $this->senderHandleAddStore();
        }
    }

    public function checkWooCommerceDeactivation($plugin)
    {
        //When plugin not active, remove store
        if (false !== strpos($plugin, 'woocommerce/woocommerce.php')) {
            $this->senderApi->senderDeleteStore();
            update_option('sender_store_register', false);
        }
    }

    private function senderAddFilters()
    {
        add_filter('plugin_action_links_' . plugin_basename($this->senderBaseFile), [&$this, 'senderAddPluginLinks']);
        return $this;
    }

    public function updateSettings($updates)
    {
        foreach ($this->availableSettings as $name => $defaultValue) {
            if (isset($updates[$name])) {
                //Handle login before all
                if ($name === 'sender_api_key') {
                    update_option('sender_api_key', sanitize_text_field($updates[$name]));
                    $user = $this->senderApi->senderGetAccount();
                    if (!$user) {
                        add_action('admin_notices', [&$this, 'error_account_connected']);
                        do_action('admin_notices', 'Incorrect API key.');
                        return false;
                    }

                    if (isset($user->xRate)){
                        add_action('admin_notices', [&$this, 'error_account_connected']);
                        do_action('admin_notices', 'Too many requests. Try again after 1 minute.');
                        return false;
                    }

                    update_option('sender_account_disconnected', false);
                    unset($updates[$name]);
                    continue;
                }

                if ($name === 'sender_subscribe_to_newsletter_string'){
                    $sanitizedInput = sanitize_text_field(wp_unslash($updates[$name]));
                    if (strlen($sanitizedInput) > 255){
                        $sanitizedInput = substr($sanitizedInput, 0, 254);
                    }

                    update_option('sender_subscribe_to_newsletter_string', $sanitizedInput);
                    unset($updates[$name]);
                    continue;
                }

                update_option($name, $updates[$name]);
                if ($name === 'sender_account_disconnected' && !empty($updates[$name])) {
                    if (isset($_POST['delete-subscribers'])){
                        $this->senderApi->senderDeleteStore(true);
                    }else{
                        $this->senderApi->senderDeleteStore();
                    }

                    update_option('sender_store_register', false);
                }

                if ($name === 'sender_wocommerce_sync'){
                    if (!class_exists('Sender_WooCommerce')) {
                        require_once("Sender_WooCommerce.php");
                    }

                    new Sender_WooCommerce($this, true);
                }
            }
        }
    }

    private function senderSetupOptions()
    {
        foreach ($this->availableSettings as $name => $defaultValue) {
            if (!get_option($name)) {
                add_option($name, $defaultValue);
            }
        }
        return $this;
    }

    public function checkApiKey()
    {
        if (!$this->senderApiKey()) {
            update_option('sender_account_message', false);
            update_option('sender_resource_key', false);
            return false;
        }

        $user = $this->senderApi->senderGetAccount();

        if (isset($user->xRate)) {
            return true;
        }

        if (isset($user->account)) {
            update_option('sender_account_title', $user->account->title);
            update_option('sender_account_plan_type', $user->account->active_plan->type);
            update_option('sender_resource_key', $user->account->resource_key);
            update_option('sender_account_message', false);
        }

        return true;
    }

    public function error_account_connected($message)
    {
        echo '<div class="notice notice-error is-dismissible sender-notice-error">
      <p>' . $message . '</p>
      </div>';
    }

    public function senderIsWooEnabled()
    {
        return Sender_Helper::senderIsWooEnabled();
    }

    public function senderSetupWooCommerce()
    {
        if (!$this->senderIsWooEnabled()) {
            return $this;
        }

        if (get_option('sender_allow_tracking')) {

            if (!class_exists('Sender_Carts')) {
                require_once("Sender_Carts.php");
            }

            new Sender_Carts($this);
        }

        if (!class_exists('Sender_WooCommerce')) {
            require_once("Sender_WooCommerce.php");
        }

        new Sender_WooCommerce($this);

        return $this;
    }

    public function insertSdkScript($isAdmin = true)
    {
        $key = $this->senderApi->senderGetResourceKey();
        $script_url = $isAdmin
            ? 'https://cdn.sender.net/accounts_resources/universal.js?explicit=true'
            : 'https://cdn.sender.net/accounts_resources/universal.js';

        ?>
        <script>
            (function (s, e, n, d, er) {
                s['Sender'] = er;
                s[er] = s[er] || function () {
                    (s[er].q = s[er].q || []).push(arguments)
                }, s[er].l = 1 * new Date();
                var a = e.createElement(n),
                    m = e.getElementsByTagName(n)[0];
                a.async = 1;
                a.src = d;
                m.parentNode.insertBefore(a, m)
            })(window, document, 'script', '<?php echo esc_url($script_url); ?>', 'sender');
            sender('<?php echo esc_js($key); ?>');
        </script>
        <?php

        if (get_option('sender_allow_tracking') && $this->senderIsWooEnabled() && !is_admin()) {
            ?>
            <script>
                sender('trackVisitors');
            </script>
            <script id="sender-track-cart"></script>
            <script id="sender-update-cart"></script>
            <?php
        }

        $this->addSenderPluginVersion();
    }

    public function enqueueSenderWordpressJs()
    {
        wp_enqueue_script(Sender_Helper::SENDER_JS_FILE_NAME, plugins_url('js/sender-wordpress-plugin.js', __FILE__), array(), '1.0', true);
        wp_localize_script(Sender_Helper::SENDER_JS_FILE_NAME, 'senderAjax', array('ajaxUrl' => admin_url('admin-ajax.php')));
    }

    public function subscriberVisitorCreationRegister($userId)
    {
        $this->senderApi->senderTrackRegisterUserCallback($userId);
        add_action('sender_track_user_action', [Sender_Carts::class, 'trackUser']);

        set_transient(Sender_Helper::TRANSIENT_LOG_IN, 1, 5);
    }

    public function subscriberVisitorCreationLogin($uname, $user)
    {
        $this->senderApi->senderTrackRegisterUserCallback($user->ID);
        add_action('sender_track_user_action', [Sender_Carts::class, 'trackUser']);
        set_transient(Sender_Helper::TRANSIENT_LOG_IN, 1, 5);
    }

    public function outputSenderTrackVisitorsScript()
    {
        if (get_transient(Sender_Helper::TRANSIENT_LOG_IN)){
            if (is_user_logged_in()){
                $current_user = wp_get_current_user();
                $user_email = strtolower($current_user->user_email);
                wp_localize_script(Sender_Helper::SENDER_JS_FILE_NAME, 'senderTrackVisitorData', array('email' => $user_email));
                delete_transient(Sender_Helper::TRANSIENT_LOG_IN);
            }
        }
    }

    private function addSenderPluginVersion() {
        $version = $this->getVersionPlugin();
        if ($version) {
            $pluginVersion = 'Sender.net ' . esc_attr($version);
            ?>
            <meta name="generator" content="<?php echo $pluginVersion; ?>"/>
            <?php
        }
    }

    private function getVersionPlugin()
    {
        $pluginData = get_plugin_data($this->senderBaseFile);
        if (!empty($pluginData) && isset($pluginData['Version'])) {
            return $pluginData['Version'];
        }
        return false;
    }

    public function senderRegisterFormsWidget()
    {
        if (!class_exists('Sender_Forms_Widget')) {
            require_once("Sender_Forms_Widget.php");
        }

        register_widget('Sender_Forms_Widget');


        if (!class_exists('Sender_Forms_Block')) {
            require_once("Sender_Forms_Block.php");
        }
    }

    public function senderAddPluginLinks($links)
    {

        $additionalLinks = [
            '<a href="' . admin_url('admin.php?page=sender-settings') . '">Settings</a>',
        ];

        return array_merge($links, $additionalLinks);
    }

    private function senderApiKey()
    {
        return get_option('sender_api_key');
    }

    private function senderCreateSettingsTemplates()
    {
        if (!class_exists('Sender_Templates_Loader')) {
            require_once(dirname($this->senderBaseFile) . "/templates/Sender_Templates_Loader.php");
        }

        new Sender_Templates_Loader($this);
    }

    private function senderEnqueueStyles()
    {
        add_action('admin_init', [&$this, 'senderInitStyles']);
    }

    public function senderInitStyles()
    {
        $version = $this->getVersionPlugin();
        wp_enqueue_style('sender-styles', plugin_dir_url($this->senderBaseFile) . 'styles/settings.css', [], $version);
    }

    public function senderHandleAddStore()
    {
        if ($this->senderIsWooEnabled()) {
            $store = $this->senderApi->senderAddStore();

            if (isset($store->data, $store->data->id)) {
                update_option('sender_store_register', $store->data->id);
                update_option('sender_wocommerce_sync', false);
                if (!class_exists('Sender_WooCommerce')) {
                    require_once("Sender_WooCommerce.php");
                }

                new Sender_WooCommerce($this, true);
            }
        }
    }

    public function senderAddWebhooks()
    {
        if (!class_exists("Sender_Webhooks")) {
            require_once("Sender_Webhooks.php");
        }

        new Sender_Webhooks($this);
    }
}
