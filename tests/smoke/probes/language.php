<?php

// Adds the language LANGUAGE_CODE the way the Panel does, and reports whether
// Kirby accepted it, what it said if not, and which language and site content
// files exist afterwards.

require '/app/kirby/bootstrap.php';

$roots = (require '/usr/local/share/kirby/roots.php')('/app/public');
$kirby = new Kirby(['roots' => $roots]);
$kirby->impersonate('kirby');

try {
	Kirby\Cms\Language::create(['code' => getenv('LANGUAGE_CODE'), 'name' => 'Smoke']);
	$error = null;
} catch (Throwable $e) {
	$error = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode([
	'created' => $error === null,
	'error' => $error,
	'languages' => array_map('basename', glob($roots['site'] . '/languages/*.php')),
	'content' => array_map('basename', glob($roots['content'] . '/site*.txt')),
]);
