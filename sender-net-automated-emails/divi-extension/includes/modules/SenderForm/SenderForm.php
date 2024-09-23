<?php

class Sender_Divi_Module extends ET_Builder_Module {

    public $slug = 'divi_sender_form';
    public $vb_support = 'on';

    protected $module_credits = array(
        'author'     => 'Sender',
        'author_uri' => 'https://sender.net',
    );

    public function init() {
        $this->name = esc_html__( 'Sender.net Form', 'sender-net-automated-emails' );
    }

    public function get_fields() {
        $forms   = $this->get_forms_data();
        $options = [
            0 => 'Select form'
        ];

        foreach ( $forms as $form ) {
            $options[ $form['id'] ] = $form['title'];
        }

        return array(
            'form' => array(
                'label'           => esc_html__( 'Form', 'sender-net-automated-emails' ),
                'type'            => 'select',
                'option_category' => 'basic_option',
                'options'         => $options,
                'description'     => esc_html__( 'Select the embedded form to display in the page', 'sender-net-automated-emails' ),
                'toggle_slug'     => 'main_content',
            ),
        );
    }

    private function get_forms_data() {
        $lastUpdateTimestamp = get_option( 'sender_forms_data_last_update', 0 );

        if ( current_time( 'timestamp' ) - (int) $lastUpdateTimestamp >= 60 ) {
            $senderApi = new Sender_API();
            $forms     = $senderApi->senderGetForms();
            if ( isset( $forms->data ) ) {
                $formsData = [];
                foreach ( $forms->data as $form ) {
                    $formsData[] = [
                        'id'            => $form->id,
                        'embed_hash'    => $form->settings->embed_hash,
                        'title'         => $form->title,
                        'thumbnail_url' => $form->thumbnail_url,
                    ];
                }

                update_option( 'sender_forms_data', $formsData );
                update_option( 'sender_forms_data_last_update', (int) current_time( 'timestamp' ) );
            }
        }

        return get_option( 'sender_forms_data', array() );
    }

    public function render( $attrs, $content, $render_slug ) {
        return sprintf( '<div class="sender-form-field" data-sender-form-id="%1$s"></div>', $this->props['form'] ?? "" );
    }
}

new Sender_Divi_Module;
