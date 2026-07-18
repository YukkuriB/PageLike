<?php

$configPath = __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';
if ( !is_file( $configPath ) ) {
	$configPath = __DIR__ . '/../../../vendor/mediawiki/mediawiki-phan-config/src/config.php';
}

$config = require $configPath;
$config['minimum_target_php_version'] = '8.2';
$config['target_php_version'] = '8.2';

return $config;
