<?php

// Test configuration: the settings array is read from the JSON file named by an environment variable.
$CONFIGURATION = json_decode(file_get_contents(getenv('KLOUDSPEAKER_HTTP_TEST_CONFIG') ?: $_SERVER['KLOUDSPEAKER_HTTP_TEST_CONFIG']), true);
?>
