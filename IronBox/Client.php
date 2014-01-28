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



class IronBox_Client_Exception extends Exception {}

class IronBox_Client {

    private $entity;
    private $entity_password;
    private $entity_type;
    private $url;
    private $format;
    private $debug;
    private $client;

    public function __construct($entity, $entity_password, $entity_type = 0, $version = 'latest', $format = 'application/json', $debug = false) {
        $this->url = "https://api.goironcloud.com/{$version}/";
        $this->entity = $entity;
        $this->entity_password = $entity_password;
        $this->entity_type = $entity_type;
        $this->format = $format;
        $this->client = new Http_Client();
        $this->debug = $debug;
    }

    public function uploadFile($container_id, $file_path, $blob_name) {
        if (!$this->pingCall()) {
            throw new IronBox_Client_Exception('Unable to communicate with IronBox server');
        }
        $key_data = $this->containerKeyDataCall($container_id);
        if (!$key_data) {
            throw new IronBox_Client_Exception('Unable to get container key data');
        }
        $blob_id_name = $this->createEntityContainerBlobCall($container_id, $blob_name);
        if (!$blob_id_name) {
            throw new IronBox_Client_Exception('Unable to create entity container blob');
        }
        $check_out_data = $this->checkOutEntityContainerBlobCall($container_id, $blob_id_name);
        if (!$check_out_data) {
            throw new IronBox_Client_Exception('Unable to check out container blob');
        }

        $original_file_size = filesize($file_path);
        $encrypted_file_path = $file_path . '.iron';
        $this->encryptFile($file_path, $encrypted_file_path, $key_data);
        if (!file_exists($encrypted_file_path)) {
            throw new IronBox_Client_Exception('Unable to create encrypted file to upload to ironbox');
        }

        if (!$this->uploadBlob($encrypted_file_path, $check_out_data['SharedAccessSignatureUri'])) {
            @unlink($encrypted_file_path);
            throw new IronBox_Client_Exception('Unable to upload blob');
        }

        if (!$this->checkInEntityContainerBlobCall($container_id, $blob_id_name, $original_file_size, $check_out_data['CheckInToken'])) {
            @unlink($encrypted_file_path);
            throw new IronBox_Client_Exception('Unable to check in the entity');
        }

        @unlink($encrypted_file_path);
    }

    public function checkInEntityContainerBlobCall($container_id, $blob_id_name, $blob_size_in_bytes, $check_in_token) {
        $post_data = array(
            'Entity' => $this->entity,
            'EntityType' => $this->entity_type,
            'EntityPassword' => $this->entity_password,
            'ContainerID' => $container_id,
            'BlobIDName' => $blob_id_name,
            'BlobSizeBytes' => $blob_size_in_bytes,
            'BlobCheckInToken' => $check_in_token
        );
        $url = $this->url . 'CheckInEntityContainerBlob';
        $response = $this->client->post($url, $post_data, array('Accept' => $this->format));
        if (!$response->isSuccessful()) {
            if ($this->debug) {
                Log::info('invalid response from CheckInEntityContainerBlob, status: ' . $response->getStatus() . ', body: ' . $response->getBody());
            }
            return null;
        }
        return json_decode($response->getBody(), true);
    }

