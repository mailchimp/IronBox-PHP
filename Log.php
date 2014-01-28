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
 * Just a little log wrapper to make the IronBox_Client class extendible enough to jump in/out of my projects
 */
class Log {

    public static function info($str) {
        echo "{$str}\n";
    }

    public static function error($str) {
        echo "{$str}\n";
    }
}

?>
