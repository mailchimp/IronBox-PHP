<?php
/**
 * IronBox PHP client
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * @category   IronBox
 * @package    Log
 * @copyright  Copyright (c) 2014 MailChimp
 * @license    http://www.opensource.org/licenses/mit-license.php
 */



/**
 * An object-oriented wrapper around cURL
 */
class Http_Client {
    /**
     * Keep the custom headers list as an array
     * @var array
     */
    public $headers = array();

    /**
     * Once the request is finished, the response can be found here
     * @var Http_Response
     */
    public $response;

    /**
     * Set a default timeout
     * @var integer
     */
    public $timeout = 600;

    /**
     * By default, throw an exception when a request fails
     * @var boolean
     */
    public $throw_on_error = true;

    /**
     * If non-null, specifies a set of options to set on the curl handle
     * before processing the request.
     */
    protected $_auth_options;

    /**
     * Initialize a client
     * @param string|null $start_url optional URL to get immediately
     */
    public function __construct($start_url=null) {
        if($start_url) $this->get($start_url);
    }

    /**
     * Initiate a GET request
     * @param string $url the URL to get
     * @param array|null $get_vars optional extra vars to append to the url as key => value pairs
     * @param array $extra_headers any request-specific headers to add to the $client->headers array
     * @return Http_Response
     */
    public function get($url, $get_vars=null, $extra_headers=array()) {
        if($get_vars !== null) {
            if(strpos($url, '?') !== false) {
                $url .= http_build_query($get_vars);
            } else {
                $url .= '?' . http_build_query($get_vars);
            }
        }

        return $this->request('GET', $url, $extra_headers);
    }

    /**
     * Initiate a POST request
     * @param string $url the URL to post to
     * @param array|string $post_vars what to post, either as an array of key => value pairs for standard forms, or a custom POST body string
     * @param array $extra_headers any request-specific headers to add to the $client->headers array
     * @return Http_Response
     */
    public function post($url, $post_vars=array(), $extra_headers=array()) {
        $post_body = null;
        if(!empty($post_vars)) {
            $post_body = (is_array($post_vars)) ? http_build_query($post_vars) : $post_vars;
        }

        return $this->request('POST', $url, $extra_headers, $post_body);
    }

    /**
     * Initiate a PUT request
     * @param string $url the URL to put to
     * @param string|resource the literal string to put in the request or a file handle to stream
     * @param array $extra_headers and request-specific headers to add to the $client->headers array
     * @return Http_Response
     */
    public function put($url, $body, $extra_headers=array()) {
        return $this->request('PUT', $url, $extra_headers, $body);
    }

    /**
     * Actually instrument cURL to make the request
     * @param string $method the HTTP method to use ("GET", "POST", "HEAD", etc.)
     * @param string $url the URL to request
     * @param array $headers any request-specific headers to add to $client->headers
     * @param string|null $body a body to POST/PUT/etc. for the request
     * @return Http_Response
     */
    public function request($method, $url, $headers=array(), $body=null) {
        $method = strtoupper($method);
        $headers = array_merge($this->headers, $headers);
        $headers['Expect'] = '';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, $headers['User-Agent']);
        unset($headers['User-Agent']);
        $opt_headers = array();
        foreach($headers as $name => $val) {
            $opt_headers[] = $name . ': ' . $val;
        }
        
        if($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif($method === 'PUT' && is_resource($body)) {
            curl_setopt($ch, CURLOPT_PUT, true);
        } elseif($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if($body) {
            if(is_string($body) || is_array($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } elseif(is_resource($body)) {
                rewind($body);
                $stat = fstat($body);
                curl_setopt($ch, CURLOPT_INFILE, $body);
                curl_setopt($ch, CURLOPT_INFILESIZE, $stat['size']);
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $opt_headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        if ($this->_auth_options) {
            curl_setopt_array($ch, $this->_auth_options);
        }

        $response_body = curl_exec($ch);
        if(curl_error($ch)) {
            $this->response = null;
            throw new Http_ConnectException("$method to $url failed: " . curl_error($ch));
        }

        $this->response = new Http_Response($ch, $response_body);
        
        if($this->throw_on_error && !$this->response->isSuccessful()) {
            throw new Http_Exception("$method to $url failed with {$this->response->getStatus()}: {$this->response->getBody()}");
        }

        return $this->response;
    }

    /**
     * Enables authentication.
     * @param string $type 'basic', 'digest', 'any'
     * @param string $user the username to authenticate with
     * @param string $password the password to authenticate with
     */
    public function auth($username, $password, $type = 'any') {
        $types = array('basic' => CURLAUTH_BASIC, 'digest' => CURLAUTH_DIGEST, 'any' => 'CURLAUTH_ANY');
        
        if (!array_key_exists($type, $types)) {
            throw new InvalidArgumentException("Unsupported auth type $type");
        }

        $this->_auth_options = array(
            CURLOPT_HTTPAUTH => $types[$type],
            CURLOPT_USERPWD  => "$username:$password"
        );
    }

    /**
     * Clears any authentication settings that were previously applied.
     */
    public function noauth() {
        $this->_auth_options = null;
    }

    /**
     * Return the response from the latest request
     * @return Http_Response
     */
    public function getResponse() {
        return $this->response;
    }
    
    /**
     * Return the response body from the latest request
     * @return string
     */
    public function getBody() {
        return $this->response->getBody();
    }
}

?>
