//Checkout
jQuery(document).ready(function () {
    var requestInFlight = false;
    var capturePending = false;
    var newsletterPending = null;
    var waitingForSender = false;
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
        capturePending = true;
        processCheckoutRequests();
    }

    // Capture and explicit consent share one queue: an older capture must not
    // finish after an opt-out and restore its stale newsletter=true value.
    function processCheckoutRequests() {
        if (requestInFlight) {
            return;
        }
        if (!capturePending) {
            sendPendingNewsletter();
            return;
        }
        capturePending = false;

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
            sendPendingNewsletter();
            return;
        }

        requestInFlight = true;
        var captureSucceeded = false;
        jQuery.ajax({
            type: 'POST',
            url: senderAjax.ajaxUrl,
            data: data,
            success: function (response) {
                if (response.success) {
                    captureSucceeded = true;
                    lastCaptured = captured;
                    if (typeof sender === 'function') {
                        sender('trackVisitors', {email: emailValue});
                    }
                }
            },
            complete: function () {
                requestInFlight = false;
                // Do not update a subscriber whose creation failed. Keep the
                // choice for a later field/checkbox interaction to retry.
                if (captureSucceeded || capturePending) {
                    processCheckoutRequests();
                }
            }
        });
    }

    function sendPendingNewsletter() {
        var email = jQuery(emailSelector).filter(':visible').first().val();
        if (newsletterPending === null || !email || email.indexOf('@') === -1) {
            return;
        }
        if (typeof sender !== 'function') {
            return;
        }
        // The bootstrap command collector returns no promise. Keep the latest
        // choice locally until the SDK can acknowledge the actual request.
        if (!sender.loaded) {
            if (!waitingForSender) {
                waitingForSender = true;
                sender.listeners = sender.listeners || {};
                sender.listeners.ready = sender.listeners.ready || [];
                sender.listeners.ready.push(function () {
                    waitingForSender = false;
                    processCheckoutRequests();
                });
            }
            return;
        }
        var choice = newsletterPending;
        newsletterPending = null;
        requestInFlight = true;
        // Start inside a promise to release the queue even if the SDK throws.
        Promise.resolve().then(function () {
            return sender('subscribeNewsletter', {
                newsletter: choice, email: email, store_id: window.senderNewsletter.storeId
            });
        }).then(function () {
            requestInFlight = false;
            processCheckoutRequests();
        }, function () {
            requestInFlight = false;
            if (newsletterPending === null) {
                newsletterPending = choice;
            }
            // Retry only on a newer interaction, never in a tight error loop.
            if (capturePending) {
                processCheckoutRequests();
            }
        });
    }

    jQuery(document.body).on('change blur', emailSelector + ', ' + nameSelector, handleCheckoutFieldChange);

    var lastNewsletterChoice = null;
    // WooCommerce replaces the payment section (including these inputs) during
    // checkout refreshes. Keep the customer's choice across that replacement.
    jQuery(document.body).on('updated_checkout', function () {
        if (lastNewsletterChoice === null) {
            return;
        }
        document.querySelectorAll('input[type="checkbox"][name="sender_newsletter"]').forEach(function (checkbox) {
            checkbox.checked = lastNewsletterChoice;
            var form = checkbox.closest('form');
            var changed = form && form.querySelector('input[name="sender_newsletter_changed"]');
            if (changed) {
                changed.value = '1';
            }
        });
    });
    document.body.addEventListener('change', function (event) {
        if (event.target && (event.target.id === 'sender_newsletter' ||
            event.target.id === 'sender-newsletter-checkbox-subscribe')) {
            if (event.target.type !== 'checkbox') {
                return;
            }
            lastNewsletterChoice = event.target.checked;
            // Preserve intent on classic checkout/account submission. A default
            // hidden zero alone must not unsubscribe a Sender form subscriber.
            var form = event.target.closest('form');
            if (form) {
                var changed = form.querySelector('input[name="sender_newsletter_changed"]');
                if (changed) {
                    changed.value = '1';
                }
            }
            newsletterPending = event.target.checked;
            handleCheckoutFieldChange();
        }
    });

});

//TrackVisitor
document.addEventListener('DOMContentLoaded', function () {
    if (typeof senderTrackVisitorData !== 'undefined' && senderTrackVisitorData) {
        sender('trackVisitors', senderTrackVisitorData);
    }
});
