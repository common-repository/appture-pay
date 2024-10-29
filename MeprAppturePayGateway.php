<?php
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}


class MeprAppturePayGateway extends MeprBaseRealGateway {
    
    private $txn = null; // mepr transaction
    private $sub = null; // mepr subscription
    private $transaction = null; // appture pay
    private $recurring_transaction = null; // appture pay
    
    /** Used in the view to identify the gateway */
    public function __construct() {
        $this->name = "Appture Pay";
        $this->has_spc_form = false;

        $this->set_defaults();

        $this->capabilities = array(
            'process-payments',
            //'process-refunds',
            'create-subscriptions',
            'cancel-subscriptions',
            'update-subscriptions',
            'suspend-subscriptions',
            'resume-subscriptions',
            'subscription-trial-payment',
            //'send-cc-expirations'
        );

        // Setup the notification actions for this gateway
        $this->notifiers = array(
            'callback' => 'callback',
            'redirect' => 'redirect'
        );
        $this->message_pages = array('cancel' => 'cancel_message', 'failed' => 'failed_message');
    }
    
    public function load($settings) {
        $this->settings = (object) $settings;
        $this->set_defaults();
    }

    protected function set_defaults() {
        if (!isset($this->settings))
            $this->settings = array();

        $this->settings = (object) array_merge(
            array(
                'gateway' => 'MeprAppturePayGateway',
                'id' => $this->generate_id(),
                'label' => '',
                'use_label' => true,
                'icon' => plugin_dir_url( __FILE__ ). "img/logo_wordpress_20.png",
                'use_icon' => true,
                'desc' => 'Pay using your Visa or Master card',
                'use_desc' => true,
                
                'dark_mode' => false,
                
                'cancellation_fee' => 'none', // none || one || all
                
                'client_id' => '',
                'client_secret' => '',
                'url' => 'https://www.appturepay.com/gateway',//"http://localhost/appturepay/ui.payment_gateway/",//
                
                'debug' => false
            ), (array) $this->settings
        );

        $this->id = $this->settings->id;
        $this->label = $this->settings->label;
        $this->use_label = $this->settings->use_label;
        $this->icon = $this->settings->icon;
        $this->use_icon = $this->settings->use_icon;
        $this->desc = $this->settings->desc;
        $this->use_desc = $this->settings->use_desc;
        
        $this->settings->client_id = trim($this->settings->client_id);
        $this->settings->client_secret = trim($this->settings->client_secret);
    }
    
