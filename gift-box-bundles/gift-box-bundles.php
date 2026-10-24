<?php
/**
 * Plugin Name: Gift Box Bundles
 * Description: Συσκευασίες δώρου (κουτιά) με θέσεις προϊόντων: σταθερά προϊόντα, επιλογή από λίστα, ελάχιστο/μέγιστο πλήθος. Το κουτί χρεώνεται με τη δική του τιμή και κάθε προϊόν μπαίνει στο καλάθι ως δική του γραμμή (απόθεμα, ΦΠΑ).
 * Version: 1.1.0
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Author: Custom
 * Text Domain: gift-box-bundles
 */
if (!defined('ABSPATH')) exit;

/**
 * A gift box is a simple product whose own price is the packaging fee.
 * In its "Συσκευασία δώρου" tab the shop defines slots; each slot has a list of
 * products and how many of them the customer takes (min–max, same product more
 * than once or not). The box may also limit the total number of products.
 *
 * In the cart the box is one line (the fee) followed by one line per chosen
 * product at its own price ("children"). Children follow the box's quantity,
 * cannot be removed or changed on their own, and are removed with the box.
 */
final class GGB_Gift_Box {
    const VERSION = '1.1.0';
    const ENABLED_KEY = '_ggb_enabled';
    const SLOTS_KEY = '_ggb_slots';
    const MIN_KEY = '_ggb_min_items';
    const MAX_KEY = '_ggb_max_items';
    /** Set while the plugin itself removes box lines, so the removal hooks do not recurse. */
    private static $removing = false;
    /** Product IDs whose box was printed on this page, for the shortcode and the diagnostics. */
    private static $rendered = [];

