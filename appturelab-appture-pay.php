<?php
/**
 * Plugin Name:       Appture Pay
 * Plugin URI:        https://www.appturepay.com/plugins
 * Description:       Unify payment and shipping providers with Appture Pay - for merchants in South Africa. Works with WooCommerce and MemberPress.
 * Version:           1.6.3
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Appture Pay
 * Author URI:        https://www.appturepay.com/
 * Developer:         Appture Pay
 * Developer URI:     https://www.appturepay.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * WC requires at least: 3.7
 * WC tested up to: 8.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function appture_pay_activation() {
    //add_option("appture_pay_settings", array("client_id" => "", "client_secret" => ""), '', false);
}
register_activation_hook( __FILE__, 'appture_pay_activation' );

function appture_pay_deactivation() {
    //error_log( "implement appture_pay_deactivation" );
}
register_deactivation_hook( __FILE__, 'appture_pay_deactivation' );

function appture_pay_uninstall() {
    //delete_option("appture_pay_settings");
}
register_uninstall_hook(__FILE__, 'appture_pay_uninstall');




function appture_pay_settings_client_description_html() {
    ?>
        <p>Fill in your API Client details here.</p>
        <p>To setup an API Client:</p>
        <ol>
            <li>Head over to <a target="blank" href="https://www.appturepay.com">Appture Pay</a> and sign in.</li>
            <li>Click on the <a target="blank" href="https://www.appturepay.com/client">API Clients</a> link in the left menu.</li>
            <li>Next click on Add Client and fill in the details
                <ol>
                    <?php
                    if ( hasWooCommerce() ) {
                        echo "<li>Set the Client's Redirect URL:<br><code>". add_query_arg( 'wc-api', 'WC_Gateway_Appture_Pay', home_url( '/' ) ). "</code></li>";
                        echo "<li>Set the Client's Callback URL:<br><code>". add_query_arg( array( 'wc-api' => 'WC_Gateway_Appture_Pay', 'itn' => '' ), home_url( '/' ) ). "</code></li>";
                    }
                    if (hasAppturePayFunding() ) {
                        echo "<li>Set the Client's Redirect URL:<br><code>". home_url( '/done/' ). "</code></li>";
                        echo "<li>Set the Client's Callback URL:<br><code>". add_query_arg( array( 'itn' => '' ), home_url( '/done/' ) ). "</code></li>";
                    } 
                    if(!hasWooCommerce() && !hasAppturePayFunding()) {
                        echo "<li>For the Client's Redirect and Callback URL's, first install and enable Woocommerce, then get it here.</li>";
                    }
                    ?>
                </ol>
            </li>
            <li>Copy the Identifier and Secret to this page</li>
        </ol>
    <?php
}

function appture_pay_field_client_id_html() {
    $options = get_option( 'appture_pay_settings' );
    echo "<input class='regular-text' type='text' id='client_id' name='appture_pay_settings[client_id]' value='".esc_attr($options["client_id"])."' required/>";
}

function appture_pay_field_client_secret_html() {
    $options = get_option( 'appture_pay_settings' );
    echo "<input class='regular-text' type='text' id='client_secret' name='appture_pay_settings[client_secret]' value='".esc_attr($options["client_secret"])."' required/>";
}

function appture_pay_field_send_debug_email_html() {
    $options = get_option( 'appture_pay_settings' );
    echo "<input class='regular-text' type='checkbox' id='send_debug_email' name='appture_pay_settings[send_debug_email]' ". ($options["send_debug_email"] ? "checked" : ""). " value='1'/>";
}

function appture_pay_field_debug_email_html() {
    $options = get_option( 'appture_pay_settings' );
    echo "<input class='regular-text' type='email' id='debug_email' name='appture_pay_settings[debug_email]' value='".esc_attr($options["debug_email"])."'/>";
}

function appture_pay_field_enable_logging_html() {
    $options = get_option( 'appture_pay_settings' );
    echo "<input class='regular-text' type='checkbox' id='enable_logging' name='appture_pay_settings[enable_logging]' ". ($options["enable_logging"] ? "checked" : ""). " value='1'/>";
}

function appture_pay_settings_validate($input) {
    $options = get_option( 'appture_pay_settings' );
    
    $updates = array();
    $updates['client_id'] = trim( $input['client_id'] );
    $updates['client_secret'] = trim( $input['client_secret'] );
    $updates['send_debug_email'] = trim( $input['send_debug_email'] );
    $updates['debug_email'] = trim( $input['debug_email'] );
    $updates['enable_logging'] = trim( $input['enable_logging'] );
    
    if ( $options["client_id"] !== $updates["client_id"] ) {
        $options["client_id"] = filter_var( $updates["client_id"], FILTER_SANITIZE_STRING );
    }
    
    if ( $options["client_secret"] !== $updates["client_secret"] ) {
        $options["client_secret"] = filter_var( $updates["client_secret"], FILTER_SANITIZE_STRING );
    }
    
    if ( $options["send_debug_email"] !== $updates["send_debug_email"] ) {
        $options["send_debug_email"] = intval( $updates["send_debug_email"] ) > 0 ? 1 : 0;
    }
    
    if ( $options["debug_email"] !== $updates["debug_email"] ) {
        $options["debug_email"] = filter_var( $updates["debug_email"], FILTER_SANITIZE_EMAIL );
    }
    
    if ( $options["enable_logging"] !== $updates["enable_logging"] ) {
        $options["enable_logging"] = intval( $updates["enable_logging"] ) > 0 ? 1 : 0;
    }
    
    include_once __DIR__. "/includes/ApptureWebRequest.php";
    include_once __DIR__. "/includes/AppturePayAPI.php";
    $api = new ApptureLab\AppturePayAPI($options["client_id"], $options["client_secret"]);
    $api->clearSession();
    //error_log(json_encode($api->getSession()));
    
    if( $api->getSession() === null) {

        add_settings_error(
            'client_id', // Slug title of setting
            'client_id', // Slug-name , Used as part of 'id' attribute in HTML output.
            __( 'Authentication failed for Client ID and Secret.' ), // message text, will be shown inside styled <div> and <p> tags
            'error' // Message type, controls HTML class. Accepts 'error' or 'updated'.
        );
        
    }
    
    return $options;
}

// add the admin settings and such
function appture_pay_admin_init(){
    // register the settings in one options field, as an array - group name, name, validation function
    register_setting( 'appture_pay_settings', 'appture_pay_settings', 'appture_pay_settings_validate' );
    
    // This creates a “section” of settings - unique id, title, section output function, page name, settings name?
    add_settings_section('appture_pay_settings_client', 'API Client Configuration', 'appture_pay_settings_client_description_html', 'appture_pay_settings_page', 'appture_pay_settings');
    
    // add each settings field
    // unique id, title, input box display callback, page name, settings section
    add_settings_field('client_id', 'Client Identifier', 'appture_pay_field_client_id_html', 'appture_pay_settings_page', 'appture_pay_settings_client');
    add_settings_field('client_secret', 'Client Secret', 'appture_pay_field_client_secret_html', 'appture_pay_settings_page', 'appture_pay_settings_client');
    add_settings_field('send_debug_email', 'Send debug email', 'appture_pay_field_send_debug_email_html', 'appture_pay_settings_page', 'appture_pay_settings_client');
    add_settings_field('debug_email', 'Debug email', 'appture_pay_field_debug_email_html', 'appture_pay_settings_page', 'appture_pay_settings_client');
    add_settings_field('enable_logging', 'Enable logging', 'appture_pay_field_enable_logging_html', 'appture_pay_settings_page', 'appture_pay_settings_client');
}
add_action( 'admin_init', 'appture_pay_admin_init' );


/** Step 1. */
function appture_pay_menu() {
    
    add_menu_page('Appture Pay', 'Appture Pay', null, 'appturelab-appture-pay-select', 'appture_pay_info_page', plugin_dir_url( __FILE__ ). "img/logo_wordpress_20.png" );
    
    add_submenu_page('appturelab-appture-pay-select', 'Appture Pay Client Settings', 'API Client', 'manage_options', 'appturelab-appture-pay', 'appture_pay_settings_page');
    
}
add_action( 'admin_menu', 'appture_pay_menu' );