    /** This gets called on the 'init' hook when the signup form is processed ...
     * this is in place so that payment solutions like paypal can redirect
     * before any content is rendered.
     */
    public function display_payment_page($txn) {
        $this->log("Call: ".__FUNCTION__);
        
        $mepr_options = MeprOptions::fetch();

        if (isset($txn) and $txn instanceof MeprTransaction) {
            $usr = $txn->user();
            $prd = $txn->product();
            $sub = $txn->subscription();
        } else {
            return false;
        }

        if ($txn->amount <= 0.00) {
            // Take care of this in display_payment_page
            //MeprTransaction::create_free_transaction($txn);
            return $txn->checkout_url();
        }

        if ($txn->gateway == $this->id) {
            $mepr_options = MeprOptions::fetch(); // Dewald: why fetch twice?
            
            $user_meta = get_user_meta($usr->ID);
            
            // Construct transaction to create via Appture Pay API
            $transaction = array(
                'client_id' => $this->settings->client_id,
                'reference' => "SUB".str_pad($sub->ID,6,'0',STR_PAD_LEFT),
                'description' => $prd->post_title,
                'transaction_type' => ($prd->is_one_time_payment() ? "DB" : "RECURRING_DB"),
                'total' => MeprUtils::format_float($txn->total),
                'recurring_term' => ($prd->is_one_time_payment() ? 0 : 1),
                'max_recur' => ($prd->limit_cycles ? $prd->limit_cycles_num : 0),
                
                'day_of_debit' => isset($user_meta["mepr_day_of_debit"]) ? $user_meta["mepr_day_of_debit"][0] : null,
                
                // add billing info
                "billing_street" => isset($user_meta["mepr-address-one"]) ? $user_meta["mepr-address-one"][0] : null,
                "billing_suburb" => isset($user_meta["mepr-address-two"]) ? $user_meta["mepr-address-two"][0] : null,
                "billing_city" => isset($user_meta["mepr-address-city"]) ? $user_meta["mepr-address-city"][0] : null,
                "billing_province" => isset($user_meta["mepr-address-state"]) ? $user_meta["mepr-address-state"][0] : null,
                "billing_country" => isset($user_meta["mepr-address-country"]) ? $user_meta["mepr-address-country"][0] : null,
                "billing_postal_code" => isset($user_meta["mepr-address-zip"]) ? $user_meta["mepr-address-zip"][0] : null,
                //"billing_phone" => "",
                "billing_email" => $usr->user_email,
                "billing_first_name" => $usr->first_name,
                "billing_last_name" => $usr->last_name,
                //"billing_company" => "",
            );
            
            $this->log("Appture Pay Response Request: ".print_r($transaction, 1));
            $this->email_status("MemberPress Appture Pay Request: \n" . MeprUtils::object_to_string($transaction, true) . "\n", $this->settings->debug);
            
            include_once __DIR__. "/includes/ApptureWebRequest.php";
            include_once __DIR__. "/includes/AppturePayAPI.php";
            $api = new \ApptureLab\AppturePayAPI($this->settings->client_id, $this->settings->client_secret);
            
            $this->log("Appture Pay Authenticated: ".($api->getSession() === null ? print_r($api->getAuthError(),1) : "Yes"));
            
            $response = $api->transactionPost($transaction);
            
            $this->log("Appture Pay Response Object: ".print_r($response, 1));
            $this->email_status("Appture Pay Response Object: \n" . MeprUtils::object_to_string($response, true) . "\n", $this->settings->debug);
            
            if($response && $response["success"]) {
        
                // get the transaction identifier from the response
                $txn->trans_num = $response["data"]["identifier"];
                $txn->store();

                /*if (!$prd->is_one_time_payment() && ($sub = $txn->subscription())) {
                    $sub->token = $txn->trans_num;
                    $sub->store();
                }*/

                MeprUtils::wp_redirect("{$this->settings->url}?id={$txn->trans_num}".($this->settings->dark_mode ? "&d" : ""));
            } else {
                throw new Exception('The connection to Appture Pay failed');
            }
        }

        throw new Exception(__('There was a problem completing the transaction', 'memberpress'));
    }

    /** This gets called on wp_enqueue_script and enqueues a set of
     * scripts for use on the page containing the payment form
     */
    public function enqueue_payment_form_scripts() {
        // No need, handled on the Appture Pay side
        $this->log("Call: ".__FUNCTION__);
    }

    /** This gets called on the_content and just renders the payment form */
    public function display_payment_form($amount, $user, $product_id, $transaction_id) {
        // Handled on the Appture Pay site so we don't have a need for it here
        $this->log("Call: ".__FUNCTION__);
    }

    /** Validates the payment form before a payment is processed */
    public function validate_payment_form($errors) {
        // Appture Pay does this on their own form
        $this->log("Call: ".__FUNCTION__);
    }

