//Checkout
jQuery(document).ready(function () {
    var emailField = jQuery('input#email, input#billing_email');

    function handleEmailFieldChange() {
        var emailValue = emailField.val();
        jQuery.ajax({
            type: 'POST',
            url: senderAjax.ajaxUrl,
            data: {
                action: 'trigger_backend_hook',
                email: emailValue
            },
            success: function (response) {
                console.log(response);
                sender('trackVisitors', {email: emailValue});
            },
            error: function (textStatus, errorThrown) {
                console.log("AJAX Error: " + textStatus + ", " + errorThrown);
            }
        });
    }

    emailField.on('change', handleEmailFieldChange);
});

//TrackVisitor
function handleTrackVisitorData(senderData) {
    if (senderData) {
        sender('trackVisitors', (senderData));
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof senderTrackVisitorData !== 'undefined') {
        handleTrackVisitorData(senderTrackVisitorData);
    }
});
