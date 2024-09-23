const {__} = wp.i18n;
const {registerBlockType} = wp.blocks;
const el = wp.element.createElement;

var sender = window.sender || {};

registerBlockType('sender-net-automated-emails/subscribe-newsletter-block', {
    title: __('Subscribe newsletter'),
    icon: 'sender-block-icon',
    category: 'widgets',
    parent: ["woocommerce/checkout-contact-information-block"],
    supports: {
        multiple: false,
    },

    edit: function (props) {
        const senderNewsletterCheckbox = window.senderNewsletter.senderCheckbox || 'Subscribe to our newsletter';
        return (
            el('div', { className: 'wc-block-components-checkbox wc-block-checkout__create-account' },
                el('label', { htmlFor: 'sender-newsletter-checkbox-subscribe' },
                    el('input', {
                        type: 'checkbox',
                        id: 'sender-newsletter-checkbox-subscribe',
                        name: 'sender-newsletter-checkbox-subscribe',
                        className: 'wc-block-components-checkbox__input'
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

    save: function () {
        const senderNewsletterCheckbox = window.senderNewsletter.senderCheckbox || 'Subscribe to our newsletter';
        return (
            el('div', { className: 'wc-block-components-checkbox wc-block-checkout__create-account' },
                el('label', { htmlFor: 'sender-newsletter-checkbox-subscribe' },
                    el('input', {
                        type: 'checkbox',
                        id: 'sender-newsletter-checkbox-subscribe',
                        name: 'sender-newsletter-checkbox-subscribe',
                        className: 'wc-block-components-checkbox__input'
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

// Frontend jQuery functionality
document.addEventListener('DOMContentLoaded', function () {
    document.body.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'email') {
            handleEmailChange(event.target);
        }

        if (event.target && event.target.id === 'sender-newsletter-checkbox-subscribe') {
            handleNewsletterCheckboxChange(event.target.checked);
        }
    });

    function handleEmailChange() {
        var emailField = jQuery('input#email');
        var email = emailField.val();
        if (email === '') {
        } else {
            handleEmailFieldChange(email);

            var checkbox = document.getElementById('sender-newsletter-checkbox-subscribe');
            if (checkbox) {
                var checkboxChecked = checkbox.checked;
                if (checkboxChecked) {
                    handleNewsletterCheckboxChange(true);
                }
            }
        }
    }

    function handleNewsletterCheckboxChange(checked) {
        var emailField = jQuery('input#email');
        var email = emailField.val();

        if (email === '') {
        } else {
            setTimeout(function (){
                handleCheckboxChange(checked, email, window.senderNewsletter.storeId);
            }, 3000)
        }
    }

    function handleCheckboxChange(isChecked, email, storeId) {
        const senderData = {newsletter: isChecked, email: email, store_id: storeId};

        console.log(senderData);
        sender('subscribeNewsletter', senderData);
    }

    function handleEmailFieldChange(emailValue) {
        jQuery.ajax({
            type: 'POST',
            url: senderAjax.ajaxUrl,
            data: {
                action: 'trigger_backend_hook',
                email: emailValue
            },
            success: function (response) {
                console.log(response);
            },
            error: function (textStatus, errorThrown) {
                console.log("AJAX Error: " + textStatus + ", " + errorThrown);
            }
        });
    }
});


