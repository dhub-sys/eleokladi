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

  $('.ggb-box').each(function () { setup($(this)); });

  function setup($box) {
    const $form = $box.closest('form');
    const boxMin = Number($box.data('min')) || 0;
    const boxMax = Number($box.data('max')) || 0;
    const fee = Number($box.data('fee')) || 0;
    let tried = false;

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
        const $o = $(this).find('option:selected');
        const img = $(this).find('select').val() ? $o.data('img') : '';
        $(this).toggleClass('is-chosen', !!img).find('.ggb-thumb').html(img ? $('<img alt="">').attr('src', img) : '');
      });
      if (tried) showProblems(problems(st));
      return st;
    }

    function showProblems(list) {
      const $errors = $box.find('.ggb-errors');
      $errors.empty().prop('hidden', !list.length);
      list.forEach(text => $('<p>').text(text).appendTo($errors));
    }

    $box.on('change', 'input, select', update);

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
