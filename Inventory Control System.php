<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// 1. REGISTER SETTINGS
// -----------------------------------------------------------------------------
add_action( 'admin_menu', 'cw_vsc_add_admin_menu', 99 );
function cw_vsc_add_admin_menu() {
    add_submenu_page( 
        'woocommerce', 
        'Inventory Control System', 
        'Inventory Control System', 
        'manage_woocommerce', 
        'cw-inventory-control-system', 
        'cw_vsc_render_settings_page' 
    );
}

add_action( 'admin_init', 'cw_vsc_register_settings' );
function cw_vsc_register_settings() {
    register_setting( 'cw_vsc_settings_group', 'cw_vsc_config' );
}

function cw_vsc_get_options() {
    $defaults = [
        'max_orders'       => '15',
        'reopen_condition' => 'below_threshold', // 'below_threshold', 'zero', 'manual'
        'products'         => '',
        'statuses'         => ['processing' => '1', 'on-hold' => '1'],
        'message'          => 'We are processing a high volume of orders. This service is temporarily paused.',
        'webhook_url'      => ''
    ];
    return wp_parse_args( get_option( 'cw_vsc_config', [] ), $defaults );
}

// -----------------------------------------------------------------------------
// 2. STATE MANAGER, UNIVERSAL CACHE PURGE & WEBHOOK ALERT
// -----------------------------------------------------------------------------
function cw_vsc_purge_all_caches() {
    if ( has_action( 'litespeed_purge_all' ) ) { do_action( 'litespeed_purge_all' ); }
    if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
    if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
    if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
    if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); }
    if ( has_action( 'breeze_clear_all_cache' ) ) { do_action( 'breeze_clear_all_cache' ); }
    if ( class_exists( 'WpFastestCache' ) ) {
        $wpfc = new WpFastestCache();
        $wpfc->deleteCache( true );
    }
}

function cw_vsc_send_discord_alert( $new_state, $current_count, $max_orders ) {
    $options = cw_vsc_get_options();
    if ( empty( $options['webhook_url'] ) ) return;

    if ( $new_state === 'yes' ) {
        $title = "🚨 Store Throttled: Sales Paused";
        $desc  = "The open order threshold has been reached. Target products are now marked out of stock.";
        $color = 14242639; // Red
    } else {
        $title = "✅ Store Un-Throttled: Sales Resumed";
        $desc  = "Target products are available again.";
        $color = 3962685; // Green
    }

    $payload = [
        'embeds' => [[
            'title'       => $title,
            'description' => $desc,
            'color'       => $color,
            'fields'      => [
                [ 'name' => 'Open Orders', 'value' => (string) $current_count, 'inline' => true ],
                [ 'name' => 'Threshold',   'value' => (string) $max_orders, 'inline' => true ],
            ],
            'timestamp'   => date('c')
        ]]
    ];

    wp_remote_post( $options['webhook_url'], [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
    ]);
}

function cw_vsc_get_current_order_count() {
    $options = cw_vsc_get_options();
    $statuses = !empty($options['statuses']) ? array_keys(array_filter($options['statuses'])) : [];
    
    if ( empty( $statuses ) ) { return 0; }
    
    $formatted_statuses = array_map(function($status) {
        return strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
    }, $statuses);

    $args = [
        'status'   => $formatted_statuses,
        'limit'    => 1,
        'paginate' => true,
        'return'   => 'ids',
    ];
    
    $results = wc_get_orders( $args );
    return isset( $results->total ) ? $results->total : 0;
}

function cw_vsc_update_throttle_state() {
    $options = cw_vsc_get_options();
    $open_order_count = cw_vsc_get_current_order_count();
    
    $current_state    = get_option( 'cw_store_is_throttled', 'no' );
    $max_orders       = (int) $options['max_orders'];
    $reopen_condition = isset( $options['reopen_condition'] ) ? $options['reopen_condition'] : 'below_threshold';
    
    $new_state = $current_state;

    if ( $current_state === 'no' ) {
        // If OPEN, check if we need to PAUSE
        if ( $open_order_count >= $max_orders ) {
            $new_state = 'yes';
        }
    } else {
        // If PAUSED, check our resume condition to see if we can OPEN
        if ( $reopen_condition === 'below_threshold' && $open_order_count < $max_orders ) {
            $new_state = 'no';
        } elseif ( $reopen_condition === 'zero' && $open_order_count <= 0 ) {
            $new_state = 'no';
        } elseif ( $reopen_condition === 'manual' ) {
            $new_state = 'yes'; // Stays closed until manually changed
        }
    }
    
    if ( $current_state !== $new_state ) {
        update_option( 'cw_store_is_throttled', $new_state );
        cw_vsc_purge_all_caches();
        cw_vsc_send_discord_alert( $new_state, $open_order_count, $max_orders );
    }
}

