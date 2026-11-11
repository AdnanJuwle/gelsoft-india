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

    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
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

  function initTabs() {
    $$('[data-tabs]').forEach(function (group) {
      var buttons = $$('[data-tab]', group);

      function show(name) {
        buttons.forEach(function (b) {
          b.setAttribute('aria-selected', b.getAttribute('data-tab') === name ? 'true' : 'false');
          if (b.hasAttribute('aria-current')) {
            b.setAttribute('aria-current', b.getAttribute('data-tab') === name ? 'true' : 'false');
          }
        });
        $$('[data-panel]', document).forEach(function (p) {
          if (!group.contains(p) && p.getAttribute('data-panel-group') !== group.getAttribute('data-tabs')) return;
          p.hidden = p.getAttribute('data-panel') !== name;
        });
      }

      buttons.forEach(function (b) {
        b.addEventListener('click', function () { show(b.getAttribute('data-tab')); });
      });

      var first = buttons.filter(function (b) { return b.getAttribute('aria-selected') === 'true'; })[0] || buttons[0];
      if (first) show(first.getAttribute('data-tab'));
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
      var qty = parseInt(out ? out.textContent : '1', 10) || 1;

      function render() {
        if (out) out.textContent = String(qty);
        if (total && unit) {
          total.textContent = '₹' + (unit * qty).toLocaleString('en-IN');
        }
      }

      $$('[data-step]', stepper).forEach(function (btn) {
        btn.addEventListener('click', function () {
          qty = Math.min(9, Math.max(1, qty + parseInt(btn.getAttribute('data-step'), 10)));
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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
