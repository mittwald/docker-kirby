<?php

// Reads the option groups Kirby looks up by their parent key, and sends mail
// through the SMTP transport the environment configured. Expects a mailpit
// container reachable as "mailpit" that requires SMTP auth.

require '/app/kirby/bootstrap.php';

$kirby = new Kirby(['roots' => [
	'index' => '/app/public',
	'base' => '/app',
	'site' => '/app/site',
	'content' => '/app/content',
	'storage' => '/app/storage',
]]);

$attempt = static function (callable $send): string {
	try {
		$send();
		return 'ok';
	} catch (Throwable $e) {
		return 'failed: ' . $e->getMessage();
	}
};

$email = $kirby->option('email');
$transport = $email['transport'] ?? [];

echo json_encode([
	'transport' => [
		'type' => $transport['type'] ?? null,
		'username' => $transport['username'] ?? null,
		'auth' => $transport['auth'] ?? null,
		'security' => $transport['security'] ?? null,
	],
	'preset.from' => $email['presets']['smoke']['from'] ?? null,
	'thumbs.quality' => $kirby->option('thumbs')['quality'] ?? null,
	'cache.pages' => $kirby->option('cache.pages'),
	'auth.methods' => $kirby->option('auth.methods'),

	// Without credentials, so that mailpit's refusal proves the credentials
	// in the configured transport are what gets the other mails through.
	'unauthenticated' => $attempt(static fn () => $kirby->email([
		'to' => 'nobody@example.com',
		'subject' => 'unauthenticated',
		'body' => '-',
		'from' => 'kirby@example.com',
		'transport' => ['type' => 'smtp', 'host' => 'mailpit', 'port' => 1025, 'security' => false],
	])),

	'preset' => $attempt(static fn () => $kirby->email('smoke', [
		'to' => 'smoke@example.com',
		'subject' => getenv('MAIL_SUBJECT'),
		'body' => 'sent by the smoke test',
	])),

	// The login code mail takes its sender from auth.challenge.email.*, not
	// from the transport. The recipient does not have to exist as an account.
	'challenge' => $attempt(static fn () => Kirby\Cms\Auth\EmailChallenge::create(
		new Kirby\Cms\User(['email' => 'editor@example.com']),
		['mode' => 'login', 'timeout' => 600],
	)),
]);
