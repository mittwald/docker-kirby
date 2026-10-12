<?php

/**
 * Refuses to start a site whose content was written for languages the site
 * does not define.
 *
 * Run by the entrypoint before the server starts, with the document root as
 * its only argument. Kirby keeps the languages as PHP files in the site root
 * and renames the content to `*.<code>.txt` when the first one is added. Once
 * the language files are lost, typically because they were added in the
 * Panel of a container whose site root was not persisted, Kirby reads none of
 * that content: language URLs answer 404 and pages render empty. Adding the
 * languages again in the Panel converts the content a second time and
 * damages it, so the container stops instead of inviting that.
 *
 * Only `site.<code>.txt` in the content root counts. Kirby writes no file of
 * that shape for a single-language site, and page folders are not looked at,
 * so a page whose slug contains a dot cannot trigger it.
 */

$log = static function (string $message): void {
	fwrite(STDERR, '[kirby-entrypoint] ' . $message . PHP_EOL);
};

$fail = static function (string $message) use ($log): never {
	$log('error: ' . $message);
	exit(1);
};

$index = $argv[1] ?? $fail('usage: check-languages.php DOCUMENT_ROOT');
$roots = (require __DIR__ . '/roots.php')($index);

$defined = glob($roots['site'] . '/languages/*.php');
$written = glob($roots['content'] . '/site.*.txt');

($defined === false || $written === false) && $fail('cannot list the languages in ' . $roots['site'] . ' and ' . $roots['content']);

if ($defined !== [] || $written === []) {
	exit(0);
}

$codes = array_map(static fn (string $file): string => explode('.', basename($file))[1], $written);
$files = implode(', ', array_map(static fn (string $code): string => 'site/languages/' . $code . '.php', $codes));

$fail(
	$roots['content'] . ' holds content in the languages ' . implode(', ', $codes) . ', but ' . $roots['site'] .
	'/languages defines none, so Kirby would serve none of it. Restore ' . $files . ' from a backup, either into a volume ' .
	'mounted at ' . $roots['site'] . ' or into your own image. Do not add the languages again in the Panel: ' .
	'Kirby would convert the content a second time and overwrite parts of it.'
);
