<?php

// The secrets Kirby ended up with, and a content token signed with the salt,
// so that a test can show Kirby actually uses it instead of its default.

require '/app/kirby/bootstrap.php';

$roots = require '/usr/local/share/kirby/roots.php';
$kirby = new Kirby(['roots' => $roots('/app/public')]);

echo json_encode([
	'content.salt' => $kirby->option('content.salt'),
	'cookie.key' => $kirby->option('cookie.key'),
	'token' => $kirby->contentToken($kirby->page('home'), 'probe'),
]);