    /** Displays the form for the given payment gateway on the MemberPress Options page */
    public function display_options_form() {
        $mepr_options = MeprOptions::fetch();

        $client_id = trim($this->settings->client_id);
        $client_secret = trim($this->settings->client_secret);
        $dark_mode = ($this->settings->dark_mode=='on' or $this->settings->dark_mode==true);
        $debug = ($this->settings->debug=='on' or $this->settings->debug==true);
        ?>
            <table>
                <tr>
                    <td colspan="2"><input type="checkbox" name="<?php echo esc_attr($mepr_options->integrations_str); ?>[<?php echo esc_attr($this->id); ?>][dark_mode]"<?php echo checked($dark_mode); ?> />&nbsp;<?php _e('Enable Dark Theme for Gateway', 'memberpress'); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Cancellation fee type:', 'memberpress'); ?></td>
                    <td>
                        <select name="<?php echo esc_attr($mepr_options->integrations_str); ?>[<?php echo esc_attr($this->id); ?>][cancellation_fee]">
                            <option <?php $this->settings->cancellation_fee === 'none' ? 'selected' : '' ?> value="none">None</option>
                            <option <?php $this->settings->cancellation_fee === 'one' ? 'selected' : '' ?> value="one">One more payment</option>
                            <option <?php $this->settings->cancellation_fee === 'all' ? 'selected' : '' ?> value="all">All outstanding payments</option>
                        </select>
                        <?php MeprAppHelper::info_tooltip("cancellation_fee_type_tooltip", "Cancellation fee type", "Set what to do on cancellation of subscription.<ul style='list-style: disc; margin-left: 40px'><li>None: do nothing</li><li>One more payment: Charge once more on next debit date</li><li>All outstanding payments: Charge total outstanding payments immediately (in case no max is set it will charge only once more)</li></ul>"); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('API Client ID*:', 'memberpress'); ?></td>
                    <td><input type="text" class="mepr-auto-trim" name="<?php echo esc_attr($mepr_options->integrations_str); ?>[<?php echo esc_attr($this->id); ?>][client_id]" value="<?php echo esc_attr($client_id); ?>" /></td>
                </tr>
                <tr>
                    <td><?php _e('API Client Secret*:', 'memberpress'); ?></td>
                    <td><input type="text" class="mepr-auto-trim" name="<?php echo esc_attr($mepr_options->integrations_str); ?>[<?php echo esc_attr($this->id); ?>][client_secret]" value="<?php echo esc_attr($client_secret); ?>" /></td>
                </tr>
                <tr>
                    <td><?php _e('API Client Callback Url:', 'memberpress'); ?></td>
                    <td><?php MeprAppHelper::clipboard_input($this->notify_url('callback')); ?></td>
                </tr>
                <tr>
                    <td><?php _e('API Client Redirect Url:', 'memberpress'); ?></td>
                    <td><?php MeprAppHelper::clipboard_input($this->notify_url('redirect')); ?></td>
                </tr>
                <tr>
                    <td colspan="2"><input type="checkbox" name="<?php echo esc_attr($mepr_options->integrations_str); ?>[<?php echo esc_attr($this->id); ?>][debug]"<?php echo checked($debug); ?> />&nbsp;<?php _e('Enable Debug Logs and Emails', 'memberpress'); ?></td>
                </tr>
        <?php MeprHooks::do_action('mepr-appture-pay-options-form', $this); ?>
            </table>
        <?php
    }

    /** Validates the form for the given payment gateway on the MemberPress Options page */
    public function validate_options_form($errors) {
        $mepr_options = MeprOptions::fetch();
        
        $error = false;
        if (!isset($_POST[$mepr_options->integrations_str][$this->id]['client_id']) ||
                empty($_POST[$mepr_options->integrations_str][$this->id]['client_id'])) {
            $errors[] = __("Appture Pay API Client ID field can't be blank.", 'memberpress'); 
        } else {
            $error = true;
        }
        
        if (!isset($_POST[$mepr_options->integrations_str][$this->id]['client_secret']) ||
                empty($_POST[$mepr_options->integrations_str][$this->id]['client_secret'])) {
            $errors[] = __("Appture Pay API Client Secret field can't be blank.", 'memberpress');
        } else {
            $error = true;
        }
        
        if(!$error) {
            
            include_once __DIR__. "/includes/ApptureWebRequest.php";
            include_once __DIR__. "/includes/AppturePayAPI.php";
            $api = new ApptureLab\AppturePayAPI($_POST[$mepr_options->integrations_str][$this->id]['client_id'], $_POST[$mepr_options->integrations_str][$this->id]['client_secret']);
            $api->clearSession();

            if( $api->getSession() === null) {
                $errors[] = __("Appture Pay API Authentication failed with provided Client ID and Secret.", 'memberpress');
            }
            
        }

        return $errors;
    }

    /** Displays the update account form on the subscription account page **/
    public function display_update_account_form($subscription_id, $errors = array(), $message = "") {
        ?>
        <h3>Updating your Appture Pay Account Information</h3>
        <div>To update your Appture Pay Account Information, please go to <a href="http://appturepay.com" target="blank">AppturePay.com</a>, login and edit your account information there.</div>
        <?php
        $this->log("Call: ".__FUNCTION__);
    }
    
    /** Validates the payment form before a payment is processed */
    public function validate_update_account_form($errors = array()) {
        // We'll have them update their cc info on appturepay.com
        $this->log("Call: ".__FUNCTION__);
    }

    /** Actually pushes the account update to the payment processor */
    public function process_update_account_form($sub_id) {
        // We'll have them update their cc info on paypal.com
        $this->log("Call: ".__FUNCTION__);
    }

    /** Returns boolean ... whether or not we should be sending in test mode or not */
    public function is_test_mode() {
        $this->log("Call: ".__FUNCTION__);
        return false; // appture pay sandbox mode depends on the api client used
    }

    public function force_ssl() {
        $this->log("Call: ".__FUNCTION__);
        return false; // redirects off site where ssl is installed
    }
    
