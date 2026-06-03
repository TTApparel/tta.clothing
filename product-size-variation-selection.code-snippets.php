<?php

/**
 * PRODUCT SIZE VARIATION SELECTION
 */

/**
 * AJAX handler for the wholesale matrix multi-variation add-to-cart request.
 */
if (!function_exists('tta_add_multi_variations_to_cart')) {
    function tta_add_multi_variations_to_cart() {
        if (!function_exists('WC') || !function_exists('wc_get_product')) {
            wp_send_json_error(['message' => 'WooCommerce is not available.'], 500);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Please log in to order.'], 401);
        }

        if (!check_ajax_referer('tta_add_multi_variations', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh the page and try again.'], 403);
        }

        if (null === WC()->cart && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (null === WC()->cart) {
            wp_send_json_error(['message' => 'WooCommerce cart is not available.'], 500);
        }

        $product_id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $items_raw  = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items      = json_decode($items_raw, true);

        if (!$product_id || !is_array($items) || empty($items)) {
            wp_send_json_error(['message' => 'No valid variations were submitted.'], 400);
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error(['message' => 'This product is not available for matrix ordering.'], 400);
        }

        $added_count = 0;
        $errors      = [];

        foreach ($items as $item) {
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            $quantity     = isset($item['quantity']) ? wc_stock_amount($item['quantity']) : 0;

            if (!$variation_id || $quantity <= 0) {
                continue;
            }

            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation') || (int) $variation->get_parent_id() !== $product_id) {
                $errors[] = 'One selected variation is no longer available.';
                continue;
            }

            if (!$variation->is_purchasable() || !$variation->is_in_stock()) {
                $errors[] = sprintf('%s is not available to purchase.', $variation->get_name());
                continue;
            }

            $variation_data = [];
            if (isset($item['attributes']) && is_array($item['attributes'])) {
                foreach ($item['attributes'] as $key => $value) {
                    $attribute_key = sanitize_key($key);
                    if (0 !== strpos($attribute_key, 'attribute_')) {
                        continue;
                    }

                    $variation_data[$attribute_key] = sanitize_title(wp_unslash($value));
                }
            }

            if (empty($variation_data)) {
                foreach ($variation->get_variation_attributes() as $key => $value) {
                    $variation_data[sanitize_key($key)] = sanitize_title($value);
                }
            }

            $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_data);
            if ($cart_item_key) {
                $added_count += $quantity;
            } else {
                $errors[] = sprintf('%s could not be added to the cart.', $variation->get_name());
            }
        }

        if (!$added_count) {
            wp_send_json_error([
                'message' => !empty($errors) ? implode(' ', array_unique($errors)) : 'Could not add to cart.',
            ], 400);
        }

        wp_send_json_success([
            'message'     => sprintf(_n('%d item added to cart.', '%d items added to cart.', $added_count, 'woocommerce'), $added_count),
            'added_count' => $added_count,
            'errors'      => array_values(array_unique($errors)),
        ]);
    }
}

add_action('wp_ajax_tta_add_multi_variations', 'tta_add_multi_variations_to_cart');
add_action('wp_ajax_nopriv_tta_add_multi_variations', 'tta_add_multi_variations_to_cart');

/**
 * [tta_wholesale_color_size_matrix color_tax="pa_color" size_tax="pa_size"]
 * Combined SSP swatches + size matrix + multi-add-to-cart + "All colors" accordion mode.
 */