    public function uploadBlob($file, $uri) {
        // Cloud storage only allows blocks of max 4MB, and max 50k blocks
        // so 200 GB max        per file
        $block_size_mb = 4;
        $block_size_bytes = $block_size_mb * 1024 * 1024;
        $file_size = filesize($file);
        if ($this->debug) Log::info("Starting send in {$block_size_mb}MB increments");

        # Send headers
        $headers = array(
            'content-type' => 'application/octet-stream',
            'x-ms-blob-type' => 'BlockBlob',
            'x-ms-version' => '2012-02-12'
        );

        // Open handle to encrypted file and send it in blocks
        $sas_uri_block_prefix = $uri . "&comp=block&blockid=";
        $block_ids = array();
        $num_bytes_sent = 0;
        $i = 0;
        $fh = fopen($file, 'r');
        while(!feof($fh)) {
            $buf = fread($fh, $block_size_bytes);
            //block IDs all have to be the same length, which was NOT
            //documented by MSFT
            $block_id = "block". str_pad($i, 8, 0, STR_PAD_LEFT);
            $block_sas_uri = $sas_uri_block_prefix . base64_encode($block_id);

            if ($this->debug) {
                Log::info('uploadBlob will put to ' . $block_sas_uri);
            }

            //Create a blob block
            $response = $this->client->put($block_sas_uri, $buf, $headers);
            if ($response->getStatus() !== 201) {
                if ($this->debug) {
                    Log::info('Invalid response creating block at url ' . $block_sas_uri . ', code: ' . $response->getStatus() . ', body: ' . $response->getBody());
                }
                throw new IronBox_Client_Exception('Unable to upload file block');
            }

            // Block was successfuly sent, record its ID
            $block_ids[] = $block_id;
            $num_bytes_sent += strlen($buf);
            $i++;

            // Show progress if needed
            if ($this->debug) {
                $done = 100 * $num_bytes_sent / $file_size;
                Log::info("{$done} % done ({$num_bytes_sent} sent of {$file_size})");
            }
        }

        // Done sending blocks, so commit the blocks into a single one
        // do the final re-assembly on the storage server side
        $commit_block_sas_url = $uri . "&comp=blockList";
        $commit_headers = array(
            'content-type' => 'text/xml',
            'x-ms-version' => '2012-02-12'
        );
        // build list of block ids as xml elements
        $block_list_body = '';
        foreach($block_ids as $x) {
            $encoded_block_id = trim(base64_encode($x));
            // Indicate blocks to commit per 2012-02-12 version PUT block list specifications
            $block_list_body .= "<Latest>{$encoded_block_id}</Latest>";
        }
        $commit_body = '<?xml version="1.0" encoding="utf-8"?><BlockList>'.$block_list_body.'</BlockList>';
        $commit_response = $this->client->put($commit_block_sas_url, $commit_body, $commit_headers);
        return $commit_response->getStatus() == 201;

    }

    public function pingCall() {
        $response = $this->client->get($this->url . 'Ping');
        if ($response->isSuccessful()) return true;
        if ($this->debug) {
            Log::info('unsuccessful ping to ' . $this->url . ', response: ' . $response->getBody());
        }
        return false;
    }

    public function containerKeyDataCall($container_id) {
        $post_vars = array('Entity'=> $this->entity, 'EntityType'=> $this->entity_type, 'EntityPassword'=> $this->entity_password, 'ContainerID'=> $container_id);
        $response = $this->client->post($this->url . 'ContainerKeyData', $post_vars, array('Accept'=>$this->format));
        if (!$response->isSuccessful()) {
            if ($this->debug) {
                Log::info('unsuccessful call to containerKeyDataCall at url ' . $this->url . ' response code: ' . $response->getStatus() . ', data: ' . $response->getBody());
            }
            return null;
        }

        $response_data = json_decode($response->getBody(), true);
        $session_key = $response_data['SessionKeyBase64'];
        if (!$session_key) {
            Log::info('containerKeyData call returned invalid data, JSON response: ' . $response->getBody());
            return null;
        }
        return array('SymmetricKey' => base64_decode($session_key),
            'InitializationVector' => base64_decode($response_data['SessionIVBase64']),
            'KeyStrength' => $response_data['SymmetricKeyStrength']);
    }

    public function createEntityContainerBlobCall($container_id, $blob_name) {
        $post_data = array(
            'Entity'=> $this->entity,
            'EntityType'=> $this->entity_type,
            'EntityPassword'=>$this->entity_password,
            'ContainerID'=> $container_id,
            'BlobName'=> $blob_name
        );
        $response = $this->client->post($this->url . 'CreateEntityContainerBlob', $post_data, array('Accept' => $this->format));

        if (!$response->isSuccessful()) {
            if ($this->debug) {
                Log::info('unsuccessful call to CreateEntityContainerBlob at url ' . $this->url . ' response code: ' . $response->getStatus() . ', data: ' . $response->getBody());
            }
            return null;
        }
        return json_decode($response->getBody(), true);
    }


