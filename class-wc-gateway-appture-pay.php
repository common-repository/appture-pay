<?php
/**
 * @todo need to test debug emails
 */
class WC_Gateway_Appture_Pay extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id = "appture_pay_gateway";
        $this->icon = "https://www.appturepay.com/img/full_logo_vector.svg";
        $this->has_fields = false;
        $this->method_title = "Appture Pay";
        $this->method_description = "Appture Pay works by sending the user to Appture Pay's secure Gateway to enter their payment information.";
        $this->available_countries  = array( 'ZA' );
        
        // Supported functionality
        $this->supports = array(
            'products',
            'pre-orders',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
        );

        $this->init_form_fields();
        $this->init_settings();
        
        $options = get_option( 'appture_pay_settings' );
        $this->client_id = $options["client_id"];
        $this->client_secret = $options["client_secret"];
        $this->debug_email = ( ! isset($options["debug_email"]) || empty($options["debug_email"]) ? get_option('admin_email') : $options["debug_email"] );
        $this->send_debug_email = $options["send_debug_email"];
        $this->enable_logging = isset($options["enable_logging"]) ? $options["enable_logging"] : 0;
        $this->url = 'https://www.appturepay.com/gateway';
        
        $this->title = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->response_url = add_query_arg( 'wc-api', 'WC_Gateway_Appture_Pay', home_url( '/' ) );
        $this->enabled = $this->is_valid_for_use() ? 'yes': 'no'; // Check if enabled and client has been set-up

        add_action( 'woocommerce_api_wc_gateway_appture_pay', array( $this, 'check_response' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_appture_pay_gateway', array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
        add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
        add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_payments' ) );
        //add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        // Add fees to order.
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_fee') );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_net'), 20 );

        // Change Payment Method actions.
		add_action( 'woocommerce_subscription_payment_method_updated_from_' . $this->id, array( $this, 'maybe_cancel_subscription_token' ), 10, 2 );
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable/Disable', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Enable Appture Pay', 'woocommerce' ),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __( 'Title', 'woocommerce' ),
                'type' => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'default' => __( 'Visa or Mastercard', 'woocommerce' ),
                'desc_tip'      => true,
            ),
            'description' => array(
                'title' => __( 'Description', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
                'default' => __("Pay via Appture Pay using a Visa or Mastercard bank card", 'woocommerce')
            )
        );
    } // End init_form_fields()
    
    /**
     * 
     * @return boolean
     */
   public function is_valid_for_use() {
        $is_available = false;

        if ( $this->get_option( 'enabled' ) === "yes" && $this->client_id && $this->client_secret ) {
            $is_available = true;
        }

        return $is_available;
   }

    function admin_options() {
        ?>
            <h2><?php _e('Appture Pay','woocommerce'); ?></h2>
            <p>Appture Pay works by sending the user to Appture Pay's Gateway to enter their payment information.</p>
            <table class="form-table">
            <?php $this->generate_settings_html(); ?>
            </table>
        <?php
    }

    /**
     * Generate the Appture Pay button link.
     *
     * @since 1.0.0
     */
    public function generate_appture_pay_form( $order_id ) {
        
        $order = wc_get_order($order_id);
        
        // Construct transaction to create via Appture Pay API
        $transaction = array(
            'client_id' => $this->client_id,
            
            // Item details
            'reference' => "ORDER" . str_pad($order->get_order_number(), 6, "0", STR_PAD_LEFT),
            /* translators: 1: blog info name */
            'description' => sprintf(__('New order from %s', 'woocommerce-gateway-appture-pay'), get_bloginfo('name')),
            'transaction_type' => "DB",
            'total' => $order->get_total(),
            
            // Billing details
            'billing_street' => implode( ", ", array_filter( array( self::get_order_prop($order, 'billing_address_1'), self::get_order_prop($order, 'billing_address_2') ) ) ),
            'billing_city' => self::get_order_prop($order, 'billing_city'),
            'billing_province' => self::get_order_prop($order, 'billing_state'),
            'billing_country' => self::get_order_prop($order, 'billing_country'),
            'billing_postal_code' => self::get_order_prop($order, 'billing_postcode'),
            'billing_phone' => self::get_order_prop($order, 'billing_phone'),
            'billing_email' => self::get_order_prop($order, 'billing_email'),
            'billing_first_name' => self::get_order_prop($order, 'billing_first_name'),
            'billing_last_name' => self::get_order_prop($order, 'billing_last_name'),
            'billing_company' => self::get_order_prop($order, 'billing_company'),
        );
        
        // add subscription parameters
        if ( $this->is_subscription( $order_id ) || $this->order_contains_subscription($order_id)) {
            // set transaction type to RECURRING_DB
            $transaction['transaction_type'] = 'RECURRING_DB';
            $transaction['recurring_term'] = 0;
            $transaction['max_recur'] = 0;
        }

        if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order($order_id);
			$current       = reset( $subscriptions );
            // For renewal orders that have subscriptions with renewal flag OR
			// For renew orders which are failed to pay by other payment gateway buy now payinh using Payfast.
			// we will create a new subscription in Payfast and link it to the existing ones in WC.
			// The old subscriptions in Payfast will be cancelled once we handle the itn request.
            if ( count( $subscriptions ) > 0 && ( $this->_has_renewal_flag( $current ) || $this->id !== $current->get_payment_method() ) ) {
                // set transaction type to RECURRING_DB
                $transaction['transaction_type'] = 'RECURRING_DB';
                $transaction['recurring_term'] = 0;
                $transaction['max_recur'] = 0;
            }
        }

        // pre-order: add the subscription type for pre order that require tokenization
        // at this point we assume that the order pre order fee and that
        // we should only charge that on the order. The rest will be charged later.
        if ($this->order_contains_pre_order($order_id) && $this->order_requires_payment_tokenization($order_id)) {
            $transaction['total'] = $this->get_pre_order_fee($order_id);
            $transaction['transaction_type'] = 'RECURRING_DB';
            $transaction['recurring_term'] = 0;
            $transaction['max_recur'] = 0;
        }

        $this->log( 'Appture Pay: Generate Transaction'.print_r($transaction,1) );
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
        $response = $api->transactionPost($transaction);

        $this->log( 'Appture Pay: Transaction gen response'.print_r($response,1) );
        
        if($response && $response["success"]) {
        
            // get the transaction identifier from the response
            $transactionIdentifier = $response["data"]["identifier"];
            
            // Save the transaction identifier on the order as _transaction_id
            // meta. We will read this transaction later when checking payment.
            add_post_meta( $order->get_id(), '_transaction_id', $transactionIdentifier, true );

            return '<form action="' . esc_url($this->url) . "?id=".esc_attr($transactionIdentifier). '" method="post" id="appture_pay_payment_form">
                        <input type="submit" class="button-alt" id="submit_appture_pay_payment_form" value="' . __('Pay via Appture Pay', 'woocommerce-gateway-appture-pay') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'woocommerce-gateway-appture-pay') . '</a>
                        <script type="text/javascript">
                            jQuery(function(){
                                jQuery("body").block(
                                    {
                                        message: "' . __('Thank you for your order. We are now redirecting you to Appture Pay to make payment.', 'woocommerce-gateway-appture-pay') . '",
                                        overlayCSS:
                                        {
                                            background: "#fff",
                                            opacity: 0.6
                                        },
                                        css: {
                                            padding:        20,
                                            textAlign:      "center",
                                            color:          "#555",
                                            border:         "3px solid #aaa",
                                            backgroundColor:"#fff",
                                            cursor:         "wait"
                                        }
                                    });
                                // allow the user time to read the notice
                                setTimeout(function(){
                                    jQuery( "#submit_appture_pay_payment_form" ).click();
                                },2500);
                                
                            });
                        </script>
                    </form>';
            
        } else {
            
            return "ERROR!";
            
        }
    }

    function process_payment($order_id) {
       if ( $this->order_contains_pre_order( $order_id )
            && $this->order_requires_payment_tokenization( $order_id )
            && ! $this->cart_contains_pre_order_fee() ) {
                throw new Exception( 'Appture Pay does not support transactions without any upfront costs or fees. Please select another gateway' );
        }
        
        $order = wc_get_order( $order_id );
        return array(
                'result' 	 => 'success',
                'redirect'	 => $order->get_checkout_payment_url( true ),
        );
    }
    
    public function receipt_page( $order ) {
        echo '<p>' . __( 'Thank you for your order, please click the button below to pay with Appture Pay.', 'woocommerce-gateway-appture-pay' ) . '</p>';
        echo $this->generate_appture_pay_form( $order );
    }
    
    public function check_response() {
        
        $data = array(
            "itn" => filter_input(INPUT_GET, "itn"),
            "id" => filter_input(INPUT_GET, "id"),
            "status" => filter_input(INPUT_GET, "status")
        );
        
        if($data["itn"] !== null) {
            
            $this->log( 'Appture Pay: Check ITN' );
            
            $this->check_itn_response( $data );
            
        } else {
            
            $this->log( 'Appture Pay: Handle Redirect' );
            
            $this->handle_redirect( $data );
            
        }
    }
    
    function handle_redirect( $data ) {
        
        // Load the related order
        $order = $this->get_order_for_transaction_id( $data["id"] );

        if ('SUCCESS' === $data["status"]) {

            header( "Location: " . $this->get_return_url( $order ) );
            flush();
            
        } elseif ('FAILED' === $data["status"] || 'ERROR' === $data["status"] || 'CLOSED' === $data["status"]) {

            header( "Location: " . $this->get_return_url( $order ) );
            flush();

        } elseif ('USER_CANCELLED' === $data["status"]) {

            header( "Location: " . $order->get_cancel_order_url() );
            flush();

        }
    }
    
    function check_itn_response( $data ) {
        
        $this->handle_itn_request( $data );
        
        // Notify Appture Pay that information has been received
        header( 'HTTP/1.0 200 OK' );
        flush();
        
    }
    
    public function handle_itn_request( $data ) {
        
        $this->log( PHP_EOL
            . '----------'
            . PHP_EOL . 'Appture Pay ITN call received'
            . PHP_EOL . '----------'
        );
        $this->log( 'Get data' );
        $this->log( 'Appture Pay Data: ' . print_r( $data, true ) );
        
        $transaction = null;
        $recurringTransaction = null;
        $order = null;
        $original_order = null;
        $order_id = null;
        $error = false;
        $done = false;
        $error_message = "";
        $vendor_name = get_bloginfo( 'name', 'display' );
        $vendor_url = home_url( '/' );

        $this->log("Authenticate Appture Pay with " . $this->client_id);
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        
        $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
        $api->clearSession();
        $response = $api->transactionGetSpecific($data["id"]);
        
        // Could we load the transaction?
        if($response && $response["success"]) {
            
            // success
            $transaction = $response["data"];
            $this->log("Transaction loaded: " . print_r( $transaction, true ) );
            
        } else {
            
            // fail
            $error_message = "Could not load transaction from Appture Pay: {$response["message"]}";
            $error = true;
            
        }

        // also load recurring transaction if type is recurring_db
        if('RECURRING_DB' === $transaction['transaction_type']) {
            $response = $api->recurringTransactionGetSpecific($transaction['recurring_transaction_id']);
            
            // Could we load the transaction?
            if($response && $response["success"]) {
                
                // success
                $recurringTransaction = $response["data"];
                $this->log("Recurring Transaction loaded: " . print_r( $recurringTransaction, true ) );
                
            } else {
                
                // fail
                $error_message = "Could not load recurring transaction from Appture Pay: {$response["message"]}";
                $error = true;
                
            }
        }
        
        // Load the related order
        $order = $original_order = $this->get_order_for_transaction_id( $data["id"] );
        
        // Check the order has been found
        if( ! $error && $order === null) {

            // fail
            $error_message = "Could not find order";
            $error = true;

        } else {
            
            $order_id = $order->get_id();
            
        }
        
        // Check transaction status is same as parameter received
        if ( ! $error && $transaction['status'] !== $data["status"] ) {

            // fail
            $error_message = "Transaction status does not match status parameter received";
            $error = true;

        }

        // Check transaction total is same as order total
        if ( ! $error && ! $this->amounts_equal( $transaction['total'], self::get_order_prop( $order, 'order_total' ) )
                && ! $this->order_contains_pre_order( $order_id )
				&& ! $this->order_contains_subscription( $order_id )
				&& ! $this->is_subscription( $order_id ) ) { // if changing payment method.

            // fail
            $error_message = "Transaction total does not match order total ({$transaction['total']} != ". self::get_order_prop( $order, 'order_total' ).")";
            $error = true;

        }

        // alter order object to be the renewal order if
		// the ITN request comes as a result of a renewal submission request.
		$description = json_decode( $data['item_description'] );

		if ( ! empty( $description->renewal_order_id ) ) {
			$order = wc_get_order( $description->renewal_order_id );
		}

        // Get internal order and verify it hasn't already been processed.
        if ( ! $error && 'completed' === self::get_order_prop( $order, 'status' ) ) {

            // already done
            $error_message = "The related order has already been processed";
            $done = true;

        }
        
        // If an error occurred
        if ( $error ) {
            
            $this->log('Error occurred: ' . $error_message);

            if ($this->send_debug_email) {
                $this->log('Sending email notification');

                // Send an email
                $subject = 'Appture Pay Payment error: ' . $error_message;
                $body = "<p>Good day,</p>\n\n" .
                        "<p>An invalid Appture Pay transaction on your website requires attention.</p>\n" .
                        '<p>Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")<br>\n" .
                        'Remote IP Address: ' . $_SERVER['REMOTE_ADDR'] . "<br>\n" .
                        'Remote host name: ' . gethostbyaddr($_SERVER['REMOTE_ADDR']) . "<br>\n" .
                        'Purchase ID: ' . self::get_order_prop($order, 'id') . "<br>\n" .
                        'User ID: ' . self::get_order_prop($order, 'user_id') . "<br></p>\n";
                
                $body .= "<p>Error: " . $error_message . "</p>\n\n";
                
                if ( $transaction ) {
                    $body .= '<p>Appture Pay Transaction:<br>\n<pre>' . json_encode($transaction) . "</pre></p>\n\n";
                } else {
                    $body .= '<p>Appture Pay Transaction could not be loaded.</p>\n\n';
                }
                
                $body .= '<p>ITN data:<br>\n<pre>' . json_encode($data). "</pre></p>\n\n";
                
                $headers = array('Content-Type: text/html; charset=UTF-8');

                wp_mail($this->debug_email, $subject, $body, $headers);
                
            } // End if().
            
        } elseif ( ! $done ) {

            $this->log('Check status and update order');

            $status = $transaction['status'];

            /**
			 * Handle Changing Payment Method.
			 *   - Save payfast subscription token to handle future payment
			 *   - (for Payfast to Payfast payment method change) Cancel old token, as future payment will be handled with new token
			 */
			if ( $this->is_subscription( $order_id ) && floatval( 0 ) === floatval( $transaction['total'] ) ) {
				$this->log( '- Change Payment Method' );
				if ( 'complete' === $status && null !== $transaction['recurring_transaction_id'] ) {
					$subscription = wcs_get_subscription( $order_id );
					if ( ! empty( $subscription ) ) {
						$old_token = $this->_get_subscription_token( $subscription );
						// Cancel old subscription token of subscription if we have it.
						if ( ! empty( $old_token ) ) {
							$this->cancel_subscription_listener( $subscription );
						}

						// Set new subscription token on subscription.
						$this->_set_subscription_token( $transaction['recurring_transaction_id'], $subscription );
						$this->log( 'Payfast token updated on Subcription: ' . $order_id );
					}
				}
				return;
			}

            $subscriptions = array();
            if (function_exists('wcs_get_subscriptions_for_renewal_order') && function_exists('wcs_get_subscriptions_for_order')) {
                $subscriptions = array_merge(
                    wcs_get_subscriptions_for_renewal_order($order_id),
                    wcs_get_subscriptions_for_order($order_id)
                );
            }

            if ('SUCCESS' === $status) {
                foreach ($subscriptions as $subscription) {
                    $this->_set_renewal_flag($subscription);
                }
            }

            if ('SUCCESS' === $status) {
                
                $this->handle_itn_payment_complete($transaction, $order, $subscriptions);
                
            } elseif ('FAILED' === $status || 'ERROR' === $status || 'CLOSED' === $status) {
                
                $this->handle_itn_payment_failed($transaction, $order);
                
            } elseif ('USER_CANCELLED' === $status) {
                
                $this->handle_itn_payment_cancelled($data, $order, $subscriptions);
                
            }
            
        } // End if().

        $this->log(PHP_EOL
            . '----------'
            . PHP_EOL . 'End ITN call'
            . PHP_EOL . '----------'
        );
        
        //header( "Location: " . $order->get_checkout_order_received_url() );
        //flush();
    }
    
    /**
     * This function mainly responds to ITN cancel requests initiated on Appture Pay, but also acts
     * just in case they are not cancelled.
     * @version 1.4.3 Subscriptions flag
     *
     * @param array $data should be from the Gatewy ITN callback.
     * @param WC_Order $order
     */
    public function handle_itn_payment_cancelled($data, $order, $subscriptions) {

        remove_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_listener'));
        
        foreach ($subscriptions as $subscription) {
            if ('cancelled' !== $subscription->get_status()) {
                $subscription->update_status('cancelled', __('Merchant cancelled subscription on Appture Pay.'));
                $this->_delete_subscription_token($subscription);
            }
        }
        
        add_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_listener'));
    }

    /**
     * This function handles payment complete request by Appture Pay.
     * @version 1.4.3 Subscriptions flag
     *
     * @param array $data should be from the Gatewy ITN callback.
     * @param WC_Order $order
     */
    public function handle_itn_payment_complete($data, $order, $subscriptions) {
        $this->log('- Complete');
        $order->add_order_note(__('Payment completed'));
        $order_id = self::get_order_prop($order, 'id');
        
        // Store token for future subscription deductions.
        $this->log('- Store token: ('. $data['recurring_transaction_id'] .')');
        if (count($subscriptions) > 0 && isset($data['recurring_transaction_id'])) {
            if ($this->_has_renewal_flag(reset($subscriptions))) {
                // renewal flag is set to true, so we need to cancel previous token since we will create a new one
                $this->log('Cancel previous subscriptions with token ' . $this->_get_subscription_token(reset($subscriptions)));

                // only request API cancel token for the first subscription since all of them are using the same token
                $this->cancel_subscription_listener(reset($subscriptions));
            }

            $token = sanitize_text_field($data['recurring_transaction_id']);
            foreach ($subscriptions as $subscription) {
                $this->_delete_renewal_flag($subscription);
                $this->_set_subscription_token($token, $subscription);
            }
        }

        // the same mechanism (adhoc token) is used to capture payment later
        if ($this->order_contains_pre_order($order_id) && $this->order_requires_payment_tokenization($order_id)) {

            $token = sanitize_text_field($data['recurring_transaction_id']);
            $is_pre_order_fee_paid = get_post_meta($order_id, '_pre_order_fee_paid', true) === 'yes';

            if (!$is_pre_order_fee_paid) {
                // translators: 1: gross amount 2: payment id 
                $order->add_order_note(sprintf(__('Appture Pay pre-order fee paid: R %1$s (%2$s)', 'woocommerce-gateway-appture-pay'), $data['total'], $data['id']));
                $this->_set_pre_order_token($token, $order);
                // set order to pre-ordered
                WC_Pre_Orders_Order::mark_order_as_pre_ordered($order);
                update_post_meta($order_id, '_pre_order_fee_paid', 'yes');
                WC()->cart->empty_cart();
            } else {
                // translators: 1: gross amount 2: payment id 
                $order->add_order_note(sprintf(__('Appture Pay pre-order product line total paid: R %1$s (%2$s)', 'woocommerce-gateway-appture-pay'), $data['total'], $data['id']));
                $order->payment_complete();
                $this->cancel_pre_order_subscription($token);
            }
        } else {
            $order->payment_complete();
        }
        
        $order->payment_complete();
        
        $vendor_name = get_bloginfo('name', 'display');
        $vendor_url = home_url('/');
        
        if ($this->send_debug_email) {
            
            $this->log('- Send email ('. $this->debug_email .')');
            
            $subject = 'Appture Pay Payment on your site';
            $body = "<p>Good day,</p>\n\n" .
                    "<p>An Appture Pay transaction has been completed on your website.</p>\n" .
                    '<p>Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")<br>\n" .
                    'Purchase ID: ' . self::get_order_prop($order, 'id') . "<br>\n" .
                    'Appture Pay Transaction ID: ' . esc_html($data['id']) . "<br>\n" .
                    'Appture Pay Payment Status: ' . esc_html($data['status']) . "<br>\n" .
                    'Order Status Code: ' . self::get_order_prop($order, 'status') . "<br></p>\n";
            
            $headers = array('Content-Type: text/html; charset=UTF-8');
            
            wp_mail($this->debug_email, $subject, $body, $headers);
            
        }
    }

    /**
     * @param $data
     * @param $order
     */
    public function handle_itn_payment_failed($data, $order) {
        
        $this->log('- Failed');
        /* translators: 1: payment status */
        $order->update_status('failed', sprintf(__('Appture Pay Transaction failed (%s).'), strtolower(sanitize_text_field($data['status']))));
        $vendor_name = get_bloginfo('name', 'display');
        $vendor_url = home_url('/');

        if ($this->send_debug_email) {
            
            $this->log('- Send email ('. $this->debug_email .')');
            
            $subject = 'Appture Pay Transaction on your site';
            $body = "<p>Good day,</p>\n\n" .
                    "<p>A failed Appture Pay transaction on your website requires attention.</p>\n" .
                    '<p>Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")<br>\n" .
                    'Purchase ID: ' . self::get_order_prop($order, 'id') . "<br>\n" .
                    'User ID: ' . self::get_order_prop($order, 'user_id') . "<br>\n" .
                    'Appture Pay Transaction ID: ' . esc_html($data['id']) . "\n" .
                    'Appture Pay Payment Status: ' . esc_html($data['status']);
            
            $headers = array('Content-Type: text/html; charset=UTF-8');
            
            wp_mail($this->debug_email, $subject, $body, $headers);
            
        }
    }

    function get_order_for_transaction_id( $transaction_id ) {
        
        $orders = wc_get_orders([
            'limit'       => 1,
            'meta_query'  => [
                [
                    "key" => "_transaction_id",
                    "compare" => "=",
                    "value" => $transaction_id
                ]
            ]
        ]);

        // Could we find an order with the given transaction id?
        if( count($orders) ) {

            // all good
            return $orders[0];
            
        }
        
        return null;
    }
    
    /**
     * Get the return url (thank you page).
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    public function get_return_url( $order = null ) {
        if ( $order ) {
                $return_url = $order->get_checkout_order_received_url();
        } else {
                $return_url = wc_get_endpoint_url( 'order-received', '', wc_get_page_permalink( 'checkout' ) );
        }

        if ( is_ssl() || get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
                $return_url = str_replace( 'http:', 'https:', $return_url );
        }

        return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
    }
    
    
    /**
	 * @param string $order_id
	 * @return double
	 */
	public function get_pre_order_fee( $order_id ) {
		foreach ( wc_get_order( $order_id )->get_fees() as $fee ) {
			if ( is_array( $fee ) && 'Pre-Order Fee' == $fee['name'] ) {
				return doubleval( $fee['line_total'] ) + doubleval( $fee['line_tax'] );
			}
		}
	}
	/**
	 * @param string $order_id
	 * @return bool
	 */
	public function order_contains_pre_order( $order_id ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			return WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
		}
		return false;
	}

	/**
	 * @param string $order_id
	 *
	 * @return bool
	 */
	public function order_requires_payment_tokenization( $order_id ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			return WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id );
		}
		return false;
	}

	/**
	 * @return bool
	 */
	public function cart_contains_pre_order_fee() {
		if ( class_exists( 'WC_Pre_Orders_Cart' ) ) {
			return WC_Pre_Orders_Cart::cart_contains_pre_order_fee();
		}
		return false;
	}
	/**
	 * Store the Payfast subscription token
	 *
	 * @param string $token
	 * @param WC_Subscription $subscription
	 */
	protected function _set_subscription_token( $token, $subscription ) {
		$subscription->update_meta_data( '_appture_pay_subscription_token', $token );
		$subscription->save_meta_data();
	}

	/**
	 * Retrieve the Payfast subscription token for a given order id.
	 *
	 * @param WC_Subscription $subscription
	 * @return mixed
	 */
	protected function _get_subscription_token( $subscription ) {
		return $subscription->get_meta( '_appture_pay_subscription_token', true );
	}

	/**
	 * Retrieve the Payfast subscription token for a given order id.
	 *
	 * @param WC_Subscription $subscription
	 * @return mixed
	 */
	protected function _delete_subscription_token( $subscription ) {
		return $subscription->delete_meta_data( '_appture_pay_subscription_token' );
	}

	/**
	 * Store the Payfast renewal flag
	 * @since 1.4.3
	 *
	 * @param string $token
	 * @param WC_Subscription $subscription
	 */
	protected function _set_renewal_flag( $subscription ) {
		$subscription->update_meta_data( '_appture_pay_renewal_flag', 'true' );
		$subscription->save_meta_data();
	}

	/**
	 * Retrieve the Payfast renewal flag for a given order id.
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	protected function _has_renewal_flag( $subscription ) {
		return 'true' === $subscription->get_meta( '_appture_pay_renewal_flag', true );
	}

	/**
	 * Retrieve the Payfast renewal flag for a given order id.
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription
	 * @return mixed
	 */
	protected function _delete_renewal_flag( $subscription ) {
		$subscription->delete_meta_data( '_appture_pay_renewal_flag' );
		$subscription->save_meta_data();
	}

	/**
	 * Store the Payfast pre_order_token token
	 *
	 * @param string   $token
	 * @param WC_Order $order
	 */
	protected function _set_pre_order_token( $token, $order ) {
		$order->update_meta_data( '_appture_pay_pre_order_token', $token );
		$order->save_meta_data();
	}

	/**
	 * Retrieve the Payfast pre-order token for a given order id.
	 *
	 * @param WC_Order $order
	 * @return mixed
	 */
	protected function _get_pre_order_token( $order ) {
		return $order->get_meta( '_appture_pay_pre_order_token', true );
	}

	/**
	 * Wrapper for WooCommerce subscription function wc_is_subscription.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function is_subscription( $order ) {
		if ( ! function_exists( 'wcs_is_subscription' ) ) {
			return false;
		}
		return wcs_is_subscription( $order );
	}

    /**
     * Wrapper function for wcs_order_contains_subscription
     *
     * @param WC_Order $order
     * @return bool
     */
    public function order_contains_subscription($order) {
        if (!function_exists('wcs_order_contains_subscription')) {
            return false;
        }
        return wcs_order_contains_subscription($order);
    }

    /**
	 * @param $amount_to_charge
	 * @param WC_Order $renewal_order
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

		$subscription = wcs_get_subscription( $renewal_order->get_meta( '_subscription_renewal', true ) );
		$this->log( 'Attempting to renew subscription from renewal order ' . self::get_order_prop( $renewal_order, 'id' ) );

		if ( empty( $subscription ) ) {
			$this->log( 'Subscription from renewal order was not found.' );
			return;
		}

        $this->log( 'Continue to submit_subscription_payment.' );
		$response = $this->submit_subscription_payment( $subscription, $amount_to_charge );

		if ( is_wp_error( $response ) ) {
			/* translators: 1: error code 2: error message */
			$renewal_order->update_status( 'failed', sprintf( esc_html__( 'Appture Pay Subscription renewal transaction failed (%1$s:%2$s)', 'woocommerce-gateway-appture-pay' ), $response->get_error_code() ,$response->get_error_message() ) );
		}
		// Payment will be completed only when the ITN callback is sent to $this->handle_itn_request().
		$renewal_order->add_order_note( esc_html__( 'Appture Pay Subscription renewal transaction submitted.', 'woocommerce-gateway-appture-pay' ) );

	}

	/**
	 * @param WC_Subscription $subscription
	 * @param $amount_to_charge
	 * @return mixed WP_Error on failure, bool true on success
	 */
	public function submit_subscription_payment( $subscription, $amount_to_charge ) {
		$token = $this->_get_subscription_token( $subscription );
        $this->log( 'submit_subscription_payment: token: '.$token );
		$item_name = $this->get_subscription_name( $subscription );
        $this->log( 'submit_subscription_payment: item_name: '.$item_name );

        $latest_order_to_renew = null;
		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order ) {
			$statuses_to_charge = array( 'on-hold', 'failed', 'pending' );
			if ( in_array( $order->get_status(), $statuses_to_charge ) ) {
				$latest_order_to_renew = $order;
				break;
			}
		}
		$item_description = 'Renewal order ('. self::get_order_prop( $latest_order_to_renew, 'id' ).')';
        $this->log( 'submit_subscription_payment: item_description: '.$item_description );

		return $this->submit_ad_hoc_payment( $token, $amount_to_charge, $item_name, $item_description );
	}

	/**
	 * Get a name for the subscription item. For multiple
	 * item only Subscription $date will be returned.
	 *
	 * For subscriptions with no items Site/Blog name will be returned.
	 *
	 * @param WC_Subscription $subscription
	 * @return string
	 */
	public function get_subscription_name( $subscription ) {

		if ( $subscription->get_item_count() > 1 ) {
			return $subscription->get_date_to_display( 'start' );
		} else {
			$items = $subscription->get_items();

			if ( empty( $items ) ) {
				return get_bloginfo( 'name' );
			}

			$item = array_shift( $items );
			return $item['name'];
		}
	}

	/**
	 * Setup api data for the the adhoc payment.
	 *
	 * @since 1.4.0 introduced.
	 * @param string $token
	 * @param double $amount_to_charge
	 * @param string $item_name
	 * @param string $item_description
	 *
	 * @return bool|WP_Error
	 */
	public function submit_ad_hoc_payment( $token, $amount_to_charge, $item_name, $item_description ) {

        $recurringTransactionId = $token;

        $this->log("Attempt to load Recurring Transaction: " . $token );

        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";

        $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
        $api->clearSession();

        $response = $api->recurringTransactionGetSpecific($recurringTransactionId);

        // Could we load the transaction?
        if($response && $response["success"]) {

            // success
            $this->log("Recurring Transaction loaded: " . print_r( $response["data"], true ) );

            $recurringTransaction = $response["data"];

            if($recurringTransaction['active']) {

                $response = $api->recurringTransactionPut($recurringTransactionId, [
                    'day_of_debit' => date('j'),
                    'force_immediate_debit' => 1,
                    'total' => $amount_to_charge,
                    'description' => $item_description
                ]);

                // Could we update the transaction?
                if($response && $response["success"]) {

                    // success
                    $this->log("Recurring Transaction updated");

                    // now charge the transaction
                    $response = $api->recurringTransactionPutCharge($recurringTransactionId);

                    // Could we charge the transaction?
                    if ($response && $response["success"]) {

                        // success
                        $this->log("Recurring Transaction charged: " . print_r($response["message"], true));

                    } else {

                        // fail
                        $this->log("Failed to charge Recurring Transaction: " . print_r($response, true));

                    }

                } else {

                    // fail
                    $this->log("Failed to update Recurring Transaction: " . print_r($response, true));

                }

            } else {

                // fail
                $this->log("Failed to charge Recurring Transaction (Transaction inactive): " . print_r( $response, true ) );

            }

        } else {

            // fail
            $this->log("Failed to charge Recurring Transaction: " . print_r( $response, true ) );

        }

	}
    
    /**
	 * Displays the amount_fee as returned by payfast.
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_fee( $order_id ) {

		$order = wc_get_order( $order_id );
		$fee   = $order->get_meta( 'payfast_amount_fee', true );

		if ( ! $fee ) {
			return;
		}
		?>

		<tr>
			<td class="label payfast-fee">
				<?php echo wc_help_tip( __( 'This represents the fee Payfast collects for the transaction.', 'woocommerce-gateway-payfast' ) ); ?>
				<?php esc_html_e( 'Payfast Fee:', 'woocommerce-gateway-payfast' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wp_kses_post( wc_price( $fee, array( 'decimals' => 2 ) ) ); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Displays the amount_net as returned by payfast.
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_net( $order_id ) {

		$order = wc_get_order( $order_id );
		$net   = $order->get_meta( 'payfast_amount_net', true );

		if ( ! $net ) {
			return;
		}

		?>

		<tr>
			<td class="label payfast-net">
				<?php echo wc_help_tip( __( 'This represents the net total that was credited to your Payfast account.', 'woocommerce-gateway-payfast' ) ); ?>
				<?php esc_html_e( 'Amount Net:', 'woocommerce-gateway-payfast' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wp_kses_post( wc_price( $net, array( 'decimals' => 2 ) ) ); ?>
			</td>
		</tr>

		<?php
	}

    /**
     * amounts_equal()
     *
     * Checks to see whether the given amounts are equal using a proper floating
     * point comparison with an Epsilon which ensures that insignificant decimal
     * places are ignored in the comparison.
     *
     * eg. 100.00 is equal to 100.0001
     *
     * @author Jonathan Smit
     * @param $amount1 Float 1st amount for comparison
     * @param $amount2 Float 2nd amount for comparison
     * @since 1.0.0
     * @return bool
     */
    public function amounts_equal($amount1, $amount2) {
        return !( abs(floatval($amount1) - floatval($amount2)) > PF_EPSILON );
    }

    /**
     * Get order property with compatibility check on order getter introduced
     * in WC 3.0.
     *
     * @since 1.4.1
     *
     * @param WC_Order $order Order object.
     * @param string   $prop  Property name.
     *
     * @return mixed Property value
     */
    public static function get_order_prop( $order, $prop ) {
        switch ( $prop ) {
            case 'order_total':
                $getter = array( $order, 'get_total' );
                break;
            default:
                $getter = array( $order, 'get_' . $prop );
                break;
        }

        return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
   }
   
   /**
	 * Responds to Subscriptions extension cancellation event.
	 *
	 * @since 1.4.0 introduced.
	 * @param WC_Subscription $subscription
	 */
	public function cancel_subscription_listener( $subscription ) {
		$recurringTransactionId = $this->_get_subscription_token( $subscription );

        // log
        $this->log("Recurring Transaction cancel_subscription_listener: " . print_r( [$recurringTransactionId,$subscription], true ) );

		if ( empty( $recurringTransactionId ) ) {
			return;
		}
		
        $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
        $api->clearSession();
        $response = $api->recurringTransactionPut($recurringTransactionId, ['active' => 0]);
        
        // Could we load the transaction?
        if($response && $response["success"]) {
            
            // success
            $this->log("Recurring Transaction deactivated: " . print_r( $response["data"], true ) );
            
        } else {
            
            // fail
            $this->log("Failed to deactivate Recurring Transaction: " . print_r( $response, true ) );
            
        }
	}

	/**
	 * @since 1.4.0
	 * @param string $token
	 *
	 * @return bool|WP_Error
	 */
	public function cancel_pre_order_subscription( $token ) {
		$recurringTransactionId = $token;
		
        $api = new ApptureLab\AppturePayAPI($this->client_id, $this->client_secret);
        $api->clearSession();
        $response = $api->recurringTransactionPut($recurringTransactionId, ['active' => 0]);
        
        // Could we load the transaction?
        if($response && $response["success"]) {
            
            // success
            $this->log("Recurring Transaction deactivated: " . print_r( $response["data"], true ) );
            return true;
            
        } else {
            
            // fail
            $this->log("Failed to deactivate Recurring Transaction: " . print_r( $response, true ) );
            
        }

        return false;
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