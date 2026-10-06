<?php

// Served by FrankenPHP: PHP_* variables are applied through the Caddyfile's
// php_ini block, so only a request sees them.
echo json_encode([
	'memory_limit' => ini_get('memory_limit'),
	'upload_max_filesize' => ini_get('upload_max_filesize'),
	'opcache.enable' => ini_get('opcache.enable'),
	'opcache.validate_timestamps' => ini_get('opcache.validate_timestamps'),
	'date.timezone' => ini_get('date.timezone'),
	'display_errors' => ini_get('display_errors'),
]);