function appture_pay_settings_page() {
    ?>
    <div>
        <?php
        if ( ! hasWooCommerce() && ! hasAppturePayFunding() ) {
            ?>
            <div class="error">
                <p>We cannot detect WooCommerce.</p>
            </div>
            <?php
        }
        ?>
        <h1>Appture Pay Client Settings</h1>
        Settings relating to your Appture Pay account and integration.
        <form action="options.php" method="post">
            <?php
            settings_fields( 'appture_pay_settings' );
            do_settings_sections( 'appture_pay_settings_page' );
            ?>
            <input name="Submit" type="submit" class="button button-primary" value="<?php echo esc_attr_e('Save Changes'); ?>" />
        </form>
    </div>
    <?php
}

function appture_pay_info_page() {
    ?>
    <div>
        <h1>Appture Pay for WordPress</h1>
        <div class="error">
            <p>You have not installed and/or activated either MemberPress or WooCommerce plugins.</p>
        </div>
    </div>
    <?php
}


/**
 * Initialize the gateway.
 */
function appturelab_appturepay_init() {
    
    if ( class_exists( 'WC_Shipping_Method' ) && class_exists( 'WC_Payment_Gateway' ) ) {
        
        require_once( plugin_basename( 'class-wc-shipping-appture-pay.php' ) );
        add_filter( 'woocommerce_shipping_methods', 'appturelab_appturepay_add_shipping' );
        
        // add shipping related order actions
        add_action('woocommerce_order_actions', 'appturelab_appturepay_add_order_meta_box_actions');
        
        require_once( plugin_basename( 'class-wc-gateway-appture-pay.php' ) );
        add_filter( 'woocommerce_payment_gateways', 'appturelab_appturepay_add_gateway' );
        
        
        // Save settings in admin if you have any defined
        //add_action('woocommerce_update_options_shipping', 'process_admin_options');
        
        // Save delivery id and service meta data on the order as it is created
        add_action('woocommerce_checkout_create_order', 'appturelab_appturepay_before_checkout_create_order', 20, 2);
        
        // purge transients so we can be sure a new delivery is generated for the next order, after completion of an order
        add_action('woocommerce_new_order', 'appturelab_appturepay_woocommerce_new_order', 10, 1);
        
    }
    
}
add_action( 'plugins_loaded', 'appturelab_appturepay_init', 0 );