    public function redirect() {
        $this->log("Call: ".__FUNCTION__);
        
        $data = array(
            "id" => filter_input(INPUT_GET, "id"),
            "recurring_transaction_id" => filter_input(INPUT_GET, "recurring_transaction_id")
        );
        
        if($this->settings->debug) {
            $this->email_status("Appture Pay Redirect\n" . MeprUtils::object_to_string($data, true) . "\n", $this->settings->debug);

            $this->log( PHP_EOL
                . '----------'
                . PHP_EOL . 'Appture Pay Redirect'
                . PHP_EOL . '----------'
            );
            $this->log( 'Appture Pay Data: ' . print_r( $data, true ) );
        }

        $mepr_options = MeprOptions::fetch();
        
        $id = !empty($data["id"]) ? $data["id"] : null; // transaction identifier
        $recurring_transaction_id = !empty($data["recurring_transaction_id"]) ? $data["recurring_transaction_id"] : null;
        $this->transaction = null;
        $this->recurring_transaction = null;
        $this->sub = null;
        $reference = null;
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new \ApptureLab\AppturePayAPI($this->settings->client_id, $this->settings->client_secret);
        
        $this->log("Appture Pay Authenticated: ".($api->getSession() === null ? print_r($api->getAuthError(),1) : "Yes"));
        
        if($id) {
            $response = $api->transactionGetSpecific($id);
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->transaction = $response["data"];
                $this->log("Transaction loaded: " . print_r( $this->transaction, true ) );
            }
        }
        
