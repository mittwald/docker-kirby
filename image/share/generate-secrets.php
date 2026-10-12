<?php

/**
 * Generates the secrets listed in `secrets.php` that the environment does not
 * provide.
 *
 * Run by the entrypoint before the server starts, with the document root as
 * its only argument. A value that already exists in the storage root is never
 * replaced, so the salt a site went live with stays its salt.
 *
 * Every failure exits non-zero: a container that cannot keep its salt would
 * otherwise fall back to Kirby's predictable default without anyone noticing.
 */

$log = static function (string $message): void {
	fwrite(STDERR, '[kirby-entrypoint] ' . $message . PHP_EOL);
};

$fail = static function (string $message) use ($log): never {
	$log('error: ' . $message);
	exit(1);
};

$read = static function (string $name): ?string {
	$value = getenv($name);

	return is_string($value) && $value !== '' ? $value : null;
};

if (isset($argv[1]) === false) {
	$fail('usage: generate-secrets.php DOCUMENT_ROOT');
}

$roots   = require __DIR__ . '/roots.php';
$secrets = require __DIR__ . '/secrets.php';
$storage = $roots($argv[1])['storage'];

foreach ($secrets($storage) as $option => [$variable, $generated]) {
	if ($read($variable) !== null) {
		continue;
	}

	if (($file = $read($variable . '_FILE')) !== null) {
		if (is_readable($file) === false) {
			$fail($variable . '_FILE=' . $file . ' is not readable');
		}

		continue;
	}

	if (is_file($generated) && filesize($generated) > 0) {
		continue;
	}

	if (is_dir($storage) === false) {
		@mkdir($storage, 0755, true);
	}

	if (is_dir($storage) === false || is_writable($storage) === false) {
		$fail('cannot generate ' . $option . ': ' . $storage . ' is not writable by the container user. Fix the ownership of the mounted volume, or set ' . $variable . '.');
	}

	umask(0077);

	if (file_put_contents($generated, bin2hex(random_bytes(32))) === false) {
		$fail('cannot write ' . $generated);
	}

	$log('generated ' . $option . ' in ' . $generated);
}