add_action( 'woocommerce_new_order', 'cw_vsc_update_throttle_state' );
add_action( 'woocommerce_order_status_changed', 'cw_vsc_update_throttle_state' );
add_action( 'woocommerce_trash_order', 'cw_vsc_update_throttle_state' );

// -----------------------------------------------------------------------------
// 3. RENDER ADMIN UI
// -----------------------------------------------------------------------------
function cw_vsc_render_settings_page() {
    // Process Manual Override Action before rendering
    if ( isset( $_POST['cw_vsc_force_action'] ) && check_admin_referer( 'cw_vsc_force_action_nonce' ) ) {
        if ( $_POST['cw_vsc_force_action'] === 'resume' ) {
            update_option( 'cw_store_is_throttled', 'no' );
            cw_vsc_purge_all_caches();
            cw_vsc_send_discord_alert( 'no', cw_vsc_get_current_order_count(), cw_vsc_get_options()['max_orders'] );
            echo '<div class="notice notice-success is-dismissible"><p>Store manually resumed!</p></div>';
        }
    }

    cw_vsc_update_throttle_state(); // Ensure UI displays the most accurate live state
    
    $options = cw_vsc_get_options();
    $current_count = cw_vsc_get_current_order_count();
    $is_throttled = get_option( 'cw_store_is_throttled' ) === 'yes';
    
    $status_bg = $is_throttled ? '#f2dede' : '#dff0d8';
    $status_border = $is_throttled ? '#ebccd1' : '#d6e9c6';
    $status_text_color = $is_throttled ? '#a94442' : '#3c763d';
    $status_msg = $is_throttled ? 'YES (Target products are currently paused)' : 'NO (Target products are available)';
    ?>
    <div class="wrap">
        <h2>Volume Stock Control</h2>
        
        <div style="background: <?php echo $status_bg; ?>; border: 1px solid <?php echo $status_border; ?>; color: <?php echo $status_text_color; ?>; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; color: <?php echo $status_text_color; ?>;">Live System Status</h3>
            <p style="margin: 0 0 5px 0;"><strong>Active Open Orders Detected:</strong> <?php echo esc_html( $current_count ); ?> (Limit: <?php echo esc_html( $options['max_orders'] ); ?>)</p>
            <p style="margin: 0;"><strong>Store Throttled:</strong> <strong><?php echo $status_msg; ?></strong></p>
            
            <?php if ( $is_throttled ) : ?>
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field( 'cw_vsc_force_action_nonce' ); ?>
                    <input type="hidden" name="cw_vsc_force_action" value="resume">
                    <button type="submit" class="button button-primary">Force Resume Sales Now</button>
                    <p style="font-size: 12px; margin-top: 5px;"><em>Note: If you have "Auto-Resume below threshold" selected and your active orders are still above the limit, the system will immediately re-pause.</em></p>
                </form>
            <?php endif; ?>
        </div>

        <p>Automatically pause sales for specific products when your open order volume gets too high.</p>
        <form method="post" action="options.php">
            <?php settings_fields( 'cw_vsc_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Max Open Orders Threshold</th>
                    <td>
                        <input type="number" name="cw_vsc_config[max_orders]" value="<?php echo esc_attr($options['max_orders']); ?>" style="width: 100px;">
                        <p class="description">If active orders reach this number, the target products will be marked out of stock.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Resume Condition</th>
                    <td>
                        <select name="cw_vsc_config[reopen_condition]">
                            <option value="below_threshold" <?php selected($options['reopen_condition'], 'below_threshold'); ?>>Auto-Resume when orders drop below threshold</option>
                            <option value="zero" <?php selected($options['reopen_condition'], 'zero'); ?>>Wait until exactly 0 open orders remain</option>
                            <option value="manual" <?php selected($options['reopen_condition'], 'manual'); ?>>Stay Closed (Manual resume only)</option>
                        </select>
                        <p class="description">Determine how the store behaves after it has been automatically paused.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Target Product IDs</th>
                    <td>
                        <input type="text" name="cw_vsc_config[products]" value="<?php echo esc_attr($options['products']); ?>" style="width: 100%; max-width: 400px;" placeholder="e.g. 35, 215, 386">
                        <p class="description">Comma-separated list of Product IDs to throttle.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Statuses to Count as "Open"</th>
                    <td>
                        <?php 
                        $wc_statuses = wc_get_order_statuses();
                        foreach ($wc_statuses as $slug => $name) {
                            $clean_slug = str_replace('wc-', '', $slug);
                            $checked = !empty($options['statuses'][$clean_slug]) ? 'checked' : '';
                            echo "<label style='display:block; margin-bottom:4px;'><input type='checkbox' name='cw_vsc_config[statuses][{$clean_slug}]' value='1' {$checked}> {$name}</label>";
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Discord Webhook URL</th>
                    <td>
                        <div style="display: flex; gap: 4px; align-items: center; max-width: 400px;">
                            <input type="password" id="cw_vsc_webhook_url" name="cw_vsc_config[webhook_url]" value="<?php echo esc_attr($options['webhook_url']); ?>" placeholder="https://discord.com/api/webhooks/..." style="width: 100%;">
                            <button type="button" class="button button-secondary" onclick="var x = document.getElementById('cw_vsc_webhook_url'); x.type = (x.type === 'password' ? 'text' : 'password');" title="Show/Hide URL">👁️</button>
                        </div>
                        <p class="description">Get alerted in Discord instantly when the store pauses or resumes.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Throttle Message</th>
                    <td>
                        <textarea name="cw_vsc_config[message]" rows="3" style="width: 100%; max-width: 400px;"><?php echo esc_textarea($options['message']); ?></textarea>
                        <p class="description">Notice displayed on the single product page and cart when an item is paused.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

// -----------------------------------------------------------------------------
// 4. DYNAMIC PRODUCT FILTERS & CART VALIDATION (Supports Unmanaged Stock)
// -----------------------------------------------------------------------------
function cw_vsc_is_target_product( $product ) {
    $options = cw_vsc_get_options();
    if ( empty( trim( $options['products'] ) ) || ! is_a( $product, 'WC_Product' ) ) {
        return false;
    }
    
    $target_ids = array_map( 'trim', explode( ',', $options['products'] ) );
    
    $product_id = (string) $product->get_id();
    $parent_id  = (string) $product->get_parent_id(); 
    
    return in_array( $product_id, $target_ids, true ) || ( $parent_id !== '0' && in_array( $parent_id, $target_ids, true ) );
}

add_filter( 'woocommerce_get_availability', 'cw_vsc_force_availability_display', 10, 2 );
function cw_vsc_force_availability_display( $availability, $product ) {
    if ( get_option( 'cw_store_is_throttled' ) === 'yes' && cw_vsc_is_target_product( $product ) ) {
        $availability['availability'] = __( 'Out of stock', 'woocommerce' );
        $availability['class']        = 'out-of-stock';
    }
    return $availability;
}

add_filter( 'woocommerce_product_get_stock_status', 'cw_vsc_override_stock_status_string', 10, 2 );
function cw_vsc_override_stock_status_string( $status, $product ) {
    if ( get_option( 'cw_store_is_throttled' ) === 'yes' && cw_vsc_is_target_product( $product ) ) {
        return 'outofstock';
    }
    return $status;
}

add_filter( 'woocommerce_product_is_in_stock', 'cw_vsc_throttle_stock_status', 10, 2 );
function cw_vsc_throttle_stock_status( $is_in_stock, $product ) {
    if ( get_option( 'cw_store_is_throttled' ) === 'yes' && cw_vsc_is_target_product( $product ) ) {
        return false; 
    }
    return $is_in_stock;
}

add_filter( 'woocommerce_is_purchasable', 'cw_vsc_throttle_purchasability', 10, 2 );
add_filter( 'woocommerce_variation_is_purchasable', 'cw_vsc_throttle_purchasability', 10, 2 );
function cw_vsc_throttle_purchasability( $purchasable, $product ) {
    if ( get_option( 'cw_store_is_throttled' ) === 'yes' && cw_vsc_is_target_product( $product ) ) {
        return false;
    }
    return $purchasable;
}

add_filter( 'woocommerce_add_to_cart_validation', 'cw_vsc_prevent_add_to_cart', 10, 3 );
function cw_vsc_prevent_add_to_cart( $passed, $product_id, $quantity ) {
    if ( get_option( 'cw_store_is_throttled' ) === 'yes' ) {
        $product = wc_get_product( $product_id );
        if ( $product && cw_vsc_is_target_product( $product ) ) {
            $options = cw_vsc_get_options();
            wc_add_notice( $options['message'], 'error' );
            return false;
        }
    }
    return $passed;
}

add_action( 'woocommerce_check_cart_items', 'cw_vsc_force_cart_validation' );
function cw_vsc_force_cart_validation() {
    if ( get_option( 'cw_store_is_throttled' ) !== 'yes' ) return;
    
    $cart = WC()->cart;
    if ( ! $cart ) return;
    
    $removed_any = false;
    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( isset( $cart_item['data'] ) && cw_vsc_is_target_product( $cart_item['data'] ) ) {
            $cart->remove_cart_item( $cart_item_key );
            $removed_any = true;
        }
    }
    
    if ( $removed_any ) {
        $options = cw_vsc_get_options();
        wc_add_notice( $options['message'], 'error' );
    }
}

add_action( 'woocommerce_single_product_summary', 'cw_vsc_throttle_message_display', 25 );
function cw_vsc_throttle_message_display() {
    global $product;
    if ( is_a( $product, 'WC_Product' ) && get_option( 'cw_store_is_throttled' ) === 'yes' && cw_vsc_is_target_product( $product ) ) {
        $options = cw_vsc_get_options();
        echo '<div class="woocommerce-info" style="margin-bottom: 20px; border-color: #d9534f;">';
        echo esc_html( $options['message'] );
        echo '</div>';
    }
}