        if($recurring_transaction_id) {
            $response = $api->recurringTransactionGetSpecific($recurring_transaction_id);
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->recurring_transaction = $response["data"];
                $this->log("Recurring Transaction loaded: " . print_r( $this->recurring_transaction, true ) );
            }
        }
        
        // get reference either from the recurring_transaction or from the transaction
        $reference = ($this->transaction ? $this->transaction["reference"] : $this->recurring_transaction["reference"]);
        
        // get sub id from reference and load transaction
        $sub_id = intval( str_replace( "SUB", "", $reference ) );
        $this->sub = new MeprSubscription();
        $subData = MeprSubscription::get_one( $sub_id );
        $this->sub->load_data($subData);
        
        // set to null if no id is loaded
        if($this->sub->id === 0) {
            $this->sub = null;
        }
        
        $this->log("Mepr Subscription loaded: " . print_r( $this->sub, true ) );
        
        if($this->transaction && $this->sub) {
        
            // now we have all data required so we can process the redirect
            
            $obj = MeprTransaction::get_one_by_trans_num($this->transaction["identifier"]);
            
            $txn = new MeprTransaction();
            $txn->load_data($obj);
            
            if($txn->id !== 0) {
                
                $this->email_status("Mepr Transaction \$txn:\n" . MeprUtils::object_to_string($txn, true) . "\n", $this->settings->debug);
                
                if($this->transaction["status"] === "USER_CANCELLED") {
                    
                    $prd = new MeprProduct($txn->product_id);
                    MeprUtils::wp_redirect($this->message_page_url($prd,'cancel'));
                    
                } else if($this->transaction["status"] === "FAILED") {
                    
                    $prd = new MeprProduct($txn->product_id);
                    MeprUtils::wp_redirect($this->message_page_url($prd,'failed'));
                    
                } else if($this->transaction["status"] === "SUCCESS") {
                
                    try {

                        $this->process_payment_form($txn);
                        $txn = new MeprTransaction($txn->id); //Grab the txn again, now that we've updated it
                        $product = new MeprProduct($txn->product_id);
                        $sanitized_title = sanitize_title($product->post_title);
                        $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
                        if ($this->sub) {
                            $query_params = array_merge($query_params, array('subscr_id' => $this->sub->subscr_id));
                        }
                        MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));

                    } catch (Exception $e) {

                        $prd = $txn->product();
                        MeprUtils::wp_redirect($prd->url('?action=payment_form&txn=' . $txn->trans_num . '&message=' . $e->getMessage() . '&_wpnonce=' . wp_create_nonce('mepr_payment_form')));

                    }
                    
                }
                
            }
            
        }
    }

    /** Listens for an incoming connection from Appture Pay and then handles the request appropriately. */
    public function callback() {
        $this->log("Call: ".__FUNCTION__);
        
        $data = array(
            "id" => filter_input(INPUT_GET, "id"),
            "recurring_transaction_id" => filter_input(INPUT_GET, "recurring_transaction_id"),
            "action" => filter_input(INPUT_GET, "action")
        );
        
        if($this->settings->debug) {
            $this->email_status("Appture Pay Callback Recieved\n" . MeprUtils::object_to_string($data, true) . "\n", $this->settings->debug);

            $this->log( PHP_EOL
                . '----------'
                . PHP_EOL . 'Appture Pay Callback received'
                . PHP_EOL . '----------'
            );
            $this->log( 'Appture Pay Data: ' . print_r( $data, true ) );
        }
        
        $id = !empty($data["id"]) ? $data["id"] : null; // transaction identifier
        $recurring_transaction_id = !empty($data["recurring_transaction_id"]) ? $data["recurring_transaction_id"] : null;
        $this->transaction = null;
        $this->recurring_transaction = null;
        $this->sub = null;
        $reference = null;
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new \ApptureLab\AppturePayAPI($this->settings->client_id, $this->settings->client_secret);
        
        $this->log("Appture Pay Authenticated: ".($api->getSession() === null ? print_r($api->getAuthError(),1) : "Yes"));
        
        if($id) {
            $response = $api->transactionGetSpecific($id);
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->transaction = $response["data"];
                $this->log("Transaction loaded: " . print_r( $this->transaction, true ) );
            }
        }
        
        if($recurring_transaction_id) {
            $response = $api->recurringTransactionGetSpecific($recurring_transaction_id);
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->recurring_transaction = $response["data"];
                $this->log("Recurring Transaction loaded: " . print_r( $this->recurring_transaction, true ) );
            }
        }
        
        // get reference either from the recurring_transaction or from the transaction
        $reference = ($this->transaction ? $this->transaction["reference"] : $this->recurring_transaction["reference"]);
        
        // get sub id from reference and load transaction
        $sub_id = intval( str_replace( "SUB", "", $reference ) );
        $this->sub = new MeprSubscription();
        $subData = MeprSubscription::get_one( $sub_id );
        $this->sub->load_data($subData);
        
        // set to null if no id is loaded
        if($this->sub->id === 0) {
            $this->sub = null;
        }
        
        $this->log("Mepr Subscription loaded: " . print_r( $this->sub, true ) );
        
        // now we have all data required so we can process the callback
        
        // this action indicates that a transaction has been completed
        if( 'DEBIT' === $data["action"] ) {
            
            if($this->transaction && $this->sub) {
                    
                if ('SUCCESS' === $this->transaction["status"]) {

                    $this->record_subscription_payment();

                }

                if ('FAILED' === $this->transaction["status"]) {

                    if( $this->recurring_transaction ) {
                        if($this->recurring_transaction["active"]) {
                            $this->record_payment_failure();
                        } else {
                            $this->record_suspend_subscription();
                        }
                    } else {
                        // once off payment
                        $this->record_payment_failure();
                    }
                }
                
            }

        }

        // this action indicates that a recurring transaction has been updated
        if( 'UPDATED' === $data["action"] ) {
            
            if( $this->recurring_transaction ) {
                
                // if not active and deactivation_date is today, it has been
                // paused or suspended
                if( $this->recurring_transaction["active"] == 0
                        && date("Y-m-d", strtotime($this->recurring_transaction["deactivation_date"])) === date("Y-m-d") ) {
                    $this->record_suspend_subscription();
                } else
                // if active and activation_date is today, it has been resumed
                if( $this->recurring_transaction["active"] == 1
                        && date("Y-m-d", strtotime($this->recurring_transaction["activation_date"])) === date("Y-m-d") ) {
                    $this->record_resume_subscription();
                } else
                // if cancellation_date is today, it has been cancelled
                // (active may not be 0 yet as it will only be deactivated when
                // the final debit has been successful or failed 3 times)
                if( date("Y-m-d", strtotime($this->recurring_transaction["cancellation_date"])) === date("Y-m-d") ) {
                    $this->record_cancel_subscription();
                }
            
            }
            
        }
        
    }
    
    /** Used to record a successful recurring payment by the given gateway. It
     * should have the ability to record a successful payment or a failure. It is
     * this method that should be used when receiving an IPN from PayPal or a
     * Silent Post from Authorize.net.
     */
    public function record_subscription_payment() {
        $this->log("Call: ".__FUNCTION__);
        
        if($this->transaction && $this->sub) { // we should not be here if these are not set
            
            $timestamp = strtotime($this->transaction["update_date"]);
            $first_txn = $this->sub->first_txn();

            if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
                $first_txn = new MeprTransaction();
                $first_txn->user_id = $this->sub->user_id;
                $first_txn->product_id = $this->sub->product_id;
                $first_txn->coupon_id = $this->sub->coupon_id;
            }

            //Prevent recording duplicates
            $existing_txn = MeprTransaction::get_one_by_trans_num($this->transaction["identifier"]);
            if (isset($existing_txn->id) &&
                    $existing_txn->id > 0 &&
                    in_array($existing_txn->status, array(MeprTransaction::$complete_str, MeprTransaction::$confirmed_str))) {
                return;
            }

            //If this is a trial payment, let's just convert the confirmation txn into a payment txn
            //then we won't have to mess with setting expires_at as it was already handled
            if ($this->is_subscr_trial_payment($this->sub)) {
                $txn = $first_txn; //For use below in send notices
                $txn->created_at = MeprUtils::ts_to_mysql_date($timestamp);
                $txn->gateway = $this->id;
                $txn->trans_num = $this->transaction["identifier"];
                $txn->txn_type = MeprTransaction::$payment_str;
                $txn->status = MeprTransaction::$complete_str;
                $txn->subscription_id = $this->sub->id;

                $txn->set_gross($this->transaction["total"]);

                $txn->store();
                
            } else {
                
                $txn = new MeprTransaction();
                $txn->created_at = MeprUtils::ts_to_mysql_date($timestamp);
                $txn->user_id = $first_txn->user_id;
                $txn->product_id = $first_txn->product_id;
                $txn->coupon_id = $first_txn->coupon_id;
                $txn->gateway = $this->id;
                $txn->trans_num = $this->transaction["identifier"];
                $txn->txn_type = MeprTransaction::$payment_str;
                $txn->status = MeprTransaction::$complete_str;
                $txn->subscription_id = $this->sub->id;

                $txn->set_gross($this->transaction["total"]);

                $txn->store();
                
                $this->log("Mepr Subscription: ".print_r($this->sub,1));

                // Check that the subscription status is still enabled, if not cancelling
                if ( $first_txn->trans_num === $this->transaction["identifier"]
                        && $this->sub->status != MeprSubscription::$active_str ) {
                    
                    $this->sub->status = MeprSubscription::$active_str;
                    $this->sub->store();
                    
                }
                
                if( $this->sub->status != MeprSubscription::$cancelled_str
                        && $this->recurring_transaction
                        && $this->recurring_transaction["cancellation_date"] != null ) {
                    
                    $this->sub->status = MeprSubscription::$cancelled_str;
                    $this->sub->store();
                    
                }

                // Not waiting for an IPN here bro ... just making it happen even though
                // the total occurrences is already capped in record_create_subscription()
                //$this->sub->limit_payment_cycles();
            }
            
            // save the recurring_transaction id as the subscription token
            if( $this->recurring_transaction && $this->sub->token == null ) {
                $this->sub->token = $this->recurring_transaction["id"];
                $this->sub->store();
            }

            $this->email_status("Subscription Transaction\n" .
                    MeprUtils::object_to_string($txn->rec, true), $this->settings->debug);

            MeprUtils::send_transaction_receipt_notices($txn);

            return $txn;
            
        }

        return false;
    }

    /** Used to record a declined payment. */
    public function record_payment_failure() {
        $this->log("Call: ".__FUNCTION__);
        
        if($this->transaction && $this->txn) { // we should not be here if these are not set
            
            $this->txn->status = MeprTransaction::$failed_str;
            $this->txn->store();
            
            MeprUtils::send_failed_txn_notices($txn);
            
            return $this->txn;
            
        }
        
        return false;
        
    }
    
    /** Record a successful cancellation from the gateway callback.
     * 
     *  Cancel - Use to cancel the Subscription (thus preventing future billings
     *  that cannot be re-initiated without re-registering) on the level of the 
     *  gateway. Will only work properly if you have your gateway correctly 
     *  configured.
     */
    public function record_cancel_subscription() {
        $this->log("Call: ".__FUNCTION__);
    }

    /** Process a cancellation from the subscriptions interface.
     * 
     *  Cancel - Use to cancel the Subscription (thus preventing future billings
     *  that cannot be re-initiated without re-registering) on the level of the 
     *  gateway. Will only work properly if you have your gateway correctly 
     *  configured.
     */
    public function process_cancel_subscription($subscription_id) {
        $this->log("Call: " . __FUNCTION__);
        
        $this->sub = new MeprSubscription($subscription_id);
        $recurring_transaction_id = $this->sub->token;
        $this->recurring_transaction = null;
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new \ApptureLab\AppturePayAPI($this->settings->client_id, $this->settings->client_secret);
        
        $this->log("Appture Pay Authenticated: ".($api->getSession() === null ? print_r($api->getAuthError(),1) : "Yes"));
        
        if($recurring_transaction_id) {
            $response = $api->recurringTransactionGetSpecific($recurring_transaction_id);
            
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->recurring_transaction = $response["data"];
                $this->log("Recurring Transaction loaded: " . print_r( $this->recurring_transaction, true ) );
            }
        }
        
        if($this->recurring_transaction) {
            
            // now we can set data for the cancellation request
            
            if( $this->settings->cancellation_fee === "none" ) {
                
                // deactivate without cancellation fee
                $data = array(
                    "cancellation_date" => date('Y-m-d'),
                    "active" => 0,
                    "max_recur" => intval($this->recurring_transaction["recur_count"])
                );
                
                // set locally to cancelled
                $this->sub->status = MeprSubscription::$cancelled_str;
                $this->sub->store();
                
            } else if( $this->settings->cancellation_fee === "one" ) {
                
                // cancel with cancellation fee at next debit date
                $data = array(
                    "cancellation_date" => date('Y-m-d'),
                    "max_recur" => intval($this->recurring_transaction["recur_count"]) + 1, // one last debit
                    // "reference" => "", // skip reference to keep subscription id as the ref
                    "description" => "Cancellation fee - ".$this->recurring_transaction["description"],
                    "active" => 1 // must be active for the cancellation debit to go off
                );
                
            } else if( $this->settings->cancellation_fee === "all" ) {
            
                // cancel with cancellation fee, immediate debit
                $data = array(
                    "force_immediate_debit" => 1, // indicate cancellation
                    "max_recur" => intval($this->recurring_transaction["recur_count"]) + 1, // one last debit
                    "day_of_debit" => date('j'), // debit today
                    "total" => (intval($this->recurring_transaction["max_recur"]) > 0 ? ((intval($this->recurring_transaction["max_recur"]) - intval($this->recurring_transaction["recur_count"])) * floatval($this->recurring_transaction["total"])) : floatval($this->recurring_transaction["total"])), // if max_recur is infinite, charge once more else total amount outstanding
                    // "reference" => "", // skip reference to keep subscription id as the ref
                    "description" => "Cancellation fee - ".$this->recurring_transaction["description"],
                    "active" => 1 // must be active for the cancellation debit to go off
                );
            
            }
            
            $response = $api->recurringTransactionPut($recurring_transaction_id, $data);
            
            $this->log("Recurring Transaction updated: " . print_r( $response, true ) );
            
            if($response && $response["success"]) {
                
                return true;
                
            }
        }
        
        return false;
    }

    public function process_create_subscription($transaction) {
        $this->log("Call: ".__FUNCTION__);
    }

    public function record_resume_subscription() {
        $this->log("Call: ".__FUNCTION__);
        
        if($this->sub) {
            
            // set locally to active
            $this->sub->status = MeprSubscription::$active_str;
            $this->sub->store();
            
        }
    }

    public function process_resume_subscription($subscription_id) {
        $this->log("Call: ".__FUNCTION__);
        
        $this->sub = new MeprSubscription($subscription_id);
        $recurring_transaction_id = $this->sub->token;
        $this->recurring_transaction = null;
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new \ApptureLab\AppturePayAPI($this->settings->client_id, $this->settings->client_secret);
        
        $this->log("Appture Pay Authenticated: ".($api->getSession() === null ? print_r($api->getAuthError(),1) : "Yes"));
        
        if($recurring_transaction_id) {
            $response = $api->recurringTransactionGetSpecific($recurring_transaction_id);
            
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->recurring_transaction = $response["data"];
                $this->log("Recurring Transaction loaded: " . print_r( $this->recurring_transaction, true ) );
            }
        }
        
        if($this->recurring_transaction) {
            
            // now we can set data for the activation request
            
            // set locally to active
            $this->sub->status = MeprSubscription::$active_str;
            $this->sub->store();
            
            // activate
            $data = array(
                "active" => 1
            );
            
            $response = $api->recurringTransactionPut($recurring_transaction_id, $data);
            
            $this->log("Recurring Transaction updated: " . print_r( $response, true ) );
            
            if($response && $response["success"]) {
                
                return true;
                
            }
        }
        
        return false;
    }

    public function process_signup_form($txn) {
        $this->log("Call: ".__FUNCTION__);
    }

    public function record_suspend_subscription() {
        $this->log("Call: ".__FUNCTION__);
        
        if($this->sub) {
            
            // set locally to suspended
            $this->sub->status = MeprSubscription::$suspended_str;
            $this->sub->store();
            
        }
    }

    public function process_suspend_subscription($subscription_id) {
        $this->log("Call: ".__FUNCTION__);
        
        $this->sub = new MeprSubscription($subscription_id);
        $recurring_transaction_id = $this->sub->token;
        $this->recurring_transaction = null;
        
        include_once __DIR__. "/includes/ApptureWebRequest.php";
        include_once __DIR__. "/includes/AppturePayAPI.php";
        $api = new \ApptureLab\AppturePayAPI($this->settings->client_id, $this->settings->client_secret);
        
        $this->log("Appture Pay Authenticated: ".($api->getSession() === null ? print_r($api->getAuthError(),1) : "Yes"));
        
        if($recurring_transaction_id) {
            $response = $api->recurringTransactionGetSpecific($recurring_transaction_id);
            
            // Could we load the recurring transaction?
            if($response && $response["success"]) {
                $this->recurring_transaction = $response["data"];
                $this->log("Recurring Transaction loaded: " . print_r( $this->recurring_transaction, true ) );
            }
        }
        
        if($this->recurring_transaction) {
            
            // now we can set data for the deactivation request
            
            // set locally to suspended
            $this->sub->status = MeprSubscription::$suspended_str;
            $this->sub->store();
            
            // deactivate
            $data = array(
                "active" => 0
            );
            
            $response = $api->recurringTransactionPut($recurring_transaction_id, $data);
            
            $this->log("Recurring Transaction updated: " . print_r( $response, true ) );
            
            if($response && $response["success"]) {
                
                return true;
                
            }
        }
        
        return false;
    }

    public function process_update_subscription($subscription_id) {
        $this->log("Call: ".__FUNCTION__);
    }

    public function record_update_subscription() {
        $this->log("Call: ".__FUNCTION__);
    }

    public function record_create_subscription() {
        $this->log("Call: ".__FUNCTION__);
    }

    public function process_trial_payment($transaction) {
        $this->log("Call: ".__FUNCTION__);
    }

    public function record_trial_payment($transaction) {
        $this->log("Call: ".__FUNCTION__);
    }
    
    /** Used to send data to a given payment gateway. In gateways which redirect
     * before this step is necessary this method should just be left blank.
     */
    public function process_payment($transaction) {
        $this->log("Call: ".__FUNCTION__);
        // appture pay redirects for payments
    }

    /** Used to record a successful payment by the given gateway. It should have
    * the ability to record a successful payment or a failure. It is this method
    * that should be used when receiving an IPN from PayPal or a Silent Post
    * from Authorize.net.
    */
    public function record_payment() {
        $this->log("Call: ".__FUNCTION__);
        // appture pay redirects for payments
    }

    public function process_refund(\MeprTransaction $txn) {
        $this->log("Call: ".__FUNCTION__);
        // not supported by Appture Pay
    }

    public function record_refund() {
        $this->log("Call: ".__FUNCTION__);
        // not supported by Appture Pay
    }
    
    public function cancel_message() {
        $mepr_options = MeprOptions::fetch();
        ?>
              <h4><?php _e('Your payment at Appture Pay was cancelled.', 'memberpress'); ?></h4>
              <p><?php echo MeprHooks::apply_filters('mepr_appture_pay_cancel_message', sprintf(__('You can retry your purchase by %1$sclicking here%2$s.', 'memberpress'), '<a href="' . MeprUtils::get_permalink() . '">', '</a>')); ?><br/></p>
        <?php
    }
    
    
    
    public function failed_message() {
        $mepr_options = MeprOptions::fetch();
        ?>
              <h4><?php _e('Your payment at Appture Pay failed.', 'memberpress'); ?></h4>
              <p><?php echo MeprHooks::apply_filters('mepr_appture_pay_failed_message', sprintf(__('You can retry your purchase by %1$sclicking here%2$s.', 'memberpress'), '<a href="' . MeprUtils::get_permalink() . '">', '</a>')); ?><br/></p>
        <?php
    }

    /**
     * Log system processes.
     * @since 1.0.0
     */
    public function log($message) {
        if ($this->settings->debug) {
            error_log('appture-pay: '.$message);
        }
    }

}