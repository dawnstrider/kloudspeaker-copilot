<?php

// Router for the PHP built-in server used by HttpTest.php: runs the real r.php entry point,
// with this directory first on the include path so its test configuration.php is the one loaded.
$backend = dirname(__DIR__, 2);
chdir($backend);
set_include_path(__DIR__ . PATH_SEPARATOR . $backend . PATH_SEPARATOR . get_include_path());
require $backend . '/r.php';
