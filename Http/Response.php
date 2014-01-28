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
 * Wrap around the result of an HTTP request
 */
class Http_Response {
    public $body;
    public $info;

    public function __construct($ch, $body) {
        $this->body = $body;
        $this->info = curl_getinfo($ch);
        curl_close($ch);
    }

    /**
     * Return true if the call was successful (3XX or lower response code)
     * @return boolean
     */
    public function isSuccessful() {
        $ret_code = floor($this->info['http_code'] / 100);
        return ($ret_code < 4);
    }

    /**
     * Return true if the response is an error (4XX or 5XX)
     * @return boolean
     */
    public function isError() {
        $ret_code = floor($this->info['http_code'] / 100);
        return ($ret_code >= 4);
    }

    /**
     * Return true if the response is a redirect (3XX)
     * @return boolean
     */
    public function isRedirect() {
        $ret_code = floor($this->info['http_code'] / 100);
        return ($ret_code == 3);
    }

    /**
     * Return the HTTP response code
     * @return integer
     */
    public function getStatus() {
        return $this->info['http_code'];
    }

    /**
     * Return the HTTP body as a string
     * @return string
     */
    public function getBody() {
        return $this->body;
    }
}

?>
