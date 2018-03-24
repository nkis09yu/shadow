<?php
require __DIR__ . '/common/common.inc.php';
ini_set('display_errors', TRUE);
ini_set('error_reporting', E_ALL);

global $user_account_read;
$ret = $user_account_read->version();
var_dump($ret);
