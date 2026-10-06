<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Docker;
use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * Option groups that Kirby reads as a whole. `email`, `thumbs` and
 * `cache.pages` are looked up by their parent key, so the KIRBY_* variables
 * only reach them as nested arrays. The mail goes to a Mailpit instance that
 * requires SMTP auth, so its arrival proves the credentials were used.
 */
final class NestedOptionsAndMailTest extends SmokeTestCase
{
	private const string MAILPIT_IMAGE = 'axllent/mailpit:v1.31.2';

	/** @var array<string, mixed> */
	private static array $probe;

	/** @var list<array<string, mixed>> */
	private static array $messages;

	private static string $subject;

	public static function setUpBeforeClass(): void
	{
		$network = self::network('net');
		self::$subject = 'smoke test ' . Docker::$runId;

		$mailpit = self::start('mailpit', [
			'--network', $network,
			'--network-alias', 'mailpit',
			'-e', 'MP_SMTP_AUTH=smoke:s3cret',
			'-e', 'MP_SMTP_AUTH_ALLOW_INSECURE=true',
		], self::MAILPIT_IMAGE, [8025]);

		$kirby = self::startHealthy('mail', [
			'--network', $network,
			'-e', 'KIRBY_EMAIL_TRANSPORT=smtp',
			'-e', 'KIRBY_EMAIL_HOST=mailpit',
			'-e', 'KIRBY_EMAIL_PORT=1025',
			'-e', 'KIRBY_EMAIL_USER=smoke',
			'-e', 'KIRBY_EMAIL_PASSWORD=s3cret',
			'-e', 'KIRBY_EMAIL_SECURITY=false',
			'-e', 'KIRBY_THUMBS_QUALITY=70',
			'-e', 'KIRBY_CACHE_PAGES=true',
			'-e', 'KIRBY_CACHE_PAGES_TYPE=apcu',
			'-e', 'KIRBY_AUTH_METHODS=password,code',
			'-e', 'KIRBY_AUTH_EMAIL_FROM=login@example.com',
			'-e', 'KIRBY_AUTH_EMAIL_FROM_NAME=Smoke Sender',
			'-e', 'KIRBY_OPTIONS_JSON={"email":{"presets":{"smoke":{"from":"kirby@example.com"}}},"auth":{"methods":["password"]}}',
		]);
		$mailpit->waitForHttp('/api/v1/info', 8025, 30);

		self::$probe = $kirby->probe('options.php', ['MAIL_SUBJECT' => self::$subject]);
		self::$messages = json_decode($mailpit->get('/api/v1/messages', port: 8025)->body, true, flags: JSON_THROW_ON_ERROR)['messages'];
	}

	public function testKirbyEmailVariablesReachTheEmailOptionWithAuthSwitchedOn(): void
	{
		self::assertSame(
			['type' => 'smtp', 'username' => 'smoke', 'auth' => true, 'security' => false],
			self::$probe['transport'],
		);
	}

	public function testKirbyOptionsJsonMergesIntoTheEmailGroupInsteadOfReplacingIt(): void
	{
		self::assertSame('kirby@example.com', self::$probe['preset.from']);
	}

	public function testKirbyThumbsQualityReachesTheThumbsOption(): void
	{
		self::assertSame(70, self::$probe['thumbs.quality']);
	}

	public function testKirbyCachePagesVariablesReachTheCachePagesOption(): void
	{
		self::assertSame(['active' => true, 'type' => 'apcu'], self::$probe['cache.pages']);
	}

	public function testAListInKirbyOptionsJsonReplacesTheOneFromTheEnvironment(): void
	{
		self::assertSame(['password'], self::$probe['auth.methods']);
	}

	public function testMailpitRefusesMailWithoutSmtpAuth(): void
	{
		self::assertStringStartsWith('failed', self::$probe['unauthenticated']);
	}

	public function testKirbySendsMailThroughTheConfiguredSmtpTransport(): void
	{
		self::assertSame('ok', self::$probe['preset']);
		self::assertContains(self::$subject, array_column(self::$messages, 'Subject'), 'the mail did not arrive in mailpit');
	}

	public function testTheLoginCodeComesFromKirbyAuthEmailFrom(): void
	{
		self::assertSame('ok', self::$probe['challenge']);
		self::assertContains(
			['Name' => 'Smoke Sender', 'Address' => 'login@example.com'],
			array_column(self::$messages, 'From'),
		);
	}
}
