<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * content.salt and cookie.key. Kirby's defaults for both are the same in every
 * container of this image, so the entrypoint generates them into the storage
 * root unless the environment provides them.
 *
 * @see https://getkirby.com/security/content-salt
 */
final class SecretsTest extends SmokeTestCase
{
	private const string COOKIE_KEY = 'cookie-key-from-a-mounted-secret';

	private static Container $generated;

	private static Container $configured;

	/** @var array<string, mixed> */
	private static array $cli;

	/** @var array<string, mixed> */
	private static array $web;

	/** @var array<string, mixed> */
	private static array $override;

	public static function setUpBeforeClass(): void
	{
		self::$generated = self::startHealthy('generated');
		self::$cli = self::$generated->probe('secrets.php');
		self::$web = self::$generated->webProbe('secrets.php');

		$secrets = self::directory() . '/secrets';
		mkdir($secrets, 0755, true);
		file_put_contents("{$secrets}/cookie-key", self::COOKIE_KEY . "\n");
		chmod("{$secrets}/cookie-key", 0644);

		self::$configured = self::startHealthy('configured', [
			'-v', "{$secrets}:/run/secrets:ro",
			'-e', 'KIRBY_CONTENT_SALT=salt-from-the-environment',
			'-e', 'KIRBY_COOKIE_KEY_FILE=/run/secrets/cookie-key',
			'-e', 'KIRBY_OPTIONS_JSON={"content":{"salt":"salt-from-options-json"}}',
		]);
		self::$override = self::$configured->probe('secrets.php');
	}

	#[DataProvider('secrets')]
	public function testIsGeneratedIntoTheStorageRoot(string $option): void
	{
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', self::$generated->exec('cat', "/app/storage/{$option}"));
	}

	#[DataProvider('secrets')]
	public function testGeneratedFileIsOnlyReadableByTheKirbyUser(string $option): void
	{
		self::assertSame('kirby 600', self::$generated->exec('stat', '-c', '%U %a', "/app/storage/{$option}"));
	}

	#[DataProvider('secrets')]
	public function testKirbyUsesTheGeneratedValue(string $option): void
	{
		$generated = self::$generated->exec('cat', "/app/storage/{$option}");

		self::assertSame($generated, self::$cli[$option], 'in the CLI');
		self::assertSame($generated, self::$web[$option], 'in the web server');
	}

	public static function secrets(): array
	{
		return self::cases('content.salt', 'cookie.key');
	}

	public function testContentTokensAreSignedWithTheGeneratedSalt(): void
	{
		self::assertSame(hash_hmac('sha1', 'probe', self::$cli['content.salt']), self::$cli['token']);
	}

	public function testAMountedSecretFileSetsTheCookieKey(): void
	{
		self::assertSame(self::COOKIE_KEY, self::$override['cookie.key']);
	}

	public function testKirbyOptionsJsonWinsOverTheSaltVariable(): void
	{
		self::assertSame('salt-from-options-json', self::$override['content.salt']);
	}

	public function testNothingIsGeneratedWhenTheEnvironmentProvidesTheSecrets(): void
	{
		self::assertSame('', self::$configured->exec('find', '/app/storage', '-maxdepth', '1', '-type', 'f'));
	}
}
