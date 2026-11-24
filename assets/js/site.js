/* ==========================================================================
   Gelsoft India — site behaviour
   Vanilla, no dependencies. Every block is optional: nothing throws on a page
   that does not use it.
   ========================================================================== */

(function () {
  'use strict';

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ------------------------------------------------------- mobile nav ---- */

  function initNav() {
    var toggle = $('[data-nav-toggle]');
    var nav = $('[data-nav]');
    if (!toggle || !nav) return;

    var inner = $('.nav__inner', nav);
    var EASE = 'cubic-bezier(0.22, 0.85, 0.3, 1)';
    var MS = 300;
    var running = null;

    var reduced = window.matchMedia
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* The panel goes from height 0 to height auto, which no CSS transition
       can interpolate. The Web Animations API can, because we measure the
       open height first and animate between two real numbers. */
    var set = function (open) {
      if (nav.classList.contains('is-open') === open) return;

      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

      if (!inner || !nav.animate || reduced) {
        nav.classList.toggle('is-open', open);
        return;
      }

      if (running) running.cancel();

      var from = nav.getBoundingClientRect().height;
      nav.classList.toggle('is-open', open);
      var to = open ? inner.getBoundingClientRect().height : 0;

      nav.classList.add('is-animating');
      running = nav.animate(
        [
          { height: from + 'px', opacity: open ? 0 : 1 },
          { height: to + 'px', opacity: open ? 1 : 0 }
        ],
        { duration: MS, easing: EASE }
      );

      running.onfinish = running.oncancel = function () {
        nav.classList.remove('is-animating');
        running = null;
      };
    };

    toggle.addEventListener('click', function () {
      set(!nav.classList.contains('is-open'));
    });

    /* Escape closes it, and so does following a link — otherwise the drawer is
       still sitting open behind the next page on a browser back. */
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && nav.classList.contains('is-open')) {
        set(false);
        toggle.focus();
      }
    });

    nav.addEventListener('click', function (ev) {
      if (ev.target.closest('a')) set(false);
    });

    /* Resizing past the breakpoint leaves the class on an element that is no
       longer a drawer, so the desktop nav would inherit the drawer layout. */
    window.addEventListener('resize', function () {
      if (window.innerWidth > 860 && nav.classList.contains('is-open')) {
        // No animation across the breakpoint: above it the nav is a row, not
        // a drawer, and sliding it would look like a glitch.
        if (running) running.cancel();
        nav.classList.remove('is-open', 'is-animating');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* -------------------------------------------------------- accordion ---- */

  function initAccordions() {
    $$('[data-accordion]').forEach(function (acc) {
      var single = acc.getAttribute('data-accordion') === 'single';

      $$('.acc__q', acc).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var panel = document.getElementById(btn.getAttribute('aria-controls'));
          var open = btn.getAttribute('aria-expanded') === 'true';

          if (single) {
            $$('.acc__q', acc).forEach(function (other) {
              if (other === btn) return;
              other.setAttribute('aria-expanded', 'false');
              var p = document.getElementById(other.getAttribute('aria-controls'));
              if (p) p.hidden = true;
            });
          }

          btn.setAttribute('aria-expanded', open ? 'false' : 'true');
          if (panel) panel.hidden = open;
        });
      });
    });
  }

  /* ------------------------------------------------------------- tabs ---- */

  /* role="tablist" is a promise: a screen reader announces "tab, 3 of 6" and
     the person then reaches for the arrow keys, because that is what the role
     means. The markup made the promise and the script did not keep it --
     arrows did nothing, and all six tabs sat in the tab order where the
     pattern asks for one.

     Both halves are done here rather than in the templates, so the account
     tabs, the admin sections and anything added later get it from one place
     and cannot drift apart. */
  function initTabs() {
    $$('[data-tabs]').forEach(function (group) {
      var buttons = $$('[data-tab]', group);
      if (!buttons.length) return;

      var groupName = group.getAttribute('data-tabs') || 'tabs';

      function panelsFor(name) {
        return $$('[data-panel]', document).filter(function (p) {
          if (!group.contains(p) && p.getAttribute('data-panel-group') !== groupName) return false;
          return name === undefined || p.getAttribute('data-panel') === name;
        });
      }

      // Tie each tab to its panel both ways. The ids are generated because
      // the markup has none, and a hand-written id per panel in six templates
      // is six chances to typo one.
      buttons.forEach(function (b) {
        var name = b.getAttribute('data-tab');
        if (!b.id) b.id = 'tab-' + groupName + '-' + name;
        panelsFor(name).forEach(function (p) {
          if (!p.id) p.id = 'panel-' + groupName + '-' + name;
          b.setAttribute('aria-controls', p.id);
          p.setAttribute('aria-labelledby', b.id);
        });
      });

      function show(name) {
        buttons.forEach(function (b) {
          var on = b.getAttribute('data-tab') === name;
          b.setAttribute('aria-selected', on ? 'true' : 'false');
          // Roving tabindex: Tab enters and leaves the strip, arrows move
          // within it. Without this, eight admin sections cost eight presses
          // of Tab to step past.
          b.tabIndex = on ? 0 : -1;
          if (b.hasAttribute('aria-current')) {
            b.setAttribute('aria-current', on ? 'true' : 'false');
          }
        });
        panelsFor().forEach(function (p) {
          p.hidden = p.getAttribute('data-panel') !== name;
        });
      }

      buttons.forEach(function (b) {
        b.addEventListener('click', function () { show(b.getAttribute('data-tab')); });
      });

      // Arrow keys wrap around, which is what the pattern specifies and what
      // makes the last tab reachable from the first on a strip that scrolls.
      // Focus moves with the selection so the panel below always belongs to
      // whatever is focused.
      var strip = buttons[0].parentElement;
      if (strip) {
        strip.addEventListener('keydown', function (ev) {
          var i = buttons.indexOf(document.activeElement);
          if (i < 0) return;
          var to = -1;
          if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') to = (i + 1) % buttons.length;
          else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') to = (i - 1 + buttons.length) % buttons.length;
          else if (ev.key === 'Home') to = 0;
          else if (ev.key === 'End') to = buttons.length - 1;
          if (to < 0) return;
          ev.preventDefault();
          show(buttons[to].getAttribute('data-tab'));
          buttons[to].focus();
        });
      }

      var first = buttons.filter(function (b) { return b.getAttribute('aria-selected') === 'true'; })[0] || buttons[0];
      if (first) {
        show(first.getAttribute('data-tab'));

        // On a phone the strip scrolls sideways and six account tabs are twice
        // its width, so a selected tab can start off-screen -- the panel below
        // would then belong to a tab nobody can see. Nudge it into view, but
        // only when the strip really does overflow, so nothing jumps on a
        // desktop where every tab already fits.
        if (strip && strip.scrollWidth > strip.clientWidth + 4) {
          strip.scrollLeft = Math.max(0, first.offsetLeft - 16);
        }
      }
    });
  }

  /* ---------------------------------------------------------- filters ---- */
  /* A filter rail drives a grid of [data-item] cards. Each filter input
     carries data-key (which attribute to match) and a value; "any" matches
     everything. Counts and the empty state update as you go.               */

  function initFilters() {
    $$('[data-filter-scope]').forEach(function (scope) {
      var inputs = $$('[data-key]', scope);
      var items = $$('[data-item]', scope);
      var countEl = $('[data-count]', scope);
      var empty = $('[data-empty]', scope);
      var grid = $('[data-grid]', scope);

      if (!inputs.length) return;

      function matches(item, state) {
        return Object.keys(state).every(function (key) {
          var want = state[key];
          if (!want || want === 'any') return true;
          return (item.getAttribute('data-' + key) || '') === want;
        });
      }

      function apply() {
        var state = {};
        inputs.forEach(function (input) {
          if (input.type === 'radio' && !input.checked) return;
          state[input.getAttribute('data-key')] = input.value;
        });

        var shown = 0;
        items.forEach(function (item) {
          var ok = matches(item, state);
          item.hidden = !ok;
          if (ok) shown++;
        });

        if (countEl) {
          countEl.textContent = items.length === 0
            ? (countEl.getAttribute('data-empty-text') || 'Nothing in stock yet')
            : shown + ' of ' + items.length + ' ' + (countEl.getAttribute('data-noun') || 'items');
        }
        if (empty) empty.hidden = shown !== 0;
        if (grid) grid.hidden = shown === 0;
      }

      inputs.forEach(function (input) { input.addEventListener('change', apply); });

      var clear = $('[data-filter-clear]', scope);
      if (clear) {
        clear.addEventListener('click', function () {
          inputs.forEach(function (input) {
            if (input.type === 'radio') input.checked = input.value === 'any';
            if (input.type === 'checkbox') input.checked = false;
          });
          apply();
        });
      }

      apply();
    });
  }

  /* ---------------------------------------------------------- stepper ---- */

  function initSteppers() {
    $$('[data-stepper]').forEach(function (stepper) {
      var out = $('output', stepper);
      var unit = parseInt(stepper.getAttribute('data-price') || '0', 10);
      var total = document.getElementById(stepper.getAttribute('data-total') || '');
      /* Never more than the shelf holds. The server caps it too, but asking
         for six of something there are two of should not look allowed. */
      var max = Math.min(9, parseInt(stepper.getAttribute('data-max') || '9', 10) || 9);
      /* The hidden input is what actually gets posted; the output is display. */
      var field = $('input[type="hidden"]', stepper);
      var qty = parseInt(out ? out.textContent : '1', 10) || 1;

      function render() {
        if (out) out.textContent = String(qty);
        if (field) field.value = String(qty);
        if (total && unit) {
          total.textContent = '₹' + (unit * qty).toLocaleString('en-IN');
        }
      }

      $$('[data-step]', stepper).forEach(function (btn) {
        btn.addEventListener('click', function () {
          qty = Math.min(max, Math.max(1, qty + parseInt(btn.getAttribute('data-step'), 10)));
          render();
        });
      });

      render();
    });
  }

  /* -------------------------------------------- pressed-button groups ---- */
  /* Swatches and gallery thumbs: one pressed at a time, optional side effect
     of swapping a colour variable or showing a view.                       */

  function initPressGroups() {
    $$('[data-press-group]').forEach(function (group) {
      var buttons = $$('[aria-pressed]', group);

      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });

          var label = document.getElementById(group.getAttribute('data-label') || '');
          if (label && btn.getAttribute('data-label')) label.textContent = btn.getAttribute('data-label');

          var target = document.getElementById(group.getAttribute('data-target') || '');
          if (target && btn.getAttribute('data-body')) {
            target.style.setProperty('--body', btn.getAttribute('data-body'));
            target.style.setProperty('--body-dark', btn.getAttribute('data-body-dark'));
          }
          if (target && btn.getAttribute('data-view')) {
            $$('[data-view]', target).forEach(function (view) {
              view.hidden = view.getAttribute('data-view') !== btn.getAttribute('data-view');
            });
          }
        });
      });
    });
  }

  /* ----------------------------------------------- conditional blocks ---- */
  /* A select with data-controls shows the [data-when] blocks matching its
     value. Used by the admin item form (blaster specs).                    */

  function initConditionals() {
    $$('[data-controls]').forEach(function (select) {
      var scope = document.getElementById(select.getAttribute('data-controls'));
      if (!scope) return;

      function apply() {
        $$('[data-when]', scope).forEach(function (block) {
          block.hidden = block.getAttribute('data-when') !== select.value;
        });
      }

      select.addEventListener('change', apply);
      apply();
    });
  }

  /* --------------------------------------------------- live previewers --- */
  /* Admin item form: grade / gears / status selects update the preview card.
     Each select names the preview element and what to write.               */

  function initPreviews() {
    $$('[data-preview]').forEach(function (select) {
      var target = document.getElementById(select.getAttribute('data-preview'));
      if (!target) return;

      function apply() {
        var opt = select.options[select.selectedIndex];
        var mode = select.getAttribute('data-preview-mode');

        if (mode === 'grade') {
          target.className = 'grade grade--badge grade--' + select.value;
          var label = $('.grade__label', target);
          if (label) label.textContent = opt.getAttribute('data-short') || opt.text;
        } else if (mode === 'chip') {
          target.className = 'chip chip--' + select.value;
          target.textContent = opt.text;
        } else {
          target.textContent = opt.text;
          if (opt.getAttribute('data-class')) target.className = opt.getAttribute('data-class');
        }
      }

      select.addEventListener('change', apply);
      apply();
    });
  }

  /* ------------------------------------------------------- back a request */

  function initBacking() {
    $$('[data-back]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var countEl = document.getElementById(btn.getAttribute('data-back'));
        var on = btn.getAttribute('aria-pressed') === 'true';
        btn.setAttribute('aria-pressed', on ? 'false' : 'true');
        btn.textContent = on ? 'Back this' : 'Backed ✓';
        btn.classList.toggle('btn--olive', !on);
        btn.classList.toggle('btn--dark', on);
        if (countEl) {
          var n = parseInt(countEl.textContent, 10) || 0;
          countEl.textContent = String(on ? n - 1 : n + 1);
        }
      });
    });
  }

  /* The sell page payout preview. Every rate comes out of the markup, so PHP
     sets them from config and this file never carries a second copy of the
     numbers. Blocks marked data-tier-when show only for the chosen tier. */

  function initPayout() {
    $$('[data-payout]').forEach(function (scope) {
      var price = $('[data-payout-price]', scope);
      var tiers = $$('[data-payout-tier]', scope);
      if (!price) return;

      var fmt = function (n) {
        return '\u20B9' + Math.round(n).toLocaleString('en-IN');
      };

      // Every match, not just the first: the sell page shows the net payout
      // twice -- in the rail card, and inline under the price field, which is
      // the only one a phone can see while the price is being typed.
      var put = function (name, text) {
        $$('[data-out="' + name + '"]', scope).forEach(function (el) {
          el.textContent = text;
        });
      };

      /* Rates come off the chosen tier where there is a choice, and off the
         scope itself where there is a single fee. */
      function current() {
        for (var i = 0; i < tiers.length; i++) {
          if (tiers[i].checked) return tiers[i];
        }
        return tiers[0] || scope;
      }

      function render() {
        var tier = current();
        var p = Number(price.value) || 0;
        var num = function (attr) {
          return tier ? Number(tier.getAttribute(attr)) || 0 : 0;
        };

        var fee = p * num('data-fee-pct') / 100;
        var handling = p * num('data-handling-pct') / 100 + (p > 0 ? num('data-flat') : 0);

        put('price', fmt(p));
        put('fee', '\u2212' + fmt(fee));
        put('handling', handling > 0 ? '\u2212' + fmt(handling) : fmt(0));
        put('net', fmt(Math.max(0, p - fee - handling)));

        var name = tier && tier.value ? tier.value : '';
        $$('[data-tier-when]', scope).forEach(function (block) {
          block.hidden = block.getAttribute('data-tier-when') !== name;
        });
      }

      price.addEventListener('input', render);
      tiers.forEach(function (t) { t.addEventListener('change', render); });
      render();
    });
  }

  /* A filter rail is a plain GET form, so it works with this file blocked.
     Here we just submit it when something changes, instead of making people
     find an Apply button. */

  function initAutoFilter() {
    $$('form[data-autofilter]').forEach(function (form) {
      var submit = function () {
        /* Drop empty fields so the URL stays readable and the range slider at
           its maximum does not read as "under 45,000". */
        $$('input, select', form).forEach(function (el) {
          if (el.type === 'checkbox' || el.type === 'radio') return;
          if (el.name && el.value === '') el.disabled = true;
          if (el.hasAttribute('data-price-range') && el.value === el.max) el.disabled = true;
        });
        /* Any change puts you back on page one: page 3 of the old filter is
           usually past the end of the new one. */
        var page = form.querySelector('[name="page"]');
        if (page) page.disabled = true;
        form.submit();
      };

      form.addEventListener('change', function (ev) {
        if (ev.target.type === 'range') return;   // handled on release
        submit();
      });

      $$('[data-price-range]', form).forEach(function (range) {
        var out = $('[data-price-out]', form);
        var fmt = function (n) { return '\u20B9' + Number(n).toLocaleString('en-IN'); };
        range.addEventListener('input', function () {
          if (out) out.textContent = Number(range.value) >= Number(range.max)
            ? fmt(range.max) : fmt(range.value);
        });
        range.addEventListener('change', submit);
      });

      /* Enter in a number field should filter, not do nothing. */
      form.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.tagName === 'INPUT') {
          ev.preventDefault();
          submit();
        }
      });
    });
  }

  /* Restock alerts. The form posts on its own without this; all we add is
     answering in place rather than reloading the page. */

  /* ------------------------------------------------- server messages ---- */

  /* Move focus to the message a failed submit came back with.
   *
   * role="alert" on its own does NOT do this. A live region announces a
   * CHANGE to itself, and on a server round-trip the whole document is new,
   * so the region has existed since parse and most screen readers say
   * nothing: the person is dropped at the top of a fresh page with no idea
   * their password was wrong. Focus is what actually delivers it, and it
   * helps everyone else too -- a keyboard user lands on the message instead
   * of tabbing from the logo, and a long form scrolls to it rather than
   * leaving it above the fold by luck.
   *
   * Every role="alert" on this site is a message from the last submit --
   * an error, or a "saved" -- so all of them are worth landing on. */
  function initMessages() {
    var msg = $('main [role="alert"], [role="alert"]');
    if (!msg) return;

    // Something inside it may already deserve focus more (a field the server
    // echoed back as invalid); don't fight that.
    if (document.activeElement && document.activeElement !== document.body) return;

    if (!msg.hasAttribute('tabindex')) msg.setAttribute('tabindex', '-1');
    try { msg.focus(); } catch (e) { /* older browsers: the message is still read in order */ }
  }

  function initAlertForms() {
    $$('form[data-alert]').forEach(function (form) {
      var msg = $('[data-alert-msg]', form);

      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var button = $('button[type="submit"]', form);
        var say = function (text, ok) {
          if (!msg) return;
          msg.hidden = false;
          msg.textContent = text;
          msg.className = 'tiny alert-form__msg ' + (ok ? 'tag-ok' : 'tag-warn');
        };

        if (button) { button.disabled = true; button.textContent = 'Saving\u2026'; }

        fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
        })
          .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong.' }; }); })
          .then(function (data) {
            say(data.message || '', data.ok);
            if (data.ok) {
              form.querySelector('[name="contact"]').value = '';
              if (button) button.textContent = 'Done';
              else return;
            } else if (button) {
              button.disabled = false;
              button.textContent = 'Alert me when it lands';
            }
          })
          .catch(function () {
            say('Could not reach the server. Try again in a minute.', false);
            if (button) { button.disabled = false; button.textContent = 'Alert me when it lands'; }
          });
      });
    });
  }

  /* A checkbox that has to be ticked before a button becomes usable. The
     button ships disabled, so with this file blocked it stays disabled and
     the required attribute stops the submit instead. */

  function initGates() {
    $$('[data-gates]').forEach(function (box) {
      var button = document.getElementById(box.getAttribute('data-gates'));
      if (!button) return;
      var sync = function () { button.disabled = !box.checked; };
      box.addEventListener('change', sync);
      sync();
    });
  }

  /* Forms whose backend is not built yet. They used to carry an inline
     onsubmit, which the content security policy now blocks — and a blocked
     inline handler means the form submits for real, which is worse than
     doing nothing. So it is handled here, and it says so out loud. */

  function initInertForms() {
    $$('form[data-inert]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var note = form.querySelector('[data-inert-note]');
        if (!note) {
          note = document.createElement('p');
          note.className = 'tiny tag-warn';
          note.setAttribute('data-inert-note', '');
          note.setAttribute('role', 'status');
          note.style.flex = '1 1 100%';
          note.style.marginTop = '8px';
          form.appendChild(note);
        }
        note.textContent = 'Not connected yet — this form has nowhere to send to until the next build step.';
      });
    });
  }

  /* A select that navigates on change, and a destructive button that asks
     first. Both degrade: the form still has a submit button without this. */

  function initAutoSubmit() {
    $$('form[data-autosubmit]').forEach(function (form) {
      form.addEventListener('change', function () { form.submit(); });
    });
  }

  function initConfirms() {
    $$('[data-confirm]').forEach(function (el) {
      el.addEventListener('submit', function (ev) {
        var message = el.getAttribute('data-confirm')
          .replace(/&ldquo;/g, '“').replace(/&rdquo;/g, '”')
          .replace(/&amp;/g, '&');
        if (!window.confirm(message)) ev.preventDefault();
      });
    });
  }

  /* A submit button that fills in a hidden field first — used by the photo
     Remove buttons, so each one can say which image it means without a
     nested form (which HTML does not allow). */

  function initSetters() {
    $$('[data-sets]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var pair = btn.getAttribute('data-sets').split(':');
        var field = document.getElementById(pair[0]);
        if (field) field.value = pair.slice(1).join(':');
      });
    });
  }

  /* Add to cart, without losing the page you were on. Falls back to a plain
     form post when this file is blocked, because the button is inside a real
     form that posts to the same endpoint. */

  function initAddToCart() {
    $$('form[data-add-to-cart]').forEach(function (form) {
      var falling_back = false;

      form.addEventListener('submit', function (ev) {
        /* Set when the fetch failed and we are letting the real post through,
           so this handler stands aside rather than looping. */
        if (falling_back) return;
        ev.preventDefault();

        var button = $('button[type="submit"]', form);
        var original = button ? button.textContent : '';
        if (button) { button.disabled = true; button.textContent = 'Adding\u2026'; }

        fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            setCartCount(data.count);
            if (button) {
              button.textContent = data.ok ? 'Added to cart' : 'Could not add';
              setTimeout(function () {
                button.disabled = false;
                button.textContent = original;
              }, 1800);
            }
            var note = $('[data-cart-note]', form.parentNode) || $('[data-cart-note]');
            if (note && data.message) {
              note.hidden = false;
              note.textContent = data.message;
              note.className = 'tiny ' + (data.ok ? 'tag-ok' : 'tag-warn');
            }
          })
          .catch(function () {
            /* The network failed, not the cart. Let the real post through so
               they still end up with the item. */
            falling_back = true;
            form.submit();
          });
      });
    });
  }

  function setCartCount(n) {
    if (typeof n !== 'number') return;
    $$('.cart-btn').forEach(function (btn) {
      btn.childNodes.forEach(function (node) {
        if (node.nodeType === 3 && node.textContent.trim() !== '') {
          node.textContent = ' ' + n;
        }
      });
      btn.setAttribute('aria-label', 'Cart, ' + (n === 0 ? 'empty' : n + ' items'));
    });
  }

  /* A quantity select on the cart page submits its own form on change. */

  function initCartForms() {
    $$('form[data-cart-form]').forEach(function (form) {
      $$('[data-autosubmit-field]', form).forEach(function (field) {
        field.addEventListener('change', function () { form.submit(); });
      });
    });
  }

  /* After a payment, the browser knows more than our database does for a
     second or two — the webhook is still in flight. Rather than telling
     someone to refresh, the page asks until it settles.

     It gives up after about a minute. The money is not at stake either way:
     the webhook will land whether or not anyone is watching this page. */

  function initOrderPoll() {
    var box = $('[data-poll-order]');
    if (!box) return;

    var ref = box.getAttribute('data-poll-order');
    var token = box.getAttribute('data-poll-token');
    var tries = 0;

    var ask = function () {
      if (tries++ > 20) return;

      fetch('/api/order-status?ref=' + encodeURIComponent(ref) +
            '&t=' + encodeURIComponent(token), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok && data.settled) {
            window.location.reload();
          } else {
            setTimeout(ask, 3000);
          }
        })
        .catch(function () { setTimeout(ask, 5000); });
    };

    setTimeout(ask, 2500);
  }

  /* The inspection tick on a listing: two totals, both worked out by the
     server, swapped between. The server recalculates either way — this only
     saves the buyer a page load to see what it costs. */

  function initPriceSwitch() {
    $$('[data-price-switch]').forEach(function (box) {
      var out = $('[data-price-out-total]');
      if (!out) return;

      var fmt = function (paise) {
        return '\u20B9' + Math.round(paise / 100).toLocaleString('en-IN');
      };

      var show = function () {
        out.textContent = fmt(Number(box.getAttribute(
          box.checked ? 'data-with' : 'data-without')) || 0);
      };

      box.addEventListener('change', show);
      show();
    });
  }

  /* ------------------------------------------------------------- boot ---- */

  function boot() {
    initNav();
    initAccordions();
    initTabs();
    initFilters();
    initSteppers();
    initPressGroups();
    initConditionals();
    initPreviews();
    initBacking();
    initPayout();
    initAutoFilter();
    initAlertForms();
    initMessages();
    initGates();
    initInertForms();
    initAutoSubmit();
    initConfirms();
    initSetters();
    initAddToCart();
    initCartForms();
    initOrderPoll();
    initPriceSwitch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
