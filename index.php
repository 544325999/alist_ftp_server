<?php
require_once('./vendor/autoload.php');
use FTPServer\FTPServer;
$fs = new FTPServer();
$fs->run();
