<?php

/**
 * Creates the first panel account from the environment.
 *
 * Run by the entrypoint before the server starts, with the document root as
 * its only argument. Kirby offers its panel installer only on localhost unless
 * `panel.install` is switched on, and switching it on lets whoever reaches the
 * panel first create an admin. KIRBY_ADMIN_EMAIL and KIRBY_ADMIN_PASSWORD (or
 * KIRBY_ADMIN_PASSWORD_FILE) cover the same need without that window.
 *
 * It only acts while there are no accounts at all. Matching the email address
 * instead would bring the account back after someone renamed or deleted it in
 * the panel, and a changed password variable would reset a password the user
 * has changed since. Once the first account exists, the variables do nothing.
 *
 * Every failure exits non-zero, so that a deployment that asked for an admin
 * does not come up without one and with no way to install it remotely.
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

$index = $argv[1] ?? $fail('usage: create-admin.php DOCUMENT_ROOT');
$email = $read('KIRBY_ADMIN_EMAIL') ?? $fail('KIRBY_ADMIN_PASSWORD is set, but KIRBY_ADMIN_EMAIL is not');

$password = $read('KIRBY_ADMIN_PASSWORD');

if ($password === null && ($file = $read('KIRBY_ADMIN_PASSWORD_FILE')) !== null) {
	is_readable($file) || $fail('KIRBY_ADMIN_PASSWORD_FILE=' . $file . ' is not readable');

	// Only the line break an editor or `echo` leaves at the end is dropped;
	// other whitespace may be part of the password.
	$password = rtrim((string)file_get_contents($file), "\r\n");
}

$password ??= $fail('KIRBY_ADMIN_EMAIL is set, but neither KIRBY_ADMIN_PASSWORD nor KIRBY_ADMIN_PASSWORD_FILE is');

try {
	require dirname($index) . '/kirby/bootstrap.php';

	$roots = require __DIR__ . '/roots.php';
	$kirby = new Kirby(['roots' => $roots($index)]);

	if ($kirby->users()->count() > 0) {
		$log('panel accounts exist, KIRBY_ADMIN_* is ignored');
		exit(0);
	}

	$kirby->impersonate('kirby', fn () => $kirby->users()->create([
		'email'    => $email,
		'password' => $password,
		'role'     => 'admin',
	]));
} catch (Throwable $e) {
	$fail('cannot create the panel admin ' . $email . ': ' . $e->getMessage());
}

$log('created panel admin ' . $email);
