<?php
/**
 * 
 *
 * @author Dewald
 */
namespace ApptureLab;
class ApptureWebRequest {
    
    public $apiUrl = null;
    protected $ch;
    protected $lastResponse;
    protected $lastCurlInfo;
    
    private static $totalTime = 0;
    private static $calls = array();
    
    protected function getRequestInfo() {
        if($this->lastCurlInfo) {
            return $this->lastCurlInfo;
        } else {
            return null;
        }
    }
    
    public static function getCalls(){
        return ApptureWebRequest::$calls;
    }
    
    public static function getTotalTime(){
        return ApptureWebRequest::$totalTime;
    }
    
    public static function getLocalIndex() {
        return str_ireplace("index.php", "", filter_input(INPUT_SERVER, 'PHP_SELF'));
    }
    
    public static function getIndex() {
        return (filter_input(INPUT_SERVER, 'HTTPS') ? 'https' : 'http')."://". filter_input(INPUT_SERVER, 'HTTP_HOST'). ApptureWebRequest::getLocalIndex();
    }

    protected function doWebRequest($method, $url, $payload, $headers = array(), $verbose = false) {

        $time = microtime(true);        
        $wp_url = $this->apiUrl.$url;

        if($payload && count($payload)) {
            if($headers !== null) {
                if(array_search("application/json", $headers) == "Content-Type") {
                    $jsonPayload = json_encode($payload);
                    if($jsonPayload === false) {
                        return array("error" => "JSON Encode failed", $payload);
                    } else {
                        $payload = $jsonPayload;
                    }
                } else if(array_search("multipart/form-data",$headers) == "Content-Type") {
                    $payload = $payload;
                }
            } else {
                $payload = $payload;
            }
        }

        if(strtoupper($method) === "PUT" && array_search("application/json",$headers) == "Content-Type") {
            $headers['Content-Length'] = strlen($payload);
        }

        //Set Request Arguments
        $wp_args = array('method' => $method, 'body' => $payload ,'headers' =>$headers);

        if($verbose){
            return wp_remote_request($wp_url, $wp_args);
        }

        //extute the request and store the respond
        $this->lastResponse = wp_remote_request($wp_url, $wp_args);
        
        if(!is_wp_error( $this->lastResponse )) {
        
            $this->lastCurlInfo = json_decode($this->lastResponse['body'], true);

            //Add http_code to accomodate AppturePayAPI_functions
            $this->lastCurlInfo['http_code'] = $this->lastResponse['response']['code'];

            // Return headers seperatly from the Response Body
            $headers = $this->lastResponse['headers'];
            $body = $this->lastResponse['body'];

            ApptureWebRequest::$totalTime += (microtime(true)-$time);
            ApptureWebRequest::$calls[] = array("url" => $this->apiUrl.$url, "method" => $method, "time" => microtime(true)-$time);

            if (is_wp_error($this->lastResponse))  {
                return array("error" => "Request Failed - Check URL");//, "curl" => $this->lastCurlInfo);
            }

            $dec = json_decode($body, true);
            if (!$dec) {
                return array("error" => "Invalid JSON returned", "response" => $this->lastResponse);
            }
            
        } else {
            return array("error" => $this->lastResponse->get_error_message(), "request" => array("wp_url"=>$wp_url, "wp_args"=>$wp_args));
        }

        return $dec;

    }
}