function appturelab_appturepay_add_shipping( $methods ) {
    $methods["appture_pay_shipping"] = 'WC_Shipping_Appture_Pay'; 
    return $methods;
}

function appturelab_appturepay_add_gateway( $methods ) {
    $methods[] = 'WC_Gateway_Appture_Pay'; 
    return $methods;
}

function appturelab_appturepay_woocommerce_new_order( $order ) {
    // purge transients
    global $wpdb;

    //error_log("Purge shipping rates");

    $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_wc_ship%'" );

    // Delete all the wc_ship transient scum, you aren’t wanted around here, move along.
    // Same as being in shipping debug mode
    $transients = $wpdb->get_col("
        SELECT option_name FROM $wpdb->options
        WHERE option_name LIKE '_transient_wc_ship%'"
    );

    if (count($transients)) {
        foreach ($transients as $tr) {
            $hash = substr($tr, 11);
            //error_log("Deleted? ".intval(delete_transient($hash)));
        }
    }

    $transient_value = get_transient("shipping-transient-version");
    WC_Cache_Helper::delete_version_transients($transient_value);

    if(WC()->session) {
        WC()->session->set("shipping_for_package", "");
        WC()->session->set("shipping_for_package_0", "");
        WC()->session->set('appturepay_shipping_delivery_id',0);
    }
}

function appturelab_appturepay_validate_add_cart_item( $passed, $product_id, $quantity, $variation_id = '', $variations= '' ) {
    
    // call purge when this is a new cart
    if ( sizeof( WC()->cart->get_cart() ) == 0 ) {
        appturelab_appturepay_woocommerce_new_order( null );
    }
    
    return true;

}
add_filter( 'woocommerce_add_to_cart_validation', 'appturelab_appturepay_validate_add_cart_item', 10, 5 );

function appturelab_appturepay_before_checkout_create_order($order, $package) {
    
    $shipping = new WC_Shipping_Appture_Pay();
    $shipping->before_checkout_create_order($order, $package);
    
}




