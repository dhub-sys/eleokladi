/* Gift Box Bundles - product page: counts the chosen products per slot and for the
 * whole box, keeps the limits, shows the live total and blocks an incomplete box. */
jQuery(function ($) {
  'use strict';
  const data = window.GGBData || {};
  const i18n = data.i18n || {};
  $('.ggb-box').each(function () { setup($(this)); });

  function setup($box) {
    const $form = $box.closest('form');
    const boxMin = Number($box.data('min')) || 0;
    const boxMax = Number($box.data('max')) || 0;
    const fee = Number($box.data('fee')) || 0;
    let tried = false;

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

    /** Chosen inputs of a slot with their quantity: [{input, qty, price}]. */
    function picks($slot) {
      const list = [];
      $slot.find('input').each(function () {
        const $i = $(this);
        let qty = 0;
        if ($i.is('.ggb-qty, .ggb-fixed')) qty = Math.max(0, parseInt($i.val(), 10) || 0);
        else if ($i.is(':checked') && $i.val() !== '') qty = 1;
        if (qty) list.push({input: this, qty: qty, price: Number($i.data('price')) || 0});
      });
      return list;
    }
    const sum = (list, key) => list.reduce((t, p) => t + (key ? p[key] * p.qty : p.qty), 0);

    function state() {
      const slots = $box.find('.ggb-slot').map(function () {
        const $slot = $(this);
        const list = picks($slot);
        return {$slot: $slot, min: Number($slot.data('min')) || 0, max: Number($slot.data('max')) || 0,
          label: $slot.data('label'), count: sum(list), price: sum(list, 'price')};
      }).get();
      return {slots: slots, count: slots.reduce((t, s) => t + s.count, 0), price: slots.reduce((t, s) => t + s.price, 0)};
    }

    function problems(st) {
      const list = [];
      st.slots.forEach(s => {
        if (s.count < s.min) list.push(sprintf(i18n.slotMin, s.label, s.min));
        if (s.count > s.max) list.push(sprintf(i18n.slotMax, s.label, s.max));
      });
      if (boxMin && st.count < boxMin) list.push(sprintf(i18n.boxMin, boxMin));
      if (boxMax && st.count > boxMax) list.push(sprintf(i18n.boxMax, boxMax));
      return list;
    }

    /** Stops the customer going over a slot's or the box's maximum. */
    function limit(st) {
      const boxLeft = boxMax ? boxMax - st.count : Infinity;
      st.slots.forEach(s => {
        const left = Math.min(s.max - s.count, boxLeft);
        s.$slot.find('input').each(function () {
          const $i = $(this);
          if ($i.closest('.ggb-option').hasClass('is-out') || $i.is('.ggb-fixed')) return;
          if ($i.is('.ggb-qty')) {
            const value = parseInt($i.val(), 10) || 0;
            $i.attr('max', Math.max(value, value + left));
          } else if ($i.is(':checkbox')) {
            $i.prop('disabled', !$i.is(':checked') && left <= 0);
          } else if ($i.val() !== '') {
            // Radio: switching keeps the count; only coming from "Aucun" adds one.
            const fromNone = s.count === 0;
            $i.prop('disabled', fromNone && left <= 0);
          }
        });
      });
    }

    function update() {
      const st = state();
      limit(st);
      $box.find('.ggb-count').text(st.count);
      $box.find('.ggb-total').html(priceHtml(fee + st.price));
      $box.find('.ggb-option').each(function () {
        const $o = $(this);
        const $i = $o.find('input').first();
        $o.toggleClass('is-chosen', $i.is('.ggb-qty, .ggb-fixed') ? (parseInt($i.val(), 10) || 0) > 0 : $i.is(':checked') && $i.val() !== '');
      });
      if (tried) showProblems(problems(st));
      return st;
    }

    function showProblems(list) {
      const $errors = $box.find('.ggb-errors');
      $errors.empty().prop('hidden', !list.length);
      list.forEach(text => $('<p>').text(text).appendTo($errors));
    }

    $box.on('change input', 'input', function () {
      const $i = $(this);
      if ($i.is('.ggb-qty')) {
        const max = Number($i.attr('max')) || 0;
        const value = Math.max(0, parseInt($i.val(), 10) || 0);
        if (value > max) $i.val(max);
      }
      update();
    });

    $form.on('submit', function (e) {
      tried = true;
      const list = problems(update());
      if (!list.length) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      showProblems(list);
      $('html, body').animate({scrollTop: Math.max(0, $box.find('.ggb-errors').offset().top - 120)}, 250);
    });

    update();
  }
});
