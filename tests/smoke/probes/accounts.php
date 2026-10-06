<?php

// What the accounts root holds: who is in it, whether ADMIN_PASSWORD logs in,
// whether every file belongs to the kirby user, and a fingerprint of the
// files so a later start can be shown to have left them alone.

require '/app/kirby/bootstrap.php';

$roots = require '/usr/local/share/kirby/roots.php';
$kirby = new Kirby(['roots' => $roots('/app/public')]);
$user = $kirby->users()->first();

try {
	$password = $user !== null && $user->validatePassword(getenv('ADMIN_PASSWORD'));
} catch (Throwable) {
	$password = false;
}

$accounts = '/app/storage/accounts';
$kirbyUid = (int) trim(shell_exec('id -u kirby'));
$foreign = 0;
$files = [];

$entries = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($accounts, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($entries as $entry) {
	if ($entry->getOwner() !== $kirbyUid) {
		$foreign++;
	}
	if ($entry->isFile()) {
		$files[substr($entry->getPathname(), strlen($accounts))] = hash_file('sha256', $entry->getPathname());
	}
}
ksort($files);

echo json_encode([
	'count' => $kirby->users()->count(),
	'email' => $user?->email(),
	'role' => $user?->role()->id(),
	'password' => $password ? 'valid' : 'invalid',
	'foreign' => $foreign,
	'fingerprint' => $files === [] ? null : hash('sha256', json_encode($files)),
]);