    public function checkOutEntityContainerBlobCall($container_id, $blob_id_name) {
        $post_data = array(
            'Entity' => $this->entity,
            'EntityType' => $this->entity_type,
            'EntityPassword' => $this->entity_password,
            'ContainerID' => $container_id,
            'BlobIDName' => $blob_id_name
        );

        $response = $this->client->post($this->url . 'CheckOutEntityContainerBlob', $post_data, array('Accept' => $this->format));

        if (!$response->isSuccessful()) {
            if ($this->debug) {
                Log::info('unsuccessful call to CheckOutEntityContainerBlob at url ' . $this->url . ', params: ' . print_r($post_data, true) . ' response code: ' . $response->getStatus() . ', data: ' . $response->getBody());
            }
            return null;
        }

        $response_data = json_decode($response->getBody(), true);
        if (!$response_data) {
            if ($this->debug) {
                Log::info('empty response from CheckOutEntityContainerBlob, params: ' . print_r($post_data, true));
            }
            return null;
        }
        
        $shared_access_signature = $response_data['SharedAccessSignature'];
        if (!$shared_access_signature) {
            if ($this->debug) {
                Log::info('SharedAccessSignature empty in response from CheckOutEntityContainerBlob: ' . $response->getBody());
            }
            return null;
        }
        
        return array(
            'SharedAccessSignature' => $shared_access_signature,
            'SharedAccessSignatureUri' => $response_data['SharedAccessSignatureUri'],
            'CheckInToken' => $response_data['CheckInToken'],
            'StorageUri' => $response_data['StorageUri'],
            'StorageType' => $response_data['StorageType'],
            'ContainerStorageName' => $response_data['ContainerStorageName']
        );
    }

    public function encryptFile($in_filename, $out_filename, $key_data) {
        $read_block_size = 1024;
        $out = fopen($out_filename, 'wb');
        $in = fopen($in_filename, 'rb');

        // this is the best post on understanding php encryption and rijndael and aes modes.  soooo helpful
        // http://www.chilkatsoft.com/p/php_aes.asp
        $cipher = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');
        $key = $key_data['SymmetricKey'];
        $iv = $key_data['InitializationVector'];

        $init = mcrypt_generic_init($cipher, $key, $iv);
        if ($init === false || $init < 0) {
            Log::error('Unable to initialize mcrypt');
        }
        while (!feof($in)) {
            $line = fread($in, $read_block_size);
            if (strlen($line) < $read_block_size) {
                $line = str_pad($line, strlen($line) + (16 - strlen($line) % 16), chr(16 - strlen($line) % 16));
            }
            $encrypted = mcrypt_generic($cipher, $line);
            fwrite($out, $encrypted);
        }
        mcrypt_generic_deinit($cipher);
        fclose($in);
        fclose($out);
    }

    public function decryptFile($in_filename, $out_filename, $key_data) {
        $read_block_size = 1024;
        $out = fopen($out_filename, 'wb');
        $in = fopen($in_filename, 'rb');

        // this is the best post on understanding php encryption and rijndael and aes modes.  soooo helpful:
        // http://www.chilkatsoft.com/p/php_aes.asp
        $cipher = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');
        $key = $key_data['SymmetricKey'];
        $iv = $key_data['InitializationVector'];

        $init = mcrypt_generic_init($cipher, $key, $iv);
        if ($init === false || $init < 0) {
            Log::error('Unable to initialize mcrypt');
        }
        while (!feof($in)) {
            $line = fread($in, $read_block_size);
            $decrypted = mdecrypt_generic($cipher, $line);
            if (strlen($decrypted) < $read_block_size) {
                $decrypted = substr($decrypted, 0, -ord(substr($decrypted, -1)));
            }
            fwrite($out, $decrypted);
        }
        mcrypt_generic_deinit($cipher);
        fclose($in);
        fclose($out);
    }
}
