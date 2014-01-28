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



require 'IronBox/Client.php';
require 'Log.php';
require 'Http/Client.php';
require 'Http/Exception.php';
require 'Http/ConnectException.php';
require 'Http/Response.php';

$email = 'you@example.com';
$password = 'blahblahblah'
$container_id = '123123';
$file_path = '/tmp/fred.txt';
$blob_name = 'fred.txt';


$client = new IronBox_Client($email, $password, 0, 'latest', 'application/json', true);
$client->uploadFile($container_id, $file_path, $blob_name);


?>