add_shortcode('tta_wholesale_color_size_matrix', function($atts){

    if (!function_exists('wc_get_product')) return '';

    $atts = shortcode_atts([
        'color_tax' => 'pa_color',
        'size_tax'  => 'pa_size',
        'sw_w'      => 53,
        'sw_h'      => 39,
        'sw_gap'    => 6,
        'sw_radius' => 0,
        'tile_w'    => 100,
    ], $atts);

    $product = wc_get_product(get_the_ID());
    if (!$product || !$product->is_type('variable')) return '';

    $product_id = $product->get_id();
    $color_tax = sanitize_text_field($atts['color_tax']);
    $size_tax  = sanitize_text_field($atts['size_tax']);

    $color_terms = wc_get_product_terms($product_id, $color_tax, ['fields' => 'all']);
    $size_terms  = wc_get_product_terms($product_id, $size_tax,  ['fields' => 'all']);
    if (empty($color_terms) || empty($size_terms) || is_wp_error($color_terms) || is_wp_error($size_terms)) return '';

    $variations = $product->get_available_variations();

    // Only list colors that exist in variations
    $valid_colors = [];
    foreach ($variations as $v) {
        $c = $v['attributes']['attribute_'.$color_tax] ?? '';
        if ($c) $valid_colors[$c] = true;
    }
    if (empty($valid_colors)) return '';

    // ---------- Helpers: image extraction from SSP meta ----------
    $find_image = function($data) use (&$find_image) {
        if (is_numeric($data)) {
            $url = wp_get_attachment_url((int)$data);
            if ($url) return $url;
        }
        if (is_string($data)) {
            $s = trim($data);
            if (preg_match('#^https?://#i', $s)) return $s;
            if (preg_match('#https?://[^)\'"\s]+#i', $s, $m)) return $m[0];
        }
        if (is_array($data)) {
            $preferred_keys = ['image', 'image_url', 'img', 'url', 'src', 'swatch', 'swatch_image', 'attachment_id', 'id'];
            foreach ($preferred_keys as $k) {
                if (isset($data[$k])) {
                    $found = $find_image($data[$k]);
                    if ($found) return $found;
                }
            }
            foreach ($data as $v) {
                $found = $find_image($v);
                if ($found) return $found;
            }
        }
        if (is_object($data)) return $find_image((array)$data);
        return '';
    };

    $resolve_ssp_image_url = function($term_id) use ($find_image) {
        $raw = get_term_meta($term_id, 'ssp_attribute_options_pa_color', true);
        if (!$raw) return '';
        $data = $raw;
        if (is_string($raw)) {
            $maybe = maybe_unserialize($raw);
            if ($maybe !== $raw) $data = $maybe;
        }
        return $find_image($data);
    };

    $uid = 'tta-wholesale-matrix-' . wp_generate_uuid4();
    $variations_json = wp_json_encode($variations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>"
         class="tta-wm"
         data-product-id="<?php echo esc_attr($product_id); ?>"
         data-color-tax="<?php echo esc_attr($color_tax); ?>"
         data-size-tax="<?php echo esc_attr($size_tax); ?>"
         data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('tta_add_multi_variations')); ?>"
         data-logged-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>"
    >
        <script type="application/json" class="tta-wm-variations"><?php echo $variations_json; ?></script>

        <div class="tta-wm-top">
            <div class="tta-wm-swatches" role="list" aria-label="Colors">
                <?php foreach ($color_terms as $term):
                    if (!isset($valid_colors[$term->slug])) continue;

                    $img_url = $resolve_ssp_image_url($term->term_id);
                    $bg = $img_url
                        ? "background-image:url('".esc_url($img_url)."');background-size:cover;background-position:center;"
                        : "background:#dddddd;";
                ?>
                    <button type="button"
                            class="tta-wm-swatch"
                            role="listitem"
                            data-color="<?php echo esc_attr($term->slug); ?>"
                            data-name="<?php echo esc_attr($term->name); ?>"
                            data-img="<?php echo esc_attr($img_url ?: ''); ?>"
                            aria-label="<?php echo esc_attr($term->name); ?>">
                        <span class="tta-wm-swatch-chip" style="<?php echo esc_attr($bg); ?>"></span>
                        <span class="tta-wm-tooltip" aria-hidden="true"><?php echo esc_html($term->name); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tta-wm-row">
            <div class="tta-wm-left">
                <div class="tta-wm-color-name">—</div>

                <button type="button" class="tta-wm-toggle-all">View all colors</button>

                <button type="button" class="tta-wm-addbtn">Add to cart</button>
                <div class="tta-wm-msg" aria-live="polite"></div>
            </div>

            <!-- Single-color grid (default) -->
            <div class="tta-wm-grid" role="table" aria-label="Size matrix (single color)">
                <?php foreach ($size_terms as $s): ?>
                    <div class="tta-wm-col" data-size="<?php echo esc_attr($s->slug); ?>">
                        <div class="tta-wm-size"><?php echo esc_html($s->name); ?></div>
                        <div class="tta-wm-qtyline">Qty: <b class="tta-wm-caseqty">—</b></div>
                        <div class="tta-wm-price">—</div>
                        <input class="tta-wm-input" type="number" min="0" step="1" placeholder="0" inputmode="numeric" />
                    </div>
                <?php endforeach; ?>
            </div>
			<!-- All-colors accordion (hidden until toggled) -->
        		<div class="tta-wm-allwrap" style="display:none;">
            	<div class="tta-wm-allgrid"></div>
        	</div>
        </div>
    </div>

    <style>
        /* Swatches */
        #<?php echo $uid; ?> .tta-wm-top { margin-bottom: 12px; }
        #<?php echo $uid; ?> .tta-wm-swatches{
            display:flex; flex-wrap:wrap; gap:<?php echo (int)$atts['sw_gap']; ?>px; align-items:center;
        }
        #<?php echo $uid; ?> .tta-wm-swatch{
            position:relative;
            width:<?php echo (int)$atts['sw_w']; ?>px;
            height:<?php echo (int)$atts['sw_h']; ?>px;
            padding:0; margin:0;
            border:1px solid rgba(0,0,0,0.18);
            border-radius:<?php echo (int)$atts['sw_radius']; ?>px;
            overflow:visible;
            background:transparent;
            cursor:pointer;
            line-height:0;
        }
        #<?php echo $uid; ?> .tta-wm-swatch-chip{
            display:block; width:100%; height:100%;
            border-radius:<?php echo (int)$atts['sw_radius']; ?>px;
            overflow:hidden;
        }
        #<?php echo $uid; ?> .tta-wm-swatch.is-selected{
            border-color: rgba(0,0,0,0.85);
            box-shadow: 0 0 0 2px rgba(0,0,0,0.15);
        }
        #<?php echo $uid; ?> .tta-wm-tooltip{
            position:absolute;
            left:50%;
            bottom:calc(100% + 10px);
            transform:translateX(-50%);
            background:rgba(0,0,0,0.9);
            color:#fff;
            padding:10px 14px;
            font-size:0.9375rem;
            font-family:'Poppins', sans-serif;
            font-weight:500;
            letter-spacing:0.02em;
            border-radius:10px;
            white-space:nowrap;
            opacity:0;
            pointer-events:none;
            transition:opacity 0.15s ease;
            z-index:9999;
        }
        #<?php echo $uid; ?> .tta-wm-swatch:hover .tta-wm-tooltip,
        #<?php echo $uid; ?> .tta-wm-swatch:focus .tta-wm-tooltip,
        #<?php echo $uid; ?> .tta-wm-swatch:focus-visible .tta-wm-tooltip{ opacity:1; }

        /* Matrix */
        #<?php echo $uid; ?> .tta-wm-row { display:flex; gap:14px; align-items:flex-start; }
        #<?php echo $uid; ?> .tta-wm-left { width:170px; flex:0 0 170px; display:flex; flex-direction:column; gap:10px; }
        #<?php echo $uid; ?> .tta-wm-color-name{
            font-family:'Poppins', sans-serif;
            font-size:0.9375rem;
            font-weight:600;
            line-height:1.2;
        }
        #<?php echo $uid; ?> .tta-wm-toggle-all,
        #<?php echo $uid; ?> .tta-wm-addbtn{
            font-family:'Poppins', sans-serif;
            font-size:0.9375rem;
            font-weight:600;
            padding:10px 12px;
            border-radius:10px;
            border:1px solid rgba(0,0,0,0.2);
            background:#fff;
            cursor:pointer;
        }
        #<?php echo $uid; ?> .tta-wm-toggle-all.is-on{
            border-color: rgba(0,0,0,0.75);
            box-shadow: 0 0 0 2px rgba(0,0,0,0.10);
        }
        #<?php echo $uid; ?> .tta-wm-addbtn:disabled{ opacity:.5; cursor:not-allowed; }
        #<?php echo $uid; ?> .tta-wm-msg{ font-family:'Poppins', sans-serif; font-size:0.875rem; opacity:.85; min-height:18px; }

        #<?php echo $uid; ?> .tta-wm-grid{ display:flex; gap:10px; flex-wrap:wrap; align-items:stretch; }
        #<?php echo $uid; ?> .tta-wm-col{
            width:<?php echo (int)$atts['tile_w']; ?>px;
            border:1px solid rgba(0,0,0,0.15);
            border-radius:12px;
            padding:5px;
            display:flex;
            flex-direction:column;
            gap:8px;
            background:#fff;
        }
        #<?php echo $uid; ?> .tta-wm-size{ font-family:'Poppins', sans-serif; font-size:0.9375rem; font-weight:600; }
        #<?php echo $uid; ?> .tta-wm-qtyline{ font-family:'Poppins', sans-serif; font-size:0.875rem; }
        #<?php echo $uid; ?> .tta-wm-qtyline b{ font-weight:600; }
        #<?php echo $uid; ?> .tta-wm-price{ font-family:'Poppins', sans-serif; font-size:0.875rem; }
        #<?php echo $uid; ?> .tta-wm-price .price{ margin:0; }
        #<?php echo $uid; ?> .tta-wm-input{
            width:100%;
            font-family:'Poppins', sans-serif;
            font-size:0.9375rem;
            padding:9px 10px;
            border-radius:10px;
            border:1px solid rgba(0,0,0,0.2);
            outline:none;
        }
        #<?php echo $uid; ?> .is-disabled{ opacity:.45; }
        #<?php echo $uid; ?> .is-disabled .tta-wm-input{ cursor:not-allowed; }

        /* All colors accordion */
        #<?php echo $uid; ?> .tta-wm-allwrap{ margin-top: 14px; }
        #<?php echo $uid; ?> .tta-wm-colorblock{
            border:1px solid rgba(0,0,0,0.12);
            border-radius:14px;
            background:#fff;
            overflow:hidden;
            margin-bottom:12px;
        }
        #<?php echo $uid; ?> .tta-wm-accbtn{
            width:100%;
            text-align:left;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:12px 12px;
            border:0;
            background:#fff;
            cursor:pointer;
        }
        #<?php echo $uid; ?> .tta-wm-accleft{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:0;
        }
        #<?php echo $uid; ?> .tta-wm-accswatch{
            width:26px;
            height:18px;
            border-radius:6px;
            border:1px solid rgba(0,0,0,0.12);
            background:#ddd;
            flex:0 0 auto;
            background-size:cover;
            background-position:center;
        }
        #<?php echo $uid; ?> .tta-wm-accname{
            font-family:'Poppins', sans-serif;
            font-size:0.9375rem;
            font-weight:700;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        #<?php echo $uid; ?> .tta-wm-chevron{
            width:10px; height:10px;
            border-right:2px solid rgba(0,0,0,0.55);
            border-bottom:2px solid rgba(0,0,0,0.55);
            transform: rotate(45deg);
            transition: transform .15s ease;
            flex:0 0 auto;
            margin-left: 10px;
        }
        #<?php echo $uid; ?> .tta-wm-colorblock.is-open .tta-wm-chevron{
            transform: rotate(-135deg);
        }
        #<?php echo $uid; ?> .tta-wm-acccontent{
            display:none;
            padding: 0 12px 12px;
        }
        #<?php echo $uid; ?> .tta-wm-colorblock.is-open .tta-wm-acccontent{ display:block; }
        #<?php echo $uid; ?> .tta-wm-colorgrid{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:stretch;
        }

        /* Mobile layout */
        @media (max-width: 768px){
            #<?php echo $uid; ?> .tta-wm-row{
                flex-direction: column;
                align-items: stretch;
            }
            #<?php echo $uid; ?> .tta-wm-left{
                width: 100% !important;
                flex: 0 0 auto !important;
            }
            #<?php echo $uid; ?> .tta-wm-left .tta-wm-addbtn,
            #<?php echo $uid; ?> .tta-wm-left .tta-wm-toggle-all{
                width: 100%;
            }
            #<?php echo $uid; ?> .tta-wm-grid{
                width: 100%;
                margin-top: 10px;
                justify-content: center;
            }
            #<?php echo $uid; ?> .tta-wm-colorgrid{
                justify-content: center;
            }
        }

        /* Guest rules */
        #<?php echo $uid; ?>[data-logged-in="0"] .tta-wm-price{
            display: none !important;
        }
        #<?php echo $uid; ?>[data-logged-in="0"] .tta-wm-addbtn{
            opacity: 0.6;
        }
		/* Right column container for all-colors view */
		#<?php echo $uid; ?> .tta-wm-allwrap{
  			flex: 1 1 auto;
  			min-width: 0;
		}
		/* Ensure accordion takes full available width on desktop */
		#<?php echo $uid; ?> .tta-wm-allgrid{
  		width: 100%;
		}	
    </style>

    <script>
    (function(){
        const root = document.getElementById('<?php echo $uid; ?>');
        if(!root) return;

        const isLoggedIn = root.getAttribute('data-logged-in') === '1';
        const productId  = root.getAttribute('data-product-id');
        const colorTax   = root.getAttribute('data-color-tax');
        const sizeTax    = root.getAttribute('data-size-tax');
        const ajaxUrl    = root.getAttribute('data-ajax-url');
        const nonce      = root.getAttribute('data-nonce');

        const variationsEl = root.querySelector('.tta-wm-variations');
        let variations = [];
        try { variations = JSON.parse(variationsEl?.textContent || '[]'); } catch(e){ variations = []; }

        const swatches     = Array.from(root.querySelectorAll('.tta-wm-swatch'));
        const cols         = Array.from(root.querySelectorAll('.tta-wm-grid .tta-wm-col'));
        const colorNameEl  = root.querySelector('.tta-wm-color-name');
        const addBtn       = root.querySelector('.tta-wm-addbtn');
        const msgEl        = root.querySelector('.tta-wm-msg');

        const toggleAllBtn = root.querySelector('.tta-wm-toggle-all');
        const singleGrid   = root.querySelector('.tta-wm-grid');
        const allWrap      = root.querySelector('.tta-wm-allwrap');
        const allGrid      = root.querySelector('.tta-wm-allgrid');

        let selectedColor = '';
        let allMode = false;

        function setMsg(t){ msgEl.textContent = t || ''; }

        function setSelectedSwatch(slug){
            swatches.forEach(s => s.classList.toggle('is-selected', s.getAttribute('data-color') === slug));
        }

        function findVariation(colorSlug, sizeSlug){
            return variations.find(v => {
                const a = v.attributes || {};
                return a['attribute_'+colorTax] === colorSlug && a['attribute_'+sizeTax] === sizeSlug;
            });
        }

        function getColorName(slug){
            const sw = swatches.find(s => s.getAttribute('data-color') === slug);
            return sw ? (sw.getAttribute('data-name') || slug) : slug;
        }

        function getColorImg(slug){
            const sw = swatches.find(s => s.getAttribute('data-color') === slug);
            return sw ? (sw.getAttribute('data-img') || '') : '';
        }

        function refreshSingle(){
            if(allMode) return; // don't touch single UI while all-mode is shown

            const active = swatches.find(s => s.getAttribute('data-color') === selectedColor);
            const name = active ? (active.getAttribute('data-name') || '') : '';
            colorNameEl.textContent = name || 'Select a color';

            let anyAddable = false;

            cols.forEach(col => {
                const sizeSlug = col.getAttribute('data-size');
                const v = (selectedColor && sizeSlug) ? findVariation(selectedColor, sizeSlug) : null;

                const qtyEl = col.querySelector('.tta-wm-caseqty');
                const priceEl = col.querySelector('.tta-wm-price');
                const input = col.querySelector('.tta-wm-input');

                col.classList.remove('is-disabled');
                input.disabled = false;
                input.max = '';

                if(!v){
                    qtyEl.textContent = '—';
                    priceEl.textContent = '—';
                    input.value = '';
                    input.disabled = true;
                    col.classList.add('is-disabled');
                    return;
                }

                const inStock = !!v.is_in_stock;
                const maxQty  = (typeof v.max_qty !== 'undefined') ? v.max_qty : '';
                const stockQty = (typeof v.stock_quantity !== 'undefined') ? v.stock_quantity : '';

                let displayQty = '—';
                if(!inStock) displayQty = '0';
                else if(stockQty !== '' && stockQty !== null) displayQty = String(stockQty);
                else if(maxQty !== '' && maxQty !== null && maxQty !== 999999) displayQty = String(maxQty);
                else displayQty = 'In stock';

                qtyEl.textContent = displayQty;

                if(v.price_html) priceEl.innerHTML = v.price_html;
                else if(typeof v.display_price !== 'undefined') priceEl.textContent = '$' + v.display_price;
                else priceEl.textContent = '—';

                if(!inStock){
                    input.value = '';
                    input.disabled = true;
                    col.classList.add('is-disabled');
                    return;
                }

                if(maxQty && maxQty !== 999999) input.max = maxQty;
                anyAddable = true;
            });

            addBtn.disabled = !(selectedColor && anyAddable);
            setMsg('');
        }

        function buildAllColorsAccordion(){
            if(!allGrid) return;
            allGrid.innerHTML = '';

            const sizeSlugs = cols.map(c => c.getAttribute('data-size')).filter(Boolean);

            // preserve swatch order
            const colorSlugs = swatches.map(s => s.getAttribute('data-color')).filter(Boolean);

            colorSlugs.forEach(colorSlug => {
                const block = document.createElement('div');
                block.className = 'tta-wm-colorblock';
                block.setAttribute('data-color', colorSlug);

                const img = getColorImg(colorSlug);
                const name = getColorName(colorSlug);

                const header = document.createElement('button');
                header.type = 'button';
                header.className = 'tta-wm-accbtn';
                header.innerHTML = `
                  <span class="tta-wm-accleft">
                    <span class="tta-wm-accswatch" style="${img ? `background-image:url('${img.replace(/'/g, "\\'")}')` : ''}"></span>
                    <span class="tta-wm-accname">${name}</span>
                  </span>
                  <span class="tta-wm-chevron" aria-hidden="true"></span>
                `;

                const content = document.createElement('div');
                content.className = 'tta-wm-acccontent';

                const grid = document.createElement('div');
                grid.className = 'tta-wm-colorgrid';

                sizeSlugs.forEach(sizeSlug => {
                    const v = findVariation(colorSlug, sizeSlug);
                    const inStock = v ? !!v.is_in_stock : false;

                    const card = document.createElement('div');
                    card.className = 'tta-wm-col';
                    card.setAttribute('data-color', colorSlug);
                    card.setAttribute('data-size', sizeSlug);

                    // qty display
                    let displayQty = '—';
                    if(!v) displayQty = '—';
                    else if(!inStock) displayQty = '0';
                    else if(typeof v.stock_quantity !== 'undefined' && v.stock_quantity !== null) displayQty = String(v.stock_quantity);
                    else if(typeof v.max_qty !== 'undefined' && v.max_qty !== null && v.max_qty !== 999999) displayQty = String(v.max_qty);
                    else displayQty = 'In stock';

                    const priceHTML =
                      v && v.price_html ? v.price_html :
                      (v && typeof v.display_price !== 'undefined' ? ('$' + v.display_price) : '—');

                    const sizeLabel = (sizeSlug || '').toUpperCase();

                    card.innerHTML = `
                      <div class="tta-wm-size">${sizeLabel}</div>
                      <div class="tta-wm-qtyline">Qty: <b class="tta-wm-caseqty">${displayQty}</b></div>
                      <div class="tta-wm-price">${priceHTML}</div>
                      <input class="tta-wm-input" type="number" min="0" step="1" placeholder="0" inputmode="numeric" ${(!v || !inStock) ? 'disabled' : ''}/>
                    `;

                    if(!v || !inStock) card.classList.add('is-disabled');
                    grid.appendChild(card);
                });

                content.appendChild(grid);
                block.appendChild(header);
                block.appendChild(content);

                // Accordion behavior (one click toggles this color)
                header.addEventListener('click', () => {
                    block.classList.toggle('is-open');
                });

                allGrid.appendChild(block);
            });
        }

        function getAllModeItems(){
            const items = [];
            if(!allGrid) return items;

            const cards = Array.from(allGrid.querySelectorAll('.tta-wm-col'));
            cards.forEach(card => {
                const colorSlug = card.getAttribute('data-color');
                const sizeSlug  = card.getAttribute('data-size');
                const input     = card.querySelector('.tta-wm-input');
                if(!input || input.disabled) return;

                const qty = parseInt(input.value || '0', 10);
                if(!qty || qty <= 0) return;

                const v = findVariation(colorSlug, sizeSlug);
                if(!v || !v.variation_id) return;

                items.push({ variation_id: v.variation_id, quantity: qty, attributes: v.attributes || {} });
            });

            return items;
        }

        async function addToCart(){
            if(!isLoggedIn){
                setMsg('Please log in to order');
                return;
            }

            const items = allMode ? getAllModeItems() : (function(){
                if(!selectedColor) return [];
                const arr = [];
                cols.forEach(col => {
                    const sizeSlug = col.getAttribute('data-size');
                    const input = col.querySelector('.tta-wm-input');
                    if(!input || input.disabled) return;

                    const qty = parseInt(input.value || '0', 10);
                    if(!qty || qty <= 0) return;

                    const v = findVariation(selectedColor, sizeSlug);
                    if(!v || !v.variation_id) return;

                    arr.push({ variation_id: v.variation_id, quantity: qty, attributes: v.attributes || {} });
                });
                return arr;
            })();

            if(items.length === 0){
                setMsg(allMode ? 'Enter quantities for any colors/sizes.' : 'Enter a quantity for at least one size.');
                return;
            }

            addBtn.disabled = true;
            setMsg('Adding to cart…');

            try{
                const res = await fetch(ajaxUrl, {
                    method:'POST',
                    headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        action: 'tta_add_multi_variations',
                        nonce: nonce,
                        product_id: productId,
                        items: JSON.stringify(items)
                    })
                });

                const responseText = await res.text();
                let data = null;
                try { data = responseText ? JSON.parse(responseText) : null; } catch(e) { data = null; }

                if(!res.ok || !data || !data.success){
                    const serverMessage = data && data.data && data.data.message;
                    setMsg(serverMessage || (responseText && responseText !== '0' ? responseText : 'Could not add to cart.'));
                    addBtn.disabled = false;
                    return;
                }

                setMsg((data.data && data.data.message) ? data.data.message : 'Added to cart!');

                if(allMode && allGrid){
                    allGrid.querySelectorAll('.tta-wm-input').forEach(i => { if(!i.disabled) i.value = ''; });
                }else{
                    cols.forEach(col => {
                        const input = col.querySelector('.tta-wm-input');
                        if(input && !input.disabled) input.value = '';
                    });
                }

                if(window.jQuery){
                    window.jQuery(document.body).trigger('wc_fragment_refresh');
                }else if(document.body){
                    document.body.dispatchEvent(new Event('wc_fragment_refresh'));
                }
                addBtn.disabled = false;

            } catch(e){
                setMsg('Network error while adding to cart.');
                addBtn.disabled = false;
            }
        }

        // Swatch click (single-color mode only)
        swatches.forEach(sw => {
            sw.addEventListener('click', () => {
                if(allMode) return;
                selectedColor = sw.getAttribute('data-color') || '';
                setSelectedSwatch(selectedColor);
                refreshSingle();
            });
        });

        addBtn.addEventListener('click', addToCart);

        // Toggle All Colors mode
        if(toggleAllBtn){
            toggleAllBtn.addEventListener('click', () => {
                allMode = !allMode;

                toggleAllBtn.classList.toggle('is-on', allMode);
                toggleAllBtn.textContent = allMode ? 'Hide all colors' : 'View all colors';

                // Show/hide
                if(allWrap) allWrap.style.display = allMode ? 'block' : 'none';
                if(singleGrid) singleGrid.style.display = allMode ? 'none' : 'flex';

                if(allMode){
                    colorNameEl.textContent = 'All colors';
                    setMsg('');
                    buildAllColorsAccordion();
                }else{
                    setMsg('');
                    refreshSingle();
                }
            });
        }

        // Auto-select first swatch
        window.addEventListener('load', () => {
            const first = swatches[0];
            if(first){
                selectedColor = first.getAttribute('data-color') || '';
                setSelectedSwatch(selectedColor);
                refreshSingle();
            }
        });

        // Safety retry
        setTimeout(() => {
            if(!selectedColor && swatches[0]){
                selectedColor = swatches[0].getAttribute('data-color') || '';
                setSelectedSwatch(selectedColor);
                refreshSingle();
            }
        }, 250);
    })();
    </script>
    <?php
    return ob_get_clean();
});
