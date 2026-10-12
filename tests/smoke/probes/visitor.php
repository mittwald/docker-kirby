<?php

// Served by FrankenPHP: reports the address PHP was given and what Kirby
// makes of it, which is what its login throttling and installer check use.

require '/app/kirby/bootstrap.php';

$kirby = new Kirby(['roots' => [
	'index' => '/app/public',
	'base' => '/app',
	'site' => '/app/site',
	'content' => '/app/content',
	'storage' => '/app/storage',
]]);

echo json_encode([
	'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
	'visitor.ip' => $kirby->visitor()->ip(),
	'isLocal' => $kirby->environment()->isLocal(),
]);
