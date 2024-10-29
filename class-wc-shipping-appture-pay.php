<?php
/**
 * Description of class-wc-shipping-appture-pay
 * 
 * @author Dewald
 */
class WC_Shipping_Appture_Pay extends WC_Shipping_Method {
    /**
     * Constructor for your shipping class
     *
     * @access public
     * @return void
     */
    public function __construct( $instance_id = 0 ) {
        $this->id = 'appture_pay_shipping';
        $this->instance_id 			     = absint( $instance_id );
        $this->method_title = __('Appture Pay Courier');
        $this->method_description = __('Unify Payment and Shipping Providers with Appture Pay, for merchants in South Africa.');
        
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
        );
        
        $this->title = $this->get_option( 'title' );
        $this->enabled = $this->get_option( 'enabled' );
        
        $options = get_option( 'appture_pay_settings' );
        $this->client_id = $options["client_id"];
        $this->client_secret = $options["client_secret"];
        $this->debug_email = ( ! isset($options["debug_email"]) || empty($options["debug_email"]) ? get_option('admin_email') : $options["debug_email"] );
        $this->send_debug_email = $options["send_debug_email"];
        $this->enable_logging = isset($options["enable_logging"]) ? $options["enable_logging"] : 0;
        
        $this->instance_form_fields = array(
            'enabled' => array(
                'title' 		=> __( 'Enable/Disable' ),
                'type' 			=> 'checkbox',
                'label' 		=> __( 'Enable this shipping method' ),
                'default' 		=> 'yes',
            ),
            'title' => array(
                'title' 		=> __( 'Method Title' ),
                'type' 			=> 'text',
                'description' 	=> __( 'This controls the title which the user sees during checkout.' ),
                'default'		=> __( 'Appture Pay Courier' ),
                'desc_tip'		=> true
            )
        );
        
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }
    
    public function process_admin_options() {
        $called = parent::process_admin_options();
        
        return $called;
    }

    // get a quote from appture pay
    function get_quote_rates($api, $delivery_id, $quote_payload) {
        
        $this->log("Quote payload: ".json_encode($quote_payload));
        $response = $api->deliveryPostQuote($delivery_id, $quote_payload);
        $this->log("Post delivery quote: ".json_encode($response));
        
        if($response && isset($response["success"]) && $response["success"]) {
            
            return $response["data"]["rates"];
            
        }
        
        return null;
    }
    
    /**
     * calculate_shipping function.
     *
     * @access public
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping($package = array()) {
        
        if(empty($package['destination']["postcode"])) return;
        
        // include the AppturePay api classes
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
        
        // set some vars
        $pickupAddress = null;
        $deliveryAddress = null;
        $delivery_id = 0;
        $delivery = null;
        $user = null;
        
        // check if we've already created a delivery with appture pay for this session
        $delivery_id = intval(WC()->session->get('appturepay_shipping_delivery_id',0));
        $this->log("Calculate shipping read Session Delivery ID:".WC()->session->get( 'appturepay_shipping_delivery_id', 0));
        
        // load the products - get the total weight and dimensions
        //$this->log("Package: ".json_encode($package));
        $quote_payload = array(
            "products" => array(),
            "value" => $package["contents_cost"],
            "insurance" => 0 // no insurance by default
        );
        foreach ( $package['contents'] as $item_id => $values ) {
            $_product  = $values['data'];
            $quote_payload["products"][] = array(
                "value" => $package["contents_cost"]/count($package['contents']),
                "weight" => max(1,intval($_product->get_weight())), // expecting kg
                "width" => intval($_product->get_width()) * 10, // expecting mm
                "height" => intval($_product->get_height()) * 10, // expecting mm
                "length" => intval($_product->get_length()) * 10, // expecting mm
                "quantity" => intval($values['quantity'])
            );
        }
        
        // get the user data
        $user = get_userdata( $package["user"]["ID"] );
        //$this->log("User: ".json_encode($user));
        
        // get the address
        /*
         * $package['destination']
         *  [country] => ZA
            [state] => GP
            [postcode] => 1501
            [city] => Beoni
            [address] => 
            [address_1] => 
            [address_2] => 
         */
        $deliveryAddress = array(
            "delivery_courier_checked" => 1,
            "delivery_contact_name" => ( $user !== false ? $user->first_name. " ". $user->last_name : "None provided" ),
            "delivery_contact_number_1" => "N/A",
            "delivery_contact_number_2" => "",
            "delivery_street" => empty($package['destination']["address_1"]) ? "Quoting" : implode(", ", array_filter( array( $package['destination']["address_1"], $package['destination']["address_2"] ) )),
            "delivery_suburb" => $package['destination']["city"],
            "delivery_city" => $package['destination']["city"],
            "delivery_province" => $package['destination']["state"],
            "delivery_country" => $package['destination']["country"],
            "delivery_postal_code" => $package['destination']["postcode"],
            "delivery_comments" => "Quoting" //$package['destination']["address_2"],
        );
        
        // courier check the address
        /*$response = $api->deliveryGetCheckPostalCode($deliveryAddress["delivery_postal_code"], array("city" => $deliveryAddress["delivery_city"]));
        $this->log("Delivery Check Postal Code: ".json_encode($response));
        
        if($response && isset($response["success"])) {
            
            if($response["success"]) {
            
                // great, its been checked
                $deliveryAddress["delivery_courier_checked"] = 1;
            
            } else if($response["message"] == "Address validated to possible places by courier") {
                
                // run through possible items and make sure we get atleast one item to match town and postal code provided
                foreach($response["data"] as $item) {
                    if($item["Town"] == $deliveryAddress["delivery_city"] && $item["PostalCode"] == $deliveryAddress["delivery_postal_code"]) {
                        
                        // great, its been checked
                        $deliveryAddress["delivery_courier_checked"] = 1;
                        break;
                    }
                }
                
            } else {
                
                return;
                
            }
            
        } else {
            
            return; // failed check, cannot quote
            
        }*/
        
        // create a new delivery or update the exiting session one
        if( $delivery_id == 0 ) {
            // create a new delivery
            
            // load the default pickup address
            $response = $api->addressGetSpecific("default");
            $this->log("Get address: ".json_encode($response));
            
            if($response && isset($response["success"]) && $response["success"]) {
                $pickupAddress = $response["data"];
                
                $response = $api->deliveryPost( array_merge( $deliveryAddress, array( "pickup_address_id" => $pickupAddress["id"] ) ) );
                $this->log("Post delivery: ".json_encode($response));
                
                if($response && isset($response["success"]) && $response["success"]) {
                    
                    // get the id of the created delivery
                    $delivery_id = $response["data"]["id"];
                    
                }
            }
            
            // set the delivery id to this session
            // we need to add this delivery id to the order as meta at a later stage
            WC()->session->set( 'appturepay_shipping_delivery_id', $delivery_id );
            $this->log("Set session delivery id: ".$delivery_id);
            
        } else {
            // delivery exists
            
            // update the delivery address
            $response = $api->deliveryPut( $delivery_id, $deliveryAddress );
            $this->log("Put delivery: ".json_encode($response));
            
        }
        
        // get a quote
        if($quote_rates = $this->get_quote_rates($api, $delivery_id, $quote_payload)) {
            
            // add all rate options
            foreach($quote_rates as $quote_rate) {
            
                // create and add the rate
                $rate = array(
                    'id'       => $this->id."-{$quote_rate["service"]}",
                    'label'    => $quote_rate["name"],
                    'cost'     => $quote_rate["total"],
                    'taxes'    => false,
                    'meta_data' => array(
                        "appture_pay_delivery_service" => $quote_rate["service"],
                        "appture_pay_delivery_id" => $delivery_id,
                    )
                );
                    
                $this->log("Set session rate: ".json_encode($rate));

                // Register the rate
                $this->add_rate( $rate );
                
            }
            
        }
        
    }
    
    function before_checkout_create_order($order, $package) {
        /*foreach($order->get_items() as $key=>$item) {
            //var_dump( array($item->get_product_id(),$item->get_variation_id()) );
            
            $product = wc_get_product( $item->get_product_id() );
            
            $product->get_weight();
        }
        
        ob_flush();
        die();
        $service = null;
        $delivery_id = null;*/

        // get the rates meta data that was set earlier - this data is lost for some reason so we need to save it ourselves
        foreach ($order->get_shipping_methods() as $item_id => $shipping_method) {
            
            if ($shipping_method->get_method_id() === $this->id) {
                
                $service = $shipping_method->get_meta("appture_pay_delivery_service");
                $delivery_id = $shipping_method->get_meta("appture_pay_delivery_id");
                
            }
        }
        
        $this->log("Should remove delivery ID:".($service !== null). " && ". ($delivery_id !== null));

        if ($service !== null && $delivery_id !== null) {
            
            $order->add_meta_data( '_appturelab_appturepay_delivery_id', $delivery_id );
            $order->add_meta_data( '_appturelab_appturepay_delivery_service', $service );
            $order->save_meta_data();
            
            // If the address entered has changed from the one entered on the
            // cart page, update the delivery street, contact details and
            // comment. During checkout these may be left blank.
            
            // include the AppturePay api classes
            include_once __DIR__. "/includes/ApptureWebRequest.php";
            include_once __DIR__. "/includes/AppturePayAPI.php";
            $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
            
            // get the details from the shipping method?
            $data = array (
                "delivery_contact_name" => $order->get_shipping_first_name(). " ". $order->get_shipping_last_name(),
                "delivery_street" => implode( ", ", array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2() ) ) ),
                "delivery_suburb" => $order->get_shipping_city(),
                "delivery_city" => $order->get_shipping_city(),
                "delivery_province" => $order->get_shipping_state(),
                "delivery_country" => $order->get_shipping_country(),
                "delivery_postal_code" => $order->get_shipping_postcode(),
                "delivery_contact_number_1" => $order->get_billing_phone(),
                "delivery_comments" => $order->get_customer_note() == "" ? "None" : $order->get_customer_note(),
            );
            
            // call deliveryPut
            $response = $api->deliveryPut($delivery_id, $data);
            $this->log("Update delivery with further details: ".json_encode($response));
            
            // update the selected delivery service as well
            $response = $api->deliveryPutQuote( $delivery_id, array("service" => $service) );
            $this->log("Update delivery service: ".json_encode($response));
            
        }
        
        $this->log("Meta Delivery ID:".$delivery_id);
        $this->log("Session Delivery ID:".WC()->session->get( 'appturepay_shipping_delivery_id', 0));
        WC()->session->set( 'appturepay_shipping_delivery_id', 0 );
        $this->log("Removed Session Delivery ID:".WC()->session->get( 'appturepay_shipping_delivery_id', 0));
        //ob_flush();
        //die();
    }

    /**
     * Log system processes.
     * @since 1.0.0
     */
    public function log($message) {
        //error_log($message);
        if ($this->enable_logging) {
            if (empty($this->logger)) {
                $this->logger = new WC_Logger();
            }
            $this->logger->add('appture-pay', $message);
        }
    }

}