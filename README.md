
ironbox-client-php
=====================

IronBox REST client for PHP

IronBox - http://www.goironbox.com/

# Usage

To use this, instantiate the IronBox_Client class with your IronBox credentials.  Then call the functions on the client to perform the desired tasks.

A simple example of uploading a file, this does all of the work of getting credentials, encrypting the file and uploading it...

    $client = new IronBox_Client($email, $password, 0, 'latest', 'application/json', true);
    $client->uploadFile($container_id, $file_path, $blob_name);

# Requirements 

PHP 5.something (have only tested and verified with 5.3)
mcrypt for the encryption parts

# License

MIT OpenSourced license: http://opensource.org/licenses/MIT