    public static function init() {
        // Admin: product tab.
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'product_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'product_panel']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        // Product page.
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render'], 5);
        // For page builders / themes whose product template has no WooCommerce add-to-cart form.
        add_shortcode('gift_box', [__CLASS__, 'shortcode']);
        add_action('wp_footer', [__CLASS__, 'diagnostics']);
        add_filter('woocommerce_get_price_html', [__CLASS__, 'price_html'], 20, 2);
        // Listings: the box needs its product page, never a direct/AJAX add to cart.
        add_filter('woocommerce_product_supports', [__CLASS__, 'supports'], 10, 3);
        add_filter('woocommerce_product_add_to_cart_url', [__CLASS__, 'add_to_cart_url'], 20, 2);
        add_filter('woocommerce_product_add_to_cart_text', [__CLASS__, 'add_to_cart_text'], 20, 2);
        // Cart.
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'cart_item_data'], 10, 2);
        add_action('woocommerce_add_to_cart', [__CLASS__, 'add_children'], 10, 6);
        add_action('woocommerce_after_cart_item_quantity_update', [__CLASS__, 'sync_quantity'], 10, 4);
        add_action('woocommerce_remove_cart_item', [__CLASS__, 'remove_linked'], 10, 2);
        add_action('woocommerce_cart_item_restored', [__CLASS__, 'restore_children'], 10, 2);
        add_action('woocommerce_cart_loaded_from_session', [__CLASS__, 'repair_cart'], 30);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'check_cart']);
        add_filter('woocommerce_cart_item_remove_link', [__CLASS__, 'child_remove_link'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'child_quantity'], 10, 3);
        add_filter('woocommerce_cart_item_class', [__CLASS__, 'cart_item_class'], 10, 3);
        add_filter('woocommerce_mini_cart_item_class', [__CLASS__, 'cart_item_class'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'item_data'], 10, 2);
        // Order.
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'order_item_meta'], 10, 4);
        add_filter('woocommerce_order_item_class', [__CLASS__, 'order_item_class'], 10, 2);
    }

    /* ------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------ */
    public static function is_box($product) {
        if (is_numeric($product)) $product = wc_get_product($product);
        return $product && $product->is_type('simple') && $product->get_meta(self::ENABLED_KEY, true) === 'yes';
    }
    /**
     * ['slots' => [['label','products'=>[ids],'min','max','repeat'], ...], 'min' => int, 'max' => int]
     * or null. 'min'/'max' 0 = no limit on the total number of products.
     */
    public static function config($product) {
        if (is_numeric($product)) $product = wc_get_product($product);
        if (!self::is_box($product)) return null;
        $slots = $product->get_meta(self::SLOTS_KEY, true);
        $slots = is_array($slots) ? array_values(array_filter(array_map([__CLASS__, 'clean_slot'], $slots))) : [];
        return [
            'slots' => $slots,
            'min' => absint($product->get_meta(self::MIN_KEY, true)),
            'max' => absint($product->get_meta(self::MAX_KEY, true)),
        ];
    }
    /** Normalised slot, or null when it has no products. Also used to sanitise the admin form. */
    public static function clean_slot($slot) {
        if (!is_array($slot)) return null;
        $ids = isset($slot['products']) && is_array($slot['products']) ? $slot['products'] : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) return null;
        $repeat = !empty($slot['repeat']);
        $min = absint($slot['min'] ?? 1);
        $max = absint($slot['max'] ?? 1);
        if ($max < 1) $max = max(1, $min);
        // Without repeats a slot cannot hold more products than it lists.
        if (!$repeat) $max = min($max, count($ids));
        $min = min($min, $max);
        return [
            'label' => sanitize_text_field($slot['label'] ?? ''),
            'products' => $ids,
            'min' => $min,
            'max' => $max,
            'repeat' => $repeat,
        ];
    }
    /**
     * A slot product the customer can get right now, or null.
     * Simple products and variations (with every attribute set) only.
     */
    private static function option_product($id) {
        $product = wc_get_product($id);
        if (!$product || !$product->is_type(['simple', 'variation']) || self::is_box($product)) return null;
        if ($product->get_status() !== 'publish' && !$product->is_type('variation')) return null;
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent || $parent->get_status() !== 'publish') return null;
            if (in_array('', $product->get_variation_attributes(), true)) return null;
        }
        return $product->is_purchasable() ? $product : null;
    }
    /**
     * How a slot is shown: fixed (one product, no choice), check (one optional product,
     * ticked) or select (one dropdown per product the slot holds, e.g. 2 dropdowns for "2 of 3").
     */
    private static function slot_mode($slot) {
        if (count($slot['products']) > 1 || ($slot['repeat'] && $slot['min'] < $slot['max'])) return 'select';
        return $slot['min'] >= 1 && $slot['min'] === $slot['max'] ? 'fixed' : 'check';
    }

    /* ------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */
    public static function product_tab($tabs) {
        $tabs['ggb'] = [
            'label' => 'Συσκευασία δώρου',
            'target' => 'ggb_product_data',
            'class' => ['show_if_simple'],
            'priority' => 65,
        ];
        return $tabs;
    }
    public static function product_panel() {
        global $product_object;
        $product = $product_object instanceof WC_Product ? $product_object : null;
        $slots = $product ? $product->get_meta(self::SLOTS_KEY, true) : [];
        $slots = is_array($slots) ? array_values(array_filter(array_map([__CLASS__, 'clean_slot'], $slots))) : [];
        echo '<div id="ggb_product_data" class="panel woocommerce_options_panel hidden">';
        if ($product && $product->get_id() && ($product->get_meta(self::ENABLED_KEY, true) === 'yes' || $slots)) {
            $problems = self::problems($product);
            echo '<div style="margin:12px;padding:8px 12px;border-left:4px solid ' . ($problems ? '#b32d2e' : '#00a32a') . ';background:#fff">';
            if ($problems) {
                echo '<strong>Δεν εμφανίζεται στον πελάτη:</strong><ul style="list-style:disc;margin:4px 0 0 18px">';
                foreach ($problems as $problem) echo '<li>' . esc_html($problem) . '</li>';
                echo '</ul>';
            } else {
                echo '✔ Οι ρυθμίσεις είναι σωστές. Αν οι επιλογές δεν φαίνονται στη σελίδα του προϊόντος, βάλτε το shortcode <code>[gift_box]</code> στο πρότυπο της σελίδας.';
            }
            echo '</div>';
        }
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => self::ENABLED_KEY,
            'label' => 'Είναι συσκευασία δώρου',
            'description' => 'Ο πελάτης γεμίζει το κουτί με τα προϊόντα που ορίζετε παρακάτω.',
            'value' => $product ? $product->get_meta(self::ENABLED_KEY, true) : '',
        ]);
        echo '<p class="form-field"><span class="description" style="display:block">Η <strong>κανονική τιμή</strong> του προϊόντος (καρτέλα «Γενικά») είναι το <strong>κόστος της συσκευασίας</strong> (π.χ. 1 € ή 6 €). '
            . 'Κάθε προϊόν που διαλέγει ο πελάτης χρεώνεται με τη δική του τιμή και μπαίνει ως ξεχωριστή γραμμή στο καλάθι και στην παραγγελία, με το δικό του απόθεμα και ΦΠΑ.</span></p>';
        foreach ([self::MIN_KEY => 'Ελάχιστα προϊόντα στο κουτί', self::MAX_KEY => 'Μέγιστα προϊόντα στο κουτί'] as $key => $label) {
            woocommerce_wp_text_input([
                'id' => $key,
                'label' => $label,
                'type' => 'number',
                'custom_attributes' => ['min' => 0, 'step' => 1],
                'placeholder' => 'Χωρίς όριο',
                'description' => 'Σύνολο τεμαχίων από όλες τις θέσεις. Κενό ή 0 = χωρίς όριο (ισχύουν μόνο τα όρια κάθε θέσης).',
                'desc_tip' => true,
                'value' => $product ? ($product->get_meta($key, true) ?: '') : '',
            ]);
        }
        echo '</div>';
        echo '<div class="options_group ggb-admin">';
        echo '<h4 style="padding:0 12px">Θέσεις του κουτιού</h4>';
        echo '<p class="description" style="padding:0 12px">Κάθε θέση: τα προϊόντα που προτείνετε και πόσα από αυτά παίρνει ο πελάτης. '
            . 'Ένα προϊόν με ελάχιστο = μέγιστο = 1 εμφανίζεται ως «περιλαμβάνεται» (χωρίς επιλογή). Ένα προϊόν με ελάχιστο 0 εμφανίζεται ως τσεκαρισμένο κουτάκι που ο πελάτης μπορεί να βγάλει. '
            . 'Όπου υπάρχει επιλογή, ο πελάτης βλέπει ένα dropdown για κάθε προϊόν της θέσης (π.χ. «από 0 έως 2» = 2 dropdown). Το «ίδιο προϊόν πολλές φορές» επιτρέπει να διαλέξει το ίδιο προϊόν σε περισσότερα από ένα dropdown.</p>';
        echo '<div class="ggb-slots">';
        foreach ($slots as $i => $slot) echo self::slot_admin_html($i, $slot, $product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        echo '<p style="padding:0 12px"><button type="button" class="button ggb-add-slot">+ Προσθήκη θέσης</button></p>';
        // Keeps "all slots removed" saveable.
        echo '<input type="hidden" name="ggb_slots[__empty]" value="">';
        echo '<script type="text/html" id="ggb-slot-template">' . self::slot_admin_html('__i__', ['label' => '', 'products' => [], 'min' => 1, 'max' => 1, 'repeat' => false], $product) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div></div>';
    }
    private static function slot_admin_html($i, $slot, $product) {
        $name = 'ggb_slots[' . $i . ']';
        $options = '';
        $warnings = [];
        foreach ($slot['products'] as $id) {
            $p = wc_get_product($id);
            if (!$p) continue;
            $options .= '<option value="' . esc_attr($id) . '" selected>' . esc_html(wp_strip_all_tags($p->get_formatted_name())) . '</option>';
            if ($p->is_type('variable')) $warnings[] = '«' . $p->get_name() . '» έχει παραλλαγές: επιλέξτε τη συγκεκριμένη παραλλαγή.';
            elseif (self::is_box($p)) $warnings[] = '«' . $p->get_name() . '» είναι κι αυτό συσκευασία δώρου.';
            elseif (!self::option_product($id)) $warnings[] = '«' . $p->get_name() . '» δεν είναι διαθέσιμο για αγορά (κατάσταση, τιμή ή παραλλαγή χωρίς όλα τα χαρακτηριστικά).';
            elseif (!$p->is_in_stock()) $warnings[] = '«' . $p->get_name() . '» είναι εξαντλημένο (εμφανίζεται ως «Épuisé»).';
        }
        $html = '<div class="ggb-slot" style="border:1px solid #dcdcde;margin:8px 12px;padding:8px 12px;background:#fafafa">';
        $html .= '<p class="form-field"><label>Τίτλος θέσης</label><input type="text" class="short" name="' . $name . '[label]" value="' . esc_attr($slot['label']) . '" placeholder="π.χ. Huile d\'olive"> <span class="description">Τον βλέπει ο πελάτης (γράψτε τον στα γαλλικά).</span></p>';
        $html .= '<p class="form-field"><label>Προϊόντα</label><select class="wc-product-search" multiple="multiple" style="width:50%" name="' . $name . '[products][]" data-placeholder="Αναζήτηση προϊόντος…" data-action="woocommerce_json_search_products_and_variations"'
            . ($product && $product->get_id() ? ' data-exclude="' . esc_attr($product->get_id()) . '"' : '') . '>' . $options . '</select></p>';
        $html .= '<p class="form-field"><label>Πόσα παίρνει</label>από <input type="number" min="0" step="1" style="width:70px;float:none" name="' . $name . '[min]" value="' . esc_attr($slot['min']) . '"> '
            . 'έως <input type="number" min="1" step="1" style="width:70px;float:none" name="' . $name . '[max]" value="' . esc_attr($slot['max']) . '"></p>';
        $html .= '<p class="form-field"><label>Ίδιο προϊόν πολλές φορές</label><input type="checkbox" name="' . $name . '[repeat]" value="1" ' . checked(!empty($slot['repeat']), true, false) . '> <span class="description">Π.χ. 2 ίδια βάζα ελιές. Χωρίς αυτό, κάθε προϊόν μπαίνει το πολύ μία φορά.</span></p>';
        foreach ($warnings as $warning) $html .= '<p style="color:#b32d2e;margin:4px 0">⚠ ' . esc_html($warning) . '</p>';
        $html .= '<p style="text-align:right;margin:0"><button type="button" class="button-link button-link-delete ggb-remove-slot">Διαγραφή θέσης</button></p>';
        return $html . '</div>';
    }
    public static function save_product($product) {
        if (!$product->is_type('simple')) return;
        // Only when our panel was posted (not REST, imports, Quick Edit).
        if (!isset($_POST['ggb_slots']) || !is_array($_POST['ggb_slots'])) return;
        $product->update_meta_data(self::ENABLED_KEY, !empty($_POST[self::ENABLED_KEY]) ? 'yes' : 'no');
        foreach ([self::MIN_KEY, self::MAX_KEY] as $key) {
            $value = isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : 0;
            if ($value) $product->update_meta_data($key, $value);
            else $product->delete_meta_data($key);
        }
        $slots = [];
        foreach (wp_unslash($_POST['ggb_slots']) as $key => $slot) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_slot sanitises
            if ($key === '__empty' || !is_array($slot)) continue;
            if (isset($slot['products']) && is_array($slot['products'])) {
                $slot['products'] = array_diff(array_map('absint', $slot['products']), [$product->get_id()]);
            }
            $slot = self::clean_slot($slot);
            if ($slot) $slots[] = $slot;
        }
        $product->update_meta_data(self::SLOTS_KEY, $slots);
    }
    public static function admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || get_post_type() !== 'product') return;
        wp_enqueue_script('ggb-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery', 'wc-enhanced-select'], self::VERSION, true);
    }

    /* ------------------------------------------------------------------
     * Product page
     * ------------------------------------------------------------------ */
    public static function assets() {
        if (!is_product()) return;
        $product = wc_get_product(get_queried_object_id());
        if (self::is_box($product)) self::enqueue();
    }
    private static function enqueue() {
        if (wp_script_is('ggb-gift-box', 'enqueued')) return;
        wp_enqueue_style('ggb-gift-box', plugin_dir_url(__FILE__) . 'assets/css/gift-box.css', [], self::VERSION);
        wp_enqueue_script('ggb-gift-box', plugin_dir_url(__FILE__) . 'assets/js/gift-box.js', ['jquery'], self::VERSION, true);
        wp_localize_script('ggb-gift-box', 'GGBData', [
            'format' => [
                'symbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
                'pattern' => html_entity_decode(get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8'),
                'decimals' => wc_get_price_decimals(),
                'decimalSep' => wc_get_price_decimal_separator(),
                'thousandSep' => wc_get_price_thousand_separator(),
            ],
            'i18n' => [
                'slotMin' => __('« %1$s » : choisissez au moins %2$d produit(s).', 'gift-box-bundles'),
                'slotMax' => __('« %1$s » : %2$d produit(s) maximum.', 'gift-box-bundles'),
                'boxMin' => __('Votre coffret doit contenir au moins %d produits.', 'gift-box-bundles'),
                'boxMax' => __('Votre coffret peut contenir au maximum %d produits.', 'gift-box-bundles'),
            ],
        ]);
    }
    public static function render() {
        global $product;
        static $done = [];
        // A theme calling the hook twice in the same form would post every choice twice.
        if (!self::is_box($product) || isset($done[$product->get_id()])) return;
        $done[$product->get_id()] = true;
        self::render_box($product);
    }
    private static function render_box($product) {
        self::$rendered[$product->get_id()] = true;
        self::enqueue();
        $config = self::config($product);
        $fee = (float) wc_get_price_to_display($product);
        printf('<div class="ggb-box" data-min="%d" data-max="%d" data-fee="%s">', $config['min'], $config['max'], esc_attr($fee));
        echo '<input type="hidden" name="ggb_box" value="1">';
        foreach ($config['slots'] as $s => $slot) self::render_slot($s, $slot);
        echo '<div class="ggb-summary">';
        echo '<p class="ggb-count-line">' . esc_html__('Produits dans le coffret :', 'gift-box-bundles') . ' <strong class="ggb-count">0</strong>';
        if ($config['min'] && $config['min'] === $config['max']) echo ' / ' . (int) $config['max'];
        elseif ($config['min'] && $config['max']) echo ' <span class="ggb-range">(' . esc_html(sprintf(__('de %1$d à %2$d', 'gift-box-bundles'), $config['min'], $config['max'])) . ')</span>';
        elseif ($config['min']) echo ' <span class="ggb-range">(' . esc_html(sprintf(__('minimum %d', 'gift-box-bundles'), $config['min'])) . ')</span>';
        elseif ($config['max']) echo ' <span class="ggb-range">(' . esc_html(sprintf(__('maximum %d', 'gift-box-bundles'), $config['max'])) . ')</span>';
        echo '</p>';
        echo '<p class="ggb-fee-line">' . esc_html__('Coffret :', 'gift-box-bundles') . ' ' . wc_price($fee) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p class="ggb-total-line">' . esc_html__('Total :', 'gift-box-bundles') . ' <strong class="ggb-total">' . wc_price($fee) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        echo '<div class="ggb-errors" role="alert" hidden></div>';
        echo '</div>';
    }
    /**
     * [gift_box] or [gift_box id="123"]: the box with its own add-to-cart form, for product
     * templates (page builders) that do not print WooCommerce's add-to-cart form.
     * Prints nothing if the page already shows the box inside the normal form.
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'gift_box');
        $id = absint($atts['id']);
        if (!$id && is_product()) $id = get_queried_object_id();
        if (!$id && isset($GLOBALS['product']) && $GLOBALS['product'] instanceof WC_Product) $id = $GLOBALS['product']->get_id();
        if (!$id) $id = (int) get_the_ID();
        $product = $id ? wc_get_product($id) : null;
        // The shortcode always prints its own form: SEO plugins and page builders often run
        // the content once before the real output, so "already shown" cannot be trusted here.
        $problems = $product ? self::problems($product) : ['Δεν βρέθηκε ποιο προϊόν είναι το κουτί. Γράψτε [gift_box id="ID του κουτιού"].'];
        if ($problems) {
            if (!current_user_can('manage_woocommerce')) return '';
            return '<div class="ggb-diagnostics" style="border:2px dashed #b32d2e;padding:10px 14px;background:#fff;color:#1d2327"><strong>[gift_box] — δεν εμφανίζεται στον πελάτη</strong> <small>(το βλέπουν μόνο οι διαχειριστές)</small><ul style="margin:6px 0 0 18px"><li>'
                . implode('</li><li>', array_map('esc_html', $problems)) . '</li></ul></div>';
        }
        ob_start();
        echo '<form class="cart ggb-form" action="' . esc_url($product->get_permalink()) . '" method="post" enctype="multipart/form-data">';
        self::render_box($product);
        woocommerce_quantity_input(['min_value' => 1, 'max_value' => $product->get_max_purchase_quantity(), 'input_value' => 1], $product);
        echo '<button type="submit" name="add-to-cart" value="' . esc_attr($product->get_id()) . '" class="single_add_to_cart_button button alt">' . esc_html($product->single_add_to_cart_text()) . '</button>';
        echo '</form>';
        return ob_get_clean();
    }
    /** Why a box cannot be shown/bought, in Greek for the shop manager. [] = all fine. */
    public static function problems($product) {
        $problems = [];
        if (!$product) return ['Το προϊόν δεν βρέθηκε.'];
        if (!$product->is_type('simple')) $problems[] = 'Το προϊόν δεν είναι «Απλό προϊόν» (Simple product).';
        if ($product->get_meta(self::ENABLED_KEY, true) !== 'yes') $problems[] = 'Δεν είναι τσεκαρισμένο το «Είναι συσκευασία δώρου» (καρτέλα «Συσκευασία δώρου»).';
        if ($product->get_price() === '') $problems[] = 'Δεν έχει κανονική τιμή (καρτέλα «Γενικά»). Χωρίς τιμή το WooCommerce δεν εμφανίζει το κουμπί/τη φόρμα αγοράς, άρα ούτε τις επιλογές.';
        elseif (!$product->is_purchasable()) $problems[] = 'Το WooCommerce δεν το θεωρεί διαθέσιμο για αγορά (κατάσταση δημοσίευσης ή άλλο plugin).';
        if (!$product->is_in_stock()) $problems[] = 'Είναι «Εξαντλημένο» (καρτέλα «Απόθεμα»). Τότε το WooCommerce δεν εμφανίζει τη φόρμα αγοράς.';
        if ($problems) return $problems;
        $config = self::config($product);
        if (!$config['slots']) return ['Δεν έχει αποθηκευμένες θέσεις με προϊόντα (καρτέλα «Συσκευασία δώρου» > «+ Προσθήκη θέσης», διαλέξτε προϊόντα, Ενημέρωση).'];
        foreach ($config['slots'] as $s => $slot) {
            $usable = array_filter($slot['products'], function ($id) { return (bool) self::option_product($id); });
            if (!$usable) $problems[] = 'Η θέση «' . ($slot['label'] ?: ($s + 1)) . '» δεν έχει κανένα προϊόν διαθέσιμο για αγορά (ελέγξτε τιμή/κατάσταση· σε προϊόντα με παραλλαγές διαλέξτε την παραλλαγή).';
        }
        if (!$problems && self::min_total($product) === null) $problems[] = 'Το κουτί δεν μπορεί να γεμίσει: τα διαθέσιμα (σε απόθεμα) προϊόντα δεν φτάνουν το ελάχιστο των θέσεων ή του κουτιού.';
        return $problems;
    }
    /** Shop managers only: a note on a box's product page when the box is missing or cannot be filled. */
    public static function diagnostics() {
        if (!is_product() || !current_user_can('manage_woocommerce')) return;
        $product = wc_get_product(get_queried_object_id());
        if (!$product) return;
        $configured = $product->get_meta(self::ENABLED_KEY, true) === 'yes' || $product->get_meta(self::SLOTS_KEY, true);
        if (!$configured) return;
        $problems = self::problems($product);
        if (!$problems && !isset(self::$rendered[$product->get_id()])) {
            $problems[] = 'Οι ρυθμίσεις είναι σωστές, αλλά το θέμα/page builder δεν εμφανίζει τη φόρμα «Προσθήκη στο καλάθι» του WooCommerce (hook woocommerce_before_add_to_cart_button). '
                . 'Βάλτε το shortcode [gift_box] στο πρότυπο της σελίδας προϊόντος, στη θέση του κουμπιού «Προσθήκη στο καλάθι».';
        }
        if (!$problems) return;
        echo '<div class="ggb-diagnostics" style="position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:720px;margin:auto;background:#fff;border:2px dashed #b32d2e;padding:12px 16px;font:14px/1.5 sans-serif;color:#1d2327;box-shadow:0 4px 16px rgba(0,0,0,.2)">';
        echo '<strong>Συσκευασία δώρου — δεν εμφανίζεται στον πελάτη</strong> <small>(το βλέπουν μόνο οι διαχειριστές)</small><ul style="margin:6px 0 0 18px">';
        foreach ($problems as $problem) echo '<li>' . esc_html($problem) . '</li>';
        echo '</ul><button type="button" onclick="this.parentNode.remove()" style="position:absolute;top:6px;right:8px;background:none;border:0;font-size:18px;cursor:pointer" aria-label="Κλείσιμο">×</button></div>';
    }
    private static function render_slot($s, $slot) {
        $mode = self::slot_mode($slot);
        $label = $slot['label'] !== '' ? $slot['label'] : sprintf(__('Produit %d', 'gift-box-bundles'), $s + 1);
        if ($mode === 'fixed') $hint = __('inclus', 'gift-box-bundles');
        elseif ($slot['min'] === $slot['max']) $hint = $slot['max'] === 1 ? '' : sprintf(__('choisissez %d', 'gift-box-bundles'), $slot['max']);
        elseif ($slot['min'] === 0) $hint = $slot['max'] === 1 ? __('facultatif', 'gift-box-bundles') : sprintf(__('jusqu\'à %d, facultatif', 'gift-box-bundles'), $slot['max']);
        else $hint = sprintf(__('de %1$d à %2$d', 'gift-box-bundles'), $slot['min'], $slot['max']);
        printf('<fieldset class="ggb-slot ggb-mode-%s" data-min="%d" data-max="%d" data-label="%s" data-repeat="%d">', esc_attr($mode), $slot['min'], $slot['max'], esc_attr($label), $slot['repeat'] ? 1 : 0);
        echo '<legend>' . esc_html($label) . ($hint !== '' ? ' <span class="ggb-hint">(' . esc_html($hint) . ')</span>' : '') . '</legend><div class="ggb-options">';
        $products = [];
        foreach ($slot['products'] as $id) {
            $p = self::option_product($id);
            if ($p) $products[$id] = $p;
        }
        if ($mode === 'select') {
            $options = '';
            foreach ($products as $id => $p) {
                $price = (float) wc_get_price_to_display($p);
                $in_stock = $p->is_in_stock();
                $image = $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_gallery_thumbnail') : wc_placeholder_img_src('woocommerce_gallery_thumbnail');
                $text = $p->get_name() . ' — ' . html_entity_decode(wp_strip_all_tags(wc_price($price)), ENT_QUOTES, 'UTF-8') . ($in_stock ? '' : ' (' . __('Épuisé', 'gift-box-bundles') . ')');
                $options .= '<option value="' . (int) $id . '" data-price="' . esc_attr($price) . '" data-img="' . esc_url($image) . '"' . ($in_stock ? '' : ' data-out="1" disabled') . '>' . esc_html($text) . '</option>';
            }
            for ($k = 0; $k < $slot['max']; $k++) {
                $placeholder = $k < $slot['min'] ? __('— Choisissez un produit —', 'gift-box-bundles') : __('— Aucun (facultatif) —', 'gift-box-bundles');
                $aria = $slot['max'] > 1 ? $label . ' ' . ($k + 1) : $label;
                echo '<div class="ggb-choice"><span class="ggb-thumb" aria-hidden="true"></span>';
                echo '<select class="ggb-select uk-select" name="ggb[' . (int) $s . '][]" aria-label="' . esc_attr($aria) . '"><option value="" data-price="0">' . esc_html($placeholder) . '</option>' . $options . '</select></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '</div></fieldset>';
            return;
        }
        foreach ($products as $id => $p) {
            $in_stock = $p->is_in_stock();
            $price = (float) wc_get_price_to_display($p);
            if ($mode === 'fixed') {
                $input = '<input type="hidden" name="ggb_qty[' . (int) $s . '][' . (int) $id . ']" value="' . (int) $slot['min'] . '" data-price="' . esc_attr($price) . '" class="ggb-fixed">';
            } else {
                // A lone optional product is in the box unless the customer takes it out.
                $input = '<input type="checkbox" class="uk-checkbox" name="ggb[' . (int) $s . '][]" value="' . (int) $id . '" data-price="' . esc_attr($price) . '"' . ($in_stock ? ' checked' : ' disabled') . '>';
            }
            $tag = $mode === 'fixed' ? 'div' : 'label';
            echo '<' . $tag . ' class="ggb-option' . ($in_stock ? '' : ' is-out') . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<span class="ggb-thumb">' . $p->get_image('woocommerce_gallery_thumbnail') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<span class="ggb-name">' . esc_html($p->get_name());
            if ($mode === 'fixed' && $slot['min'] > 1) echo ' × ' . (int) $slot['min'];
            echo '</span><span class="ggb-price">' . wc_price($price) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if (!$in_stock) echo '<span class="ggb-out">' . esc_html__('Épuisé', 'gift-box-bundles') . '</span>';
            echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</div></fieldset>';
    }
    /**
     * Lowest price at which the box can be bought: fee + the cheapest products that
     * meet every slot's minimum and the box's minimum. null if it cannot be filled.
     */
    public static function min_total($product) {
        $config = self::config($product);
        if (!$config || !$config['slots']) return null;
        $total = (float) wc_get_price_to_display($product);
        $count = 0;
        $pool = [];
        foreach ($config['slots'] as $slot) {
            $prices = [];
            foreach ($slot['products'] as $id) {
                $p = self::option_product($id);
                if ($p && $p->is_in_stock()) $prices[] = (float) wc_get_price_to_display($p);
            }
            sort($prices);
            if (!$prices) {
                if ($slot['min'] > 0) return null;
                continue;
            }
            if ($slot['repeat']) {
                $units = array_fill(0, $slot['max'], $prices[0]);
            } else {
                if (count($prices) < $slot['min']) return null;
                $units = array_slice($prices, 0, $slot['max']);
            }
            $total += array_sum(array_slice($units, 0, $slot['min']));
            $count += $slot['min'];
            $pool = array_merge($pool, array_slice($units, $slot['min']));
        }
        if ($config['min'] > $count) {
            sort($pool);
            $need = $config['min'] - $count;
            if (count($pool) < $need) return null;
            $total += array_sum(array_slice($pool, 0, $need));
        }
        return $total;
    }
    public static function price_html($html, $product) {
        if (!self::is_box($product)) return $html;
        $min = self::min_total($product);
        if ($min === null) return $html;
        return '<span class="ggb-from">' . esc_html__('À partir de', 'gift-box-bundles') . '</span> ' . wc_price($min);
    }
    public static function supports($supports, $feature, $product) {
        return $feature === 'ajax_add_to_cart' && self::is_box($product) ? false : $supports;
    }
    public static function add_to_cart_url($url, $product) {
        return self::is_box($product) ? $product->get_permalink() : $url;
    }
    public static function add_to_cart_text($text, $product) {
        return self::is_box($product) && !is_product() ? __('Composer mon coffret', 'gift-box-bundles') : $text;
    }

    /* ------------------------------------------------------------------
     * Selection
     * ------------------------------------------------------------------ */
    /** Posted choice per slot: [slot index => [product id => quantity]]. */
    private static function posted_selection($config) {
        $selection = [];
        $picked = isset($_POST['ggb']) && is_array($_POST['ggb']) ? wp_unslash($_POST['ggb']) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $qty = isset($_POST['ggb_qty']) && is_array($_POST['ggb_qty']) ? wp_unslash($_POST['ggb_qty']) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach (array_keys($config['slots']) as $s) {
            $selection[$s] = [];
            foreach ((isset($picked[$s]) && is_array($picked[$s]) ? $picked[$s] : []) as $id) {
                $id = is_scalar($id) ? absint($id) : 0;
                if ($id) $selection[$s][$id] = ($selection[$s][$id] ?? 0) + 1;
            }
            foreach ((isset($qty[$s]) && is_array($qty[$s]) ? $qty[$s] : []) as $id => $n) {
                $id = absint($id);
                $n = is_scalar($n) ? absint($n) : 0;
                if ($id && $n) $selection[$s][$id] = ($selection[$s][$id] ?? 0) + $n;
            }
        }
        return $selection;
    }
    /** Customer-facing problems with a selection, [] when it can be bought. */
    private static function selection_errors($config, $selection, $box_qty, $check_stock = true) {
        $errors = [];
        $total = 0;
        foreach ($config['slots'] as $s => $slot) {
            $label = $slot['label'] !== '' ? $slot['label'] : sprintf(__('Produit %d', 'gift-box-bundles'), $s + 1);
            $count = 0;
            foreach ($selection[$s] ?? [] as $id => $n) {
                $p = in_array($id, $slot['products'], true) ? self::option_product($id) : null;
                if (!$p) {
                    $errors[] = sprintf(__('« %s » : un produit choisi n\'est plus disponible.', 'gift-box-bundles'), $label);
                    continue;
                }
                if (!$slot['repeat'] && $n > 1) {
                    $errors[] = sprintf(__('« %s » : chaque produit ne peut être choisi qu\'une fois.', 'gift-box-bundles'), $label);
                }
                if ($check_stock && (!$p->is_in_stock() || !$p->has_enough_stock($n * $box_qty))) {
                    $errors[] = sprintf(__('« %s » n\'est pas disponible en quantité suffisante.', 'gift-box-bundles'), $p->get_name());
                }
                $count += $n;
            }
            if ($count < $slot['min']) $errors[] = sprintf(__('« %1$s » : choisissez au moins %2$d produit(s).', 'gift-box-bundles'), $label, $slot['min']);
            if ($count > $slot['max']) $errors[] = sprintf(__('« %1$s » : %2$d produit(s) maximum.', 'gift-box-bundles'), $label, $slot['max']);
            $total += $count;
        }
        if ($config['min'] && $total < $config['min']) $errors[] = sprintf(__('Votre coffret doit contenir au moins %d produits.', 'gift-box-bundles'), $config['min']);
        if ($config['max'] && $total > $config['max']) $errors[] = sprintf(__('Votre coffret peut contenir au maximum %d produits.', 'gift-box-bundles'), $config['max']);
        if (!$total) $errors[] = __('Votre coffret est vide.', 'gift-box-bundles');
        return array_values(array_unique($errors));
    }
    /** Selection as stored on the box's cart line: [['slot','label','id','qty'], ...]. */
    private static function contents($config, $selection) {
        $contents = [];
        foreach ($config['slots'] as $s => $slot) {
            foreach ($selection[$s] ?? [] as $id => $n) {
                $contents[] = ['slot' => $s, 'label' => $slot['label'], 'id' => (int) $id, 'qty' => (int) $n];
            }
        }
        return $contents;
    }
    /** The contents as a selection, for re-checking a cart line. */
    private static function selection_of($contents) {
        $selection = [];
        foreach ($contents as $row) $selection[$row['slot']][$row['id']] = ($selection[$row['slot']][$row['id']] ?? 0) + $row['qty'];
        return $selection;
    }

    /* ------------------------------------------------------------------
     * Cart
     * ------------------------------------------------------------------ */
    public static function validate($passed, $product_id, $quantity) {
        if (!$passed || !self::is_box($product_id)) return $passed;
        $config = self::config($product_id);
        if (!$config['slots'] || empty($_POST['ggb_box'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            wc_add_notice(__('Composez votre coffret sur sa page avant de l\'ajouter au panier.', 'gift-box-bundles'), 'error');
            return false;
        }
        $errors = self::selection_errors($config, self::posted_selection($config), max(1, (int) $quantity));
        foreach ($errors as $error) wc_add_notice($error, 'error');
        return !$errors;
    }
    public static function cart_item_data($data, $product_id) {
        if (!self::is_box($product_id) || empty($_POST['ggb_box'])) return $data; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $config = self::config($product_id);
        $data['ggb_contents'] = self::contents($config, self::posted_selection($config));
        // Every box is its own cart line, so its products stay attached to it.
        $data['ggb_uid'] = wp_generate_uuid4();
        return $data;
    }
    /** Adds the chosen products right after the box. Any failure removes the whole box. */
    public static function add_children($key, $product_id, $quantity, $variation_id, $variation, $data) {
        if (empty($data['ggb_contents']) || !empty($data['ggb_parent'])) return;
        $cart = WC()->cart;
        $added = [];
        foreach ($data['ggb_contents'] as $row) {
            $p = wc_get_product($row['id']);
            // The slot index keeps the same product chosen in two slots (e.g. the same olive
            // jar as 2nd and 3rd product) on two lines; WooCommerce would merge them otherwise.
            $child = ['ggb_parent' => $key, 'ggb_slot' => $row['slot'], 'ggb_per_box' => $row['qty'], 'ggb_label' => $row['label']];
            $child_key = false;
            if ($p) {
                $child_key = $p->is_type('variation')
                    ? $cart->add_to_cart($p->get_parent_id(), $row['qty'] * $quantity, $p->get_id(), $p->get_variation_attributes(), $child)
                    : $cart->add_to_cart($p->get_id(), $row['qty'] * $quantity, 0, [], $child);
            }
            if (!$child_key) {
                self::$removing = true;
                foreach ($added as $k) $cart->remove_cart_item($k);
                $cart->remove_cart_item($key);
                self::$removing = false;
                // Caught by WC_Cart::add_to_cart(): shown as an error and the add fails.
                throw new Exception(__('Le coffret n\'a pas pu être ajouté : un des produits choisis n\'est pas disponible.', 'gift-box-bundles'));
            }
            $added[] = $child_key;
        }
    }
    private static function children_keys($cart, $parent_key) {
        $keys = [];
        foreach ($cart->get_cart() as $key => $item) {
            if (($item['ggb_parent'] ?? '') === $parent_key) $keys[] = $key;
        }
        return $keys;
    }
    public static function sync_quantity($key, $quantity, $old, $cart) {
        $item = $cart->get_cart_item($key);
        if (empty($item['ggb_contents'])) return;
        foreach (self::children_keys($cart, $key) as $child_key) {
            $child = $cart->get_cart_item($child_key);
            $cart->set_quantity($child_key, max(1, (int) $child['ggb_per_box']) * $quantity, false);
        }
    }
    /** Removing a box removes its products; removing one of its products removes the box. */
    public static function remove_linked($key, $cart) {
        if (self::$removing) return;
        $item = $cart->get_cart_item($key);
        if (!$item) return;
        $parent = !empty($item['ggb_contents']) ? $key : ($item['ggb_parent'] ?? '');
        if ($parent === '') return;
        self::$removing = true;
        foreach (self::children_keys($cart, $parent) as $child_key) {
            if ($child_key !== $key) $cart->remove_cart_item($child_key);
        }
        if ($parent !== $key) $cart->remove_cart_item($parent);
        self::$removing = false;
    }
    /** "Annuler ?" after removing a box brings its products back too. */
    public static function restore_children($key, $cart) {
        $item = $cart->get_cart_item($key);
        if (empty($item['ggb_contents'])) return;
        foreach ($cart->get_removed_cart_contents() as $removed_key => $removed) {
            if (($removed['ggb_parent'] ?? '') === $key) $cart->restore_cart_item($removed_key);
        }
    }
    /**
     * Drops what cannot be a complete box: a box without its products (e.g. "order again",
     * which copies the lines but not the link) and products whose box is gone.
     */
    public static function repair_cart($cart) {
        $contents = $cart->get_cart_contents();
        $changed = false;
        foreach ($contents as $key => $item) {
            $broken = false;
            if (!empty($item['ggb_parent'])) {
                $broken = !isset($contents[$item['ggb_parent']]);
            } elseif (self::is_box($item['data'] ?? null)) {
                $expected = count($item['ggb_contents'] ?? []);
                $found = 0;
                foreach ($contents as $other) if (($other['ggb_parent'] ?? '') === $key) $found++;
                $broken = !$expected || $found !== $expected;
            }
            if ($broken) {
                unset($contents[$key]);
                $changed = true;
            }
        }
        if (!$changed) return;
        // A second pass for the products of a box removed above.
        foreach ($contents as $key => $item) {
            if (!empty($item['ggb_parent']) && !isset($contents[$item['ggb_parent']])) unset($contents[$key]);
        }
        $cart->set_cart_contents($contents);
        wc_add_notice(__('Un coffret incomplet a été retiré de votre panier. Merci de le composer à nouveau.', 'gift-box-bundles'), 'notice');
    }
    /** A box whose composition the shop has since changed cannot be ordered as it is. */
    public static function check_cart() {
        $cart = WC()->cart;
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['ggb_contents'])) continue;
            $config = self::config($item['data']);
            // Stock is checked by WooCommerce on each product line.
            if (!$config || self::selection_errors($config, self::selection_of($item['ggb_contents']), $item['quantity'], false)) {
                wc_add_notice(sprintf(__('La composition du coffret « %s » n\'est plus disponible. Retirez-le du panier et composez-le à nouveau.', 'gift-box-bundles'), $item['data']->get_name()), 'error');
            }
        }
    }
    public static function child_remove_link($link, $key) {
        $item = WC()->cart ? WC()->cart->get_cart_item($key) : null;
        return !empty($item['ggb_parent']) ? '' : $link;
    }
    public static function child_quantity($html, $key, $item = null) {
        if ($item === null) $item = WC()->cart->get_cart_item($key);
        return !empty($item['ggb_parent']) ? '<span class="ggb-child-qty">' . (int) $item['quantity'] . '</span>' : $html;
    }
    public static function cart_item_class($class, $item, $key) {
        if (!empty($item['ggb_parent'])) return $class . ' ggb-child';
        if (!empty($item['ggb_contents'])) return $class . ' ggb-parent';
        return $class;
    }
    public static function item_data($data, $item) {
        if (!empty($item['ggb_parent'])) {
            $parent = WC()->cart ? WC()->cart->get_cart_item($item['ggb_parent']) : null;
            if ($parent) $data[] = ['key' => __('Coffret', 'gift-box-bundles'), 'value' => $parent['data']->get_name()];
        } elseif (!empty($item['ggb_contents']) && WC()->cart) {
            // Price of one complete box: the fee plus its products.
            $total = (float) wc_get_price_to_display($item['data']);
            foreach (self::children_keys(WC()->cart, $item['key'] ?? '') as $child_key) {
                $child = WC()->cart->get_cart_item($child_key);
                $total += (float) wc_get_price_to_display($child['data']) * max(1, (int) $child['ggb_per_box']);
            }
            $data[] = ['key' => __('Prix du coffret complet', 'gift-box-bundles'), 'value' => wp_strip_all_tags(wc_price($total))];
        }
        return $data;
    }

    /* ------------------------------------------------------------------
     * Order
     * ------------------------------------------------------------------ */
    public static function order_item_meta($order_item, $key, $values, $order) {
        if (!empty($values['ggb_parent'])) {
            $parent = WC()->cart ? WC()->cart->get_cart_item($values['ggb_parent']) : null;
            if ($parent) {
                $order_item->add_meta_data(__('Coffret', 'gift-box-bundles'), $parent['data']->get_name(), true);
                $order_item->add_meta_data('_ggb_uid', $parent['ggb_uid'] ?? '', true);
            }
        } elseif (!empty($values['ggb_contents'])) {
            $lines = [];
            foreach ($values['ggb_contents'] as $row) {
                $p = wc_get_product($row['id']);
                $lines[] = $row['qty'] . ' × ' . ($p ? $p->get_name() : '#' . $row['id']);
            }
            $order_item->add_meta_data(__('Contenu', 'gift-box-bundles'), implode(', ', $lines), true);
            $order_item->add_meta_data('_ggb_uid', $values['ggb_uid'] ?? '', true);
            $order_item->add_meta_data('_ggb_box', 'yes', true);
        }
    }
    public static function order_item_class($class, $item) {
        if (!is_object($item) || !method_exists($item, 'get_meta')) return $class;
        if ($item->get_meta('_ggb_box')) return $class . ' ggb-parent';
        if ($item->get_meta('_ggb_uid')) return $class . ' ggb-child';
        return $class;
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) GGB_Gift_Box::init();
});
