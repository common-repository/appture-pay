<?php
/**
 * Description of AppturePayAPI
 * 
 */
namespace ApptureLab;
class AppturePayAPI extends ApptureWebRequest {
    public $apiUrl = "https://www.appturepay.com/api/";//"http://localhost/appturepay/api.appturepay/";//
    
    private $clientId = "appture_pay_web";
    private $clientSecret = "";
    
    private static $session = null;
    private $authError = null;
    private $authRetries = 0;
    
    public function __construct($clientId = "appture_pay_web", $clientSecret = "") {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }
    
    public function clearSession() {
        AppturePayAPI::$session = null;
    }
    
    public function getSession() {
        if(AppturePayAPI::$session === null
                || (AppturePayAPI::$session !== null && !empty(AppturePayAPI::$session["client_id"]) && AppturePayAPI::$session["client_id"] !== $this->clientId)) {
            $this->authClient();
        }
        
        return AppturePayAPI::$session;
    }
    
    public function getAuthError() {
        return $this->authError;
    }
    
    public function authClient() {
        
        if(isset($_SESSION["session"]) && microtime(true) < $_SESSION["session"]["time"] + $_SESSION["session"]["expires_in"]) {
            
            AppturePayAPI::$session = array(
                "time" => filter_input(INPUT_SESSION, "time"),
                "expires_in" => filter_input(INPUT_SESSION, "expires_in"),
                "client_id" => filter_input(INPUT_SESSION, "client_id"),
                "username" => filter_input(INPUT_SESSION, "username")
            );
            
        } else {

            $payload = array(
                "grant_type" => "client_credentials"
            );

            $headers = array(
                "Authorization" => "Basic ".base64_encode($this->clientId.":".$this->clientSecret),
                "Content-Type" => "application/x-www-form-urlencoded"
            );
            
            $response = $this->doWebRequest("POST", "auth/access_token", $payload, $headers);
            $requestInfo = $this->getRequestInfo();
            
            if($requestInfo && $requestInfo["http_code"] === 200) {
                $response["time"] = microtime(true);
                $response["client_id"] = $this->clientId;
                $_SESSION["session"] = $response;
                AppturePayAPI::$session = $response;
                
            } else {
                
                AppturePayAPI::$session = null;
                $_SESSION["session"] = null;
                $this->authError = $response;
                
                if($this->authRetries === 0) {
                    
                    $this->authRetries++;
                    // retry once
                    $this->authClient();
                }
            }
            
        }
        
    }

    public function authPassword($username, $password) {
        
        $payload = array(
            "grant_type" => "password",
            "username" => $username,
            "password" => $password
        );
        
        $headers = array(
            "Authorization" => "Basic ".base64_encode($this->clientId.":".$this->clientSecret),
            "Content-Type" => "application/x-www-form-urlencoded"
        );
        
        $response = $this->doWebRequest("POST", "auth/access_token", $payload, $headers);
        $requestInfo = $this->getRequestInfo();

        if($requestInfo && $requestInfo["http_code"] === 200) {
            $response["time"] = microtime(true);
            $response["client_id"] = $this->clientId;
            $response["username"] = $username;
            $_SESSION["session"] = $response;
            AppturePayAPI::$session = $response;

        } else {
            AppturePayAPI::$session = null;
            $_SESSION["session"] = null;
            $this->authError = $response;
        }
        
    }
    
    /**
     * Revoke Token for current session.
     */
    public function authRevokeToken() {
        
        if(isset($_SESSION["session"]) && microtime(true) < $_SESSION["session"]["time"] + $_SESSION["session"]["expires_in"]) {
        
            $payload = array(
                "token" => $_SESSION["session"]["access_token"],
                "token_type_hint" => "access_token",
            );

            $headers = array(
                "Authorization" => $_SESSION["session"]["token_type"]." ".$_SESSION["session"]["access_token"]
            );

            $response = $this->doWebRequest("POST", "auth/revoke_token", $payload, $headers);
            $requestInfo = $this->getRequestInfo();

            if($requestInfo && $requestInfo["http_code"] === 200) {
                AppturePayAPI::$session = null;
                $_SESSION["session"] = null;
            }
            
            return $response;
            
        }
        
    }
    
