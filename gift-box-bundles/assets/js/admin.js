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
  // Photo fields: WordPress media library.
  $panel.on('click', '.ggb-image-choose', function (e) {
    e.preventDefault();
    const $field = $(this).closest('.ggb-image-field');
    const frame = wp.media({title: 'Φωτογραφία πριν την επιλογή', library: {type: 'image'}, multiple: false, button: {text: 'Χρήση εικόνας'}});
    frame.on('select', function () {
      const image = frame.state().get('selection').first().toJSON();
      const sizes = image.sizes || {};
      $field.find('input').val(image.id);
      $field.find('img').attr('src', (sizes.thumbnail || sizes.full || image).url).show();
      $field.find('.ggb-image-remove').show();
    });
    frame.open();
  });
  $panel.on('click', '.ggb-image-remove', function (e) {
    e.preventDefault();
    const $field = $(this).closest('.ggb-image-field');
    $field.find('input').val('');
    $field.find('img').attr('src', '').hide();
    $(this).hide();
  });
  $panel.on('click', '.ggb-remove-slot', function () {
    if (window.confirm('Διαγραφή θέσης;')) $(this).closest('.ggb-slot').remove();
  });
});
