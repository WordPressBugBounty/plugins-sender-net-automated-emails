(function (blocks, editor, components, i18n) {
    var el = wp.element.createElement;

    blocks.registerBlockType('sender/sender-forms', {
        title: i18n.__('Sender.net Form'),
        category: 'widgets',
        icon: 'sender-block-icon',
        attributes: {
            form: {
                type: 'string',
                default: ''
            }
        },
        edit: function (props) {
            const {attributes, setAttributes} = props;
            const formsData = window.senderFormsBlockData.formsData || [];

            const onChange = function (newValue) {
                senderForms.destroy(attributes.form);
                setAttributes({form: newValue});
                appendScript(newValue);
            };

            const appendScript = function (hash) {
                if (!hash) {
                    return;
                }
                const form = formsData.find(form => form.embed_hash === hash);

                if (!form) {
                    console.warn("Form not found");
                    return;
                }

                var _window = window;

                if (document.body.querySelector('.edit-site-visual-editor__editor-canvas')) {
                    _window = document.body.querySelector('.edit-site-visual-editor__editor-canvas').contentWindow.document.defaultView;
                }

                setTimeout(() => _window.senderForms.render(form.id));
            };

            return (
                el('div', {},
                    el(components.SelectControl, {
                        label: i18n.__('Select Form'),
                        options: [
                            {label: i18n.__('Select your form'), value: ''},
                            ...formsData.map(form => ({label: form.title, value: form.embed_hash}))
                        ],
                        value: attributes.form,
                        onChange: onChange
                    }),
                    el('div', {
                        id: 'sender-forms-script-placeholder',
                        key: attributes.form,
                        className: 'sender-form-field',
                        'data-sender-form-id': attributes.form ?? null,
                    })
                )
            );
        },

        save: function ({attributes}) {
            return el('div', {
                className: 'sender-form-field',
                'data-sender-form-id': attributes.form
            });
        }
    });
})(
    window.wp.blocks,
    window.wp.editor,
    window.wp.components,
    window.wp.i18n
);