/**
 * Add a custom action to order actions select box on edit order page
 * Only added for paid orders that haven't fired this action yet
 *
 * @param array $actions order actions array to display
 * @return array - updated actions
 */
function appturelab_appturepay_add_order_meta_box_actions($actions) {
    global $theorder;

    // bail if the order has not been paid for or this action has been run
    if ( $theorder->is_paid() ) {
        
        // has the courier been dispatched?
        if( get_post_meta($theorder->get_id(), '_wc_order_appturepay_courier_dispatched', true) ) {
            
            if( $theorder->get_status() === 'processing' ) {
                
                // add print labels action
                $actions['appturelab_appturepay_order_courier_labels'] = __('Download/Print Courier Label(s)');
                // add print waybill action
                $actions['appturelab_appturepay_order_courier_waybill'] = __('Download/Print Courier Waybill');
                
            }
            
        } else {
            
            // add dispatch courier action
            $actions['appturelab_appturepay_order_courier_dispatched'] = __('Dispatch the Appture Pay Courier');
            
        }
        
    }

    
    return $actions;
}


/**
 * Update the Appture Pay delivery with the selected service
 * Dispatch the Appture Pay Courier
 * Add an order note when custom action is clicked
 * Add a flag on the order to show it's been run
 *
 * @param \WC_Order $order
 */
function appturelab_appturepay_order_courier_dispatched_process_action( $order ) {
    
    $options = get_option( 'appture_pay_settings' );
    
    // include the AppturePay api classes
    include_once __DIR__. "/includes/ApptureWebRequest.php";
    include_once __DIR__. "/includes/AppturePayAPI.php";
    $api = new ApptureLab\AppturePayAPI($options["client_id"], $options["client_secret"]);
    
    $delivery_id = $order->get_meta("_appturelab_appturepay_delivery_id");
    $service = $order->get_meta("_appturelab_appturepay_delivery_service");
    $error = false;
    
    // update the related appture pay delivery with the selected service
    $response = $api->deliveryPutQuote($delivery_id, array("service" => $service));
    
    if($response && isset($response["success"]) && $response["success"]) {
        
        // call the dispatch endpoint for the delivery
        $response = $api->deliveryPutDispatch($delivery_id, null);
        
        if($response && isset($response["success"]) && $response["success"]) {

            // add the order note
            // translators: Placeholders: %s is a user's display name
            $order->add_order_note( __( 'The courier has been dispatched.' ) );

            // add the flag
            update_post_meta( $order->get_id(), '_wc_order_appturepay_courier_dispatched', 'yes' );
            
        } else {
            
            $error = true;
            
        }
        
    } else {
        
        $error = true;
        
    }
    
    if($error) {
        
        $adminnotice = new WC_Admin_Notices();
        $adminnotice->add_custom_notice("appturelab_appturepay_order_courier_dispatched_process_action_error","Error Dispatching the courier".($response && isset($response["message"]) ? ":<br>".$response["message"] : ""));
        $adminnotice->output_custom_notices();
        
    }
    
}
add_action( 'woocommerce_order_action_appturelab_appturepay_order_courier_dispatched', 'appturelab_appturepay_order_courier_dispatched_process_action' );


/**
 * Download the Labels PDF from appture pay and output to browser for printing
 *
 * @param \WC_Order $order
 */
