//Checkout
jQuery(document).ready(function () {
    var requestInFlight = false;
    var capturePending = false;
    var lastCaptured = '';
    var emailSelector = 'input#email, input#billing_email';
    var nameSelector = 'input#billing_first_name, input#billing_last_name, ' +
        'input#billing-first_name, input#billing-last_name, ' +
        'input#shipping-first_name, input#shipping-last_name';

    function checkoutName(classicId, billingId, shippingId) {
        var field = jQuery(classicId + ', ' + billingId).filter(':visible').first();
        // Blocks uses the shipping address for billing when no separate billing form is shown.
        if (!field.length) {
            field = jQuery(shippingId).filter(':visible').first();
        }
        return field.val() || '';
    }

    function handleCheckoutFieldChange() {
        if (requestInFlight) {
            capturePending = true;
            return;
        }

        var emailValue = jQuery(emailSelector).filter(':visible').first().val();
        if (!emailValue || emailValue.indexOf('@') === -1) {
            return;
        }

        var data = {
            action: 'trigger_backend_hook',
            email: emailValue,
            firstname: checkoutName('input#billing_first_name', 'input#billing-first_name', 'input#shipping-first_name'),
            lastname: checkoutName('input#billing_last_name', 'input#billing-last_name', 'input#shipping-last_name'),
            newsletter: jQuery('input[name="sender_newsletter"]:checked, input#sender-newsletter-checkbox-subscribe:checked').length > 0 ? 1 : 0
        };
        var captured = JSON.stringify(data);
        if (captured === lastCaptured) {
            return;
        }

        requestInFlight = true;
        jQuery.ajax({
            type: 'POST',
            url: senderAjax.ajaxUrl,
            data: data,
            success: function (response) {
                if (response.success) {
                    lastCaptured = captured;
                    if (typeof sender === 'function') {
                        sender('trackVisitors', {email: emailValue});
                    }
                }
            },
            complete: function () {
                requestInFlight = false;
                if (capturePending) {
                    capturePending = false;
                    handleCheckoutFieldChange();
                }
            }
        });
    }

    jQuery(document.body).on('change blur', emailSelector + ', ' + nameSelector, handleCheckoutFieldChange);
});

//TrackVisitor
function handleTrackVisitorData(senderData) {
    if (senderData) {
        sender('trackVisitors', (senderData));
    }
}

//Checkbox
function handleNewsletterCheckboxChange(checked) {
    var emailField = jQuery('input#email, input#billing_email');
    var email = emailField.val();

    if (!email) {
        return;
    }
    handleCheckboxChange(checked, email, window.senderNewsletter.storeId);
}

function handleCheckboxChange(isChecked, email, storeId) {
    const senderData = {newsletter: isChecked, email: email, store_id: storeId};

    sender('subscribeNewsletter', senderData);
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof senderTrackVisitorData !== 'undefined') {
        handleTrackVisitorData(senderTrackVisitorData);
    }
});

// checkbox newsletter
document.addEventListener('DOMContentLoaded', function () {
    document.body.addEventListener('change', function (event) {
        if (event.target && (event.target.id === 'sender_newsletter' ||
            event.target.id === 'sender-newsletter-checkbox-subscribe')) {
            handleNewsletterCheckboxChange(event.target.checked);
        }
    });

});
