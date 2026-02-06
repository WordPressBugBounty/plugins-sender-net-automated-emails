(function (wp) {
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const el = wp.element.createElement;

    registerBlockType('sender-net-automated-emails/subscribe-newsletter-block', {
        title: __('Subscribe newsletter'),
        icon: 'sender-block-icon',
        category: 'widgets',
        parent: ["woocommerce/checkout-contact-information-block"],
        supports: { multiple: false },

        edit: function () {
            const initialChecked = window.senderNewsletter.checkboxActive === '1' || false;
            const label = window.senderNewsletter.senderCheckbox || 'Subscribe to our newsletter';

            return el(
                'div',
                { className: 'wc-block-components-checkbox wc-block-checkout__create-account' },
                el(
                    'label',
                    { htmlFor: 'sender-newsletter-checkbox-subscribe' },
                    el('input', {
                        type: 'checkbox',
                        id: 'sender-newsletter-checkbox-subscribe',
                        className: 'wc-block-components-checkbox__input',
                        checked: initialChecked,
                    }),
                    el('span', { className: 'wc-block-components-checkbox__label' }, label)
                )
            );
        },

        save: function () {
            const senderNewsletterCheckbox = window.senderNewsletter.senderCheckbox || 'Subscribe to our newsletter';
            const initialChecked = window.senderNewsletter.checkboxActive === '1' || false;

            return (
                el('div', { className: 'wc-block-components-checkbox wc-block-checkout__create-account' },
                    el('label', { htmlFor: 'sender-newsletter-checkbox-subscribe' },
                        el('input', {
                            type: 'checkbox',
                            id: 'sender-newsletter-checkbox-subscribe',
                            name: 'sender-newsletter-checkbox-subscribe',
                            className: 'wc-block-components-checkbox__input',
                            checked: initialChecked,
                        }),
                        el('svg', {
                                className: 'wc-block-components-checkbox__mark',
                                ariaHidden: 'true',
                                xmlns: 'http://www.w3.org/2000/svg',
                                viewBox: '0 0 24 20'
                            },
                            el('path', { d: 'M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z' })
                        ),
                        el('span', { className: 'wc-block-components-checkbox__label' }, senderNewsletterCheckbox)
                    )
                )
            );
        },
    });

})(window.wp);