function appturelab_appturepay_order_courier_labels_process_action( $order ) {
    
    $options = get_option( 'appture_pay_settings' );
    
    // include the AppturePay api classes
    include_once __DIR__. "/includes/ApptureWebRequest.php";
    include_once __DIR__. "/includes/AppturePayAPI.php";
    $api = new ApptureLab\AppturePayAPI($options["client_id"], $options["client_secret"]);
    
    $delivery_id = $order->get_meta("_appturelab_appturepay_delivery_id");
    
    $filepath = __DIR__."/waybills/";
    $filename = "Label_{$delivery_id}.pdf";
    
    if(file_exists($filepath.$filename)) {
        
        // serve existing file
        
        $fileContents = file_get_contents($filepath.$filename);
        $mime = mime_content_type($filepath.$filename);
        
        header("Content-Type: $mime");
        header("Content-Disposition: attachment; filename='{$filename}'");
        echo $fileContents;
        ob_flush();
        die();
        
    } else {
        
        // download and serve

        $result = $api->deliveryGetLabel($delivery_id); // returns a file
        
        if($result) {
            
            if(!file_exists($filepath)) {
                mkdir($filepath,0755,true);
            }
            
            $fp = fopen($filepath.$filename, 'w');
            fwrite($fp, $result);
            fclose($fp);
            
            // serve the file
            
            $fileContents = $result;
            $mime = mime_content_type($filepath.$filename);
            
            header("Content-Type: $mime");
            header("Content-Disposition: attachment; filename='{$filename}'");
            echo $fileContents;
            ob_flush();
            die();

        } else {

            $adminnotice = new WC_Admin_Notices();
            $adminnotice->add_custom_notice("appturelab_appturepay_order_courier_waybill_process_action_error","Failed downloading Label(s) from Appture Pay. Please try again.");
            $adminnotice->output_custom_notices();

        }

    }
    
}
add_action( 'woocommerce_order_action_appturelab_appturepay_order_courier_labels', 'appturelab_appturepay_order_courier_labels_process_action' );

/**
 * Download the Waybill PDF from appture pay and output to browser for printing
 *
 * @param \WC_Order $order
 */
function appturelab_appturepay_order_courier_waybill_process_action( $order ) {
    
    $options = get_option( 'appture_pay_settings' );
    
    // include the AppturePay api classes
    include_once __DIR__. "/includes/ApptureWebRequest.php";
    include_once __DIR__. "/includes/AppturePayAPI.php";
    $api = new ApptureLab\AppturePayAPI($options["client_id"], $options["client_secret"]);
    
    $delivery_id = $order->get_meta("_appturelab_appturepay_delivery_id");
    
    $filepath = __DIR__."/waybills/";
    $filename = "Waybill_{$delivery_id}.pdf";
    
    if(file_exists($filepath.$filename)) {
        
        // serve existing file
        
        $fileContents = file_get_contents($filepath.$filename);
        $mime = mime_content_type($filepath.$filename);
        
        header("Content-Type: $mime");
        header("Content-Disposition: attachment; filename='{$filename}'");
        echo $fileContents;
        ob_flush();
        die();
        
    } else {
        
        // download and serve

        $result = $api->deliveryGetWaybill($delivery_id); // returns a file
        
        if($result) {
            
            if(!file_exists($filepath)) {
                mkdir($filepath,0755,true);
            }
            
            $fp = fopen($filepath.$filename, 'w');
            fwrite($fp, $result);
            fclose($fp);
            
            // serve the file
            
            $fileContents = $result;
            $mime = mime_content_type($filepath.$filename);
            
            header("Content-Type: $mime");
            header("Content-Disposition: attachment; filename='{$filename}'");
            echo $fileContents;
            ob_flush();
            die();

        } else {

            $adminnotice = new WC_Admin_Notices();
            $adminnotice->add_custom_notice("appturelab_appturepay_order_courier_waybill_process_action_error","Failed downloading Waybill from Appture Pay. Please try again.");
            $adminnotice->output_custom_notices();

        }

    }
    
}
add_action( 'woocommerce_order_action_appturelab_appturepay_order_courier_waybill', 'appturelab_appturepay_order_courier_waybill_process_action' );

function hasWooCommerce() {
    return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
}

function hasMemberPress() {
    return in_array( 'memberpress/memberpress.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
}

function hasAppturePayFunding() {
    return in_array( 'appturelab-appture-pay-funding/appturelab-appture-pay-funding.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
}

// 3rd party dsv courier's slow server is causing timeout issues so we added these
add_filter('http_request_args', 'appturelab_appturepay_http_request_args', 100, 1);
function appturelab_appturepay_http_request_args($r) //called on line 237
{
    $r['timeout'] = 15;
    return $r;
}
add_action('http_api_curl', 'appturelab_appturepay_http_api_curl', 100, 1);
function appturelab_appturepay_http_api_curl($handle) //called on line 1315
{
    curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 15 );
    curl_setopt( $handle, CURLOPT_TIMEOUT, 15 );
}