    /*USER PROFILE ENDPOINT*/
    public function userProfileGetSpecific($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "user_profile/{$id}", null, $headers);
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    /*TRANSACTION ENDPOINT*/
    public function transactionGet($parameters = array()) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "transaction?". http_build_query($parameters), null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function transactionPost($data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("POST", "transaction", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function transactionGetSpecific($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "transaction/{$id}", null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function transactionDelete($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("DELETE", "transaction/{$id}", null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function transactionPutCapture($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("PUT", "transaction/{$id}/capture", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function transactionPutReverse($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("PUT", "transaction/{$id}/reverse", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    /*RECURRING TRANSACTION ENDPOINT*/
    public function recurringTransactionGetSpecific($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "recurring_transaction/{$id}", null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function recurringTransactionPut($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("PUT", "recurring_transaction/{$id}", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }

    public function recurringTransactionPutCharge($id) {

        $session = $this->getSession();

        if($session) {

            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );

            $response = $this->doWebRequest("PUT", "recurring_transaction/{$id}/charge", null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;

            return $response;

        } else {

            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);

        }

        return null;

    }
    
    /*DELIVERY ENDPOINT*/
    public function deliveryGet($parameters = array()) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "delivery?". http_build_query($parameters), null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryGetSpecific($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "delivery/{$id}", null, $headers);
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryPost($data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("POST", "delivery", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryPut($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("PUT", "delivery/{$id}", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryDelete($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("DELETE", "delivery/{$id}", null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryGetWaybill($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "delivery/{$id}/waybill", null, $headers, true);
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryGetLabel($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "delivery/{$id}/label", null, $headers, true);
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryGetTrack($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "delivery/{$id}/track", null, $headers);
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    /**
     * @deprecated
     * 
     * @param type $id
     * @return type
     */
    public function deliveryGetTracking($id) {
        trigger_error('Deprecation warning: use deliveryGetTrack instead', E_USER_NOTICE);
        return $this->deliveryGetTrack($id);
    }
    
    public function deliveryPostQuote($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("POST", "delivery/{$id}/quote", $data, $headers); // note that we are POSTing for this PUT
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryPutQuote($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("PUT", "delivery/{$id}/quote", $data, $headers); // note that we are POSTing for this PUT
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryPutDispatch($id, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("PUT", "delivery/{$id}/dispatch", $data, $headers); // note that we are POSTing for this PUT
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function deliveryGetCheckPostalCode($postalCode, $data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "delivery/check_postal_code/{$postalCode}?". http_build_query($data), null, $headers);
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    /**
     * @deprecated
     * 
     * @param type $postalCode
     * @param type $data
     */
    public function deliveryCheckPostalCode($postalCode, $data) {
        trigger_error('Deprecation warning: use deliveryGetCheckPostalCode instead', E_USER_NOTICE);
        return $this->deliveryGetCheckPostalCode($postalCode, $data);
    }
    
    /*ADDRESS ENDPOINT*/
    public function addressGetSpecific($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "address/{$id}", null, $headers);
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }

    /*PAY OUT ENDPOINT*/
    public function payOutPost($data) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization" => $session["token_type"]. " ". $session["access_token"],
                "Content-Type" => "application/json"
            );
            
            $response = $this->doWebRequest("POST", "pay_out", $data, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => $this->authError);
            
        }
        
        return null;
        
    }
    
    public function payOutGetSpecific($id) {
        
        $session = $this->getSession();
        
        if($session) {
            
            $headers = array(
                "Authorization: ". $session["token_type"]. " ". $session["access_token"]
            );
            
            $response = $this->doWebRequest("GET", "pay_out/{$id}", null, $headers);
            $requestInfo = $this->getRequestInfo();
            $response["request"] = $requestInfo;
            
            return $response;
            
        } else {
            
            return array("success" => false, "message" => "Not Authenticated", "data" => AppturePayAPI::$authError);
            
        }
        
        return null;
        
    }
    
}