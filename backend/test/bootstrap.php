<?php

error_reporting(E_ALL);
chdir(dirname(__DIR__));
set_include_path(getcwd() . PATH_SEPARATOR . getcwd() . '/include' . PATH_SEPARATOR . get_include_path());
require_once 'include/KloudspeakerBackend.class.php';
require_once 'include/Settings.class.php';
require_once 'include/Logging.class.php';
require_once 'include/Request.class.php';
require_once 'db/pdo/PDODatabase.class.php';