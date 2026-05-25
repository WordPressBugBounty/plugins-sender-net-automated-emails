//Checkout
jQuery(document).ready(function () {
    function handleEmailFieldChange(event) {
        var emailField = jQuery(event.target);
        var emailValue = emailField.val();
        var newsletterChecked =
            jQuery('input[name="sender_newsletter"]:checked').length > 0;

        if (!emailValue || emailValue.indexOf('@') === -1) {
            return;
        }

        jQuery.ajax({
            type: 'POST',
            url: senderAjax.ajaxUrl,
            data: {
                action: 'trigger_backend_hook',
                email: emailValue,
                newsletter: newsletterChecked ? 1 : 0
            },
            success: function (response) {
                if (typeof sender === 'function') {
                    sender('trackVisitors', {email: emailValue});
                }
            },
            error: function (textStatus, errorThrown) {
                console.log("AJAX Error: " + textStatus + ", " + errorThrown);
            }
        });
    }

    jQuery(document.body).on('change blur', 'input#email, input#billing_email', handleEmailFieldChange);
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
        if (event.target && event.target.id === 'sender_newsletter') {
            handleNewsletterCheckboxChange(event.target.checked);
        }
    });

});
