/* Gift Box Bundles - product edit screen: add/remove slots. */
jQuery(function ($) {
  'use strict';
  const $panel = $('#ggb_product_data');
  if (!$panel.length) return;
  const template = $('#ggb-slot-template').html() || '';
  let next = $panel.find('.ggb-slots .ggb-slot').length + 1000;

  $panel.on('click', '.ggb-add-slot', function () {
    $panel.find('.ggb-slots').append(template.replace(/__i__/g, String(next++)));
    // Turns the new product field into WooCommerce's product search.
    $(document.body).trigger('wc-enhanced-select-init');
  });
  $panel.on('click', '.ggb-remove-slot', function () {
    if (window.confirm('Διαγραφή θέσης;')) $(this).closest('.ggb-slot').remove();
  });
});
