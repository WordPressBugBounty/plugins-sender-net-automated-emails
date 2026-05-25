(function (blocks, editor, components, i18n) {
    var el = wp.element.createElement;

    function getEditorWindows() {
        var windows = [window];
        var iframes = document.querySelectorAll('iframe');

        Array.prototype.forEach.call(iframes, function (iframe) {
            try {
                if (iframe.contentWindow) {
                    windows.push(iframe.contentWindow);
                }
            } catch (error) {
                // Cross-origin editor frames are not usable for block previews.
            }
        });

        return windows;
    }

    function renderForm(form) {
        getEditorWindows().forEach(function (editorWindow) {
            if (editorWindow.senderForms && typeof editorWindow.senderForms.render === 'function') {
                setTimeout(function () {
                    editorWindow.senderForms.render(form.id);
                });
            }
        });
    }

    function destroyForm(formHash) {
        getEditorWindows().forEach(function (editorWindow) {
            if (editorWindow.senderForms && typeof editorWindow.senderForms.destroy === 'function') {
                editorWindow.senderForms.destroy(formHash);
            }
        });
    }

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
                destroyForm(attributes.form);
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

                renderForm(form);
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
