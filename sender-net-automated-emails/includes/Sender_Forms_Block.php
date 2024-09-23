<?php

if (!defined('ABSPATH')) {
    exit;
}

class Sender_Forms_Block
{
    public function __construct()
    {
        add_action('enqueue_block_editor_assets', array($this, 'register_block'));
    }

    public function register_block()
    {
        wp_enqueue_script(
            'sender-forms-block',
            plugins_url('js/sender-forms-block.js', __FILE__),
            array('wp-blocks', 'wp-components', 'wp-i18n'),
            filemtime(plugin_dir_path(__FILE__) . 'js/sender-forms-block.js')
        );

        wp_localize_script('sender-forms-block', 'senderFormsBlockData', array(
            'formsData' => $this->get_forms_data())
        );
    }

    private function get_forms_data()
    {
        $lastUpdateTimestamp = get_option('sender_forms_data_last_update', 0);

        if (current_time('timestamp') - (int)$lastUpdateTimestamp >= 60) {
            $senderApi = new Sender_API();
            $forms = $senderApi->senderGetForms();
            if (isset($forms->data)) {
                $formsData = [];
                foreach ($forms->data as $form) {
                    $formsData[] = [
                        'id' => $form->id,
                        'embed_hash' => $form->settings->embed_hash,
                        'title' => $form->title,
                        'thumbnail_url' => $form->thumbnail_url,
                    ];
                }

                update_option('sender_forms_data', $formsData);
                update_option('sender_forms_data_last_update', (int)current_time('timestamp'));
            }
        }

        return get_option('sender_forms_data', array());
    }
}

$sender_forms_block = new Sender_Forms_Block();
