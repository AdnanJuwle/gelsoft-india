/* ==========================================================================
   Gelsoft India — handing off to Razorpay.

   Everything this needs comes off the script tag's data attributes rather
   than an inline block, so the page keeps a strict script-src and no inline
   JavaScript is allowed anywhere on the site.

   Worth being clear about what this file can and cannot do: it collects a
   payment and then asks our server to confirm it. It does NOT decide that an
   order is paid — the webhook does that, server to server. This only decides
   which page the customer sees next.
   ========================================================================== */

(function () {
  'use strict';

  var tag = document.currentScript || document.querySelector('script[data-order]');
  if (!tag) return;

  var cfg = {
    key: tag.getAttribute('data-key'),
    order: tag.getAttribute('data-order'),
    amount: Number(tag.getAttribute('data-amount')),
    name: tag.getAttribute('data-name'),
    description: tag.getAttribute('data-description'),
    reference: tag.getAttribute('data-reference'),
    token: tag.getAttribute('data-token'),
    /* The shop lands on /order, a marketplace sale on /sale. */
    returnTo: tag.getAttribute('data-return') || '/order'
  };

  var button = document.getElementById('pay-now');

  var thankYou = function (extra) {
    window.location.href = cfg.returnTo + '?ref=' + encodeURIComponent(cfg.reference) +
      (cfg.token ? '&t=' + encodeURIComponent(cfg.token) : '') + (extra || '');
  };

  var say = function (text) {
    if (button) {
      button.disabled = false;
      button.textContent = text;
    }
  };

  function open() {
    if (typeof window.Razorpay !== 'function') {
      say('Payment window did not load — reload the page');
      return;
    }

    var rzp = new window.Razorpay({
      key: cfg.key,
      order_id: cfg.order,
      amount: cfg.amount,
      currency: 'INR',
      name: cfg.name,
      description: cfg.description,
      prefill: {
        name: tag.getAttribute('data-prefill-name') || '',
        email: tag.getAttribute('data-prefill-email') || '',
        contact: tag.getAttribute('data-prefill-contact') || ''
      },
      theme: { color: '#B14A24' },

      handler: function (response) {
        /* Tell our server what the widget handed back. The server checks the
           signature before it believes any of it, and even then only uses it
           to show the right page. */
        var body = new URLSearchParams({
          razorpay_order_id: response.razorpay_order_id || '',
          razorpay_payment_id: response.razorpay_payment_id || '',
          razorpay_signature: response.razorpay_signature || ''
        });

        fetch('/api/payment-return', {
          method: 'POST',
          body: body,
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
        })
          .then(function (r) { return r.json(); })
          .catch(function () { return { ok: false }; })
          .then(function () {
            /* Either way we go to the order page: it reads the real status
               from the database, so it is right whether or not the webhook
               has landed yet. */
            thankYou('');
          });
      },

      modal: {
        ondismiss: function () {
          say('Pay ' + '₹' + Math.round(cfg.amount / 100).toLocaleString('en-IN'));
          var note = document.getElementById('pay-note');
          if (note) {
            note.textContent = 'Payment window closed. The order is saved as ' +
              cfg.reference + ' and nothing has been charged — you can pay it from your orders.';
          }
        }
      }
    });

    rzp.on('payment.failed', function (response) {
      say('Payment failed — try again');
      var note = document.getElementById('pay-note');
      if (note && response && response.error) {
        note.textContent = response.error.description ||
          'The payment did not go through. Nothing has been charged.';
      }
    });

    rzp.open();
  }

  if (button) {
    button.addEventListener('click', function () {
      button.disabled = true;
      button.textContent = 'Opening…';
      open();
    });
  }

  /* Open it straight away — people expect the payment screen, not another
     button. The button stays as the way back if this is blocked. */
  if (document.readyState === 'complete') {
    open();
  } else {
    window.addEventListener('load', open);
  }
})();
