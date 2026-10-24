/* Gift Box Bundles - product page: counts the chosen products per slot and for the
 * whole box, keeps the limits, shows the live total and blocks an incomplete box. */
jQuery(function ($) {
  'use strict';
  const data = window.GGBData || {};
  const i18n = data.i18n || {};
  const sprintf = (text, ...args) => args.reduce((t, a, i) => t.replace('%' + (i + 1) + '$s', a).replace('%' + (i + 1) + '$d', a).replace(/%[sd]/, a), String(text || ''));

  function formatAmount(amount) {
    const f = data.format || {};
    const decimals = Number.isInteger(f.decimals) ? f.decimals : 2;
    const parts = Math.abs(amount).toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, f.thousandSep === undefined ? ' ' : f.thousandSep);
    const number = parts.join(f.decimalSep === undefined ? ',' : f.decimalSep);
    return (f.pattern || '%2$s %1$s').replace('%1$s', f.symbol || '€').replace('%2$s', number);
  }
  function priceHtml(amount) {
    return '<span class="woocommerce-Price-amount amount"><bdi>' + $('<div>').text(formatAmount(amount)).html() + '</bdi></span>';
  }

  // With [gift_box] on the page, the box that the theme's own add-to-cart form (e.g. YOOtheme's
  // Add to Cart element) also shows is a duplicate: hide that whole form.
  $('form.ggb-form .ggb-box').each(function () {
    $('.ggb-box[data-product="' + $(this).data('product') + '"]').not(this).closest('form').not('.ggb-form').hide()
      .find('.ggb-box').remove();
  });
  $('.ggb-box').each(function () { setup($(this)); });

  function setup($box) {
    const $form = $box.closest('form');
    const form = $form.get(0);
    const boxMin = Number($box.data('min')) || 0;
    const boxMax = Number($box.data('max')) || 0;
    const fee = Number($box.data('fee')) || 0;
    let $tip = null;

    /** Chosen products of a slot: [{qty, price}]. */
    function picks($slot) {
      const list = [];
      $slot.find('input.ggb-fixed').each(function () {
        list.push({qty: Math.max(0, parseInt(this.value, 10) || 0), price: Number($(this).data('price')) || 0});
      });
      $slot.find('input:checkbox:checked').each(function () {
        list.push({qty: 1, price: Number($(this).data('price')) || 0});
      });
      $slot.find('select.ggb-select').each(function () {
        const $o = $(this).find('option:selected');
        if (this.value) list.push({qty: 1, price: Number($o.data('price')) || 0});
      });
      return list;
    }

    function state() {
      const slots = $box.find('.ggb-slot').map(function () {
        const $slot = $(this);
        const list = picks($slot);
        return {$slot: $slot, min: Number($slot.data('min')) || 0, max: Number($slot.data('max')) || 0,
          repeat: String($slot.data('repeat')) === '1', label: $slot.data('label'),
          count: list.reduce((t, p) => t + p.qty, 0), price: list.reduce((t, p) => t + p.qty * p.price, 0)};
      }).get();
      return {slots: slots, count: slots.reduce((t, s) => t + s.count, 0), price: slots.reduce((t, s) => t + s.price, 0)};
    }

    /** What is missing, as lines for the tooltip. [] = the box can be added to the cart. */
    function problems(st) {
      const list = [];
      const missing = st.slots.filter(s => s.count < s.min);
      const left = Math.max(boxMin - st.count, missing.reduce((t, s) => t + s.min - s.count, 0));
      if (left > 0) list.push(sprintf(left === 1 ? i18n.leftOne : i18n.left, left));
      if (missing.length) list.push(sprintf(i18n.toChoose, missing.map(s => s.label).join(', ')));
      st.slots.forEach(s => { if (s.count > s.max) list.push(sprintf(i18n.slotMax, s.label, s.max)); });
      if (boxMax && st.count > boxMax) list.push(sprintf(i18n.boxMax, boxMax));
      return list;
    }
    /** Red outline on the dropdowns a slot still needs. */
    function markMissing(st, on) {
      $box.find('.ggb-missing').removeClass('ggb-missing');
      if (!on) return;
      st.slots.forEach(s => {
        s.$slot.find('select.ggb-select').filter(function () { return !this.value; })
          .slice(0, Math.max(0, s.min - s.count)).closest('.ggb-choice').addClass('ggb-missing');
      });
    }

    /** Keeps the box's maximum and, where a product may be taken once, hides it in the other dropdowns. */
    function limit(st) {
      const boxFull = boxMax && st.count >= boxMax;
      st.slots.forEach(s => {
        const $selects = s.$slot.find('select.ggb-select');
        $selects.each(function () {
          const select = this;
          const taken = s.repeat ? [] : $selects.not(select).map(function () { return this.value; }).get().filter(Boolean);
          $(select).find('option').each(function () {
            if (!this.value) return;
            const out = String($(this).data('out')) === '1';
            // An empty dropdown cannot add a product to a full box; a filled one can still be changed.
            this.disabled = out || taken.includes(this.value) || (boxFull && !select.value);
          });
        });
        s.$slot.find('input:checkbox').each(function () {
          if ($(this).closest('.ggb-option').hasClass('is-out')) return;
          this.disabled = !this.checked && boxFull;
        });
      });
    }

    function update() {
      const st = state();
      limit(st);
      $box.find('.ggb-count').text(st.count);
      $box.find('.ggb-total').html(priceHtml(fee + st.price));
      $box.find('.ggb-option').each(function () {
        const $i = $(this).find('input').first();
        $(this).toggleClass('is-chosen', $i.is('.ggb-fixed') || $i.is(':checked'));
      });
      $box.find('.ggb-choice').each(function () {
        const chosen = !!$(this).find('select').val();
        const $thumb = $(this).find('.ggb-thumb');
        // Before a choice: the photo set on the box/slot.
        const img = chosen ? $(this).find('option:selected').data('img') : $thumb.data('default');
        $(this).toggleClass('is-chosen', chosen);
        if (img && $thumb.find('img').attr('src') !== img) $thumb.html($('<img alt="">').attr('src', img));
      });
      // Once shown, the tooltip follows the choices and closes when the box is complete.
      if ($tip) {
        const list = problems(st);
        markMissing(st, list.length > 0);
        if (list.length) fillTip(list); else hideTip();
      }
      return st;
    }

    /* ---- Tooltip above the add-to-cart button ------------------------- */
    function fillTip(list) {
      $tip.find('.ggb-tip-body').empty().append(list.map(text => $('<p>').text(text)));
    }
    function showTip(list, button) {
      if (!$tip) {
        $tip = $('<div class="ggb-tip" role="alert"><strong class="ggb-tip-title"></strong><div class="ggb-tip-body"></div></div>');
        $tip.find('.ggb-tip-title').text(i18n.tipTitle || '');
        if ($form.css('position') === 'static') $form.css('position', 'relative');
        $form.append($tip);
      }
      fillTip(list);
      const f = form.getBoundingClientRect();
      const b = (button || $form.find('[type="submit"]').get(0) || form).getBoundingClientRect();
      $tip.css({left: Math.max(0, b.left - f.left) + 'px', bottom: (f.bottom - b.top + 14) + 'px'});
      $tip.get(0).scrollIntoView({block: 'nearest', behavior: 'smooth'});
    }
    function hideTip() {
      if ($tip) $tip.remove();
      $tip = null;
      markMissing(null, false);
    }

    $box.on('change', 'input, select', update);

    /**
     * Checked in the capture phase, before the theme's own handlers: YOOtheme (and other
     * themes) add to the cart over AJAX with only the product and quantity, which loses
     * the box's choices. An incomplete box shows the tooltip; a complete one is posted
     * by the browser itself, with every choice.
     */
    function guard(e, button) {
      const st = update();
      const list = problems(st);
      e.stopImmediatePropagation();
      if (!list.length) return;
      e.preventDefault();
      markMissing(st, true);
      showTip(list, button);
    }
    window.addEventListener('click', function (e) {
      const button = e.target.closest && e.target.closest('button, input[type="submit"]');
      if (button && button.form === form && (button.type === 'submit' || button.name === 'add-to-cart')) guard(e, button);
    }, true);
    window.addEventListener('submit', function (e) { if (e.target === form) guard(e, null); }, true);
    document.addEventListener('click', function (e) {
      if ($tip && !$(e.target).closest('.ggb-tip, button, input[type="submit"]').length) hideTip();
    });

    update();
  }
});
