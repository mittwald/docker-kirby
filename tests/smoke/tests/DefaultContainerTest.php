<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A default run with no configuration beyond a renamed panel and a raised
 * memory limit, so the environment -> Kirby and environment -> PHP paths are
 * exercised end to end.
 */
final class DefaultContainerTest extends SmokeTestCase
{
	private static Container $kirby;

	/** @var array<string, mixed> */
	private static array $ini;

	public static function setUpBeforeClass(): void
	{
		self::$kirby = self::startHealthy('default', [
			'-e', 'KIRBY_PANEL_SLUG=steuerung',
			'-e', 'PHP_MEMORY_LIMIT=512M',
			'-e', 'KIRBY_OPTIONS_JSON={"smartypants":true}',
		]);
		self::$ini = self::$kirby->webProbe('ini.php');
	}

	// --- serving ------------------------------------------------------------

	public function testHealthEndpointResponds(): void
	{
		self::assertSame(200, self::$kirby->get('/healthz', port: 8090)->status);
	}

	public function testHomePageIsRenderedByKirby(): void
	{
		$home = self::$kirby->get('/');
		self::assertSame(200, $home->status);
		self::assertStringContainsString('<h1>Home</h1>', $home->body);
	}

	/** A raw Caddy 404 would not contain the content from content/error. */
	public function testUnknownPageIsKirbysErrorPage(): void
	{
		$missing = self::$kirby->get('/this-page-does-not-exist');
		self::assertSame(404, $missing->status);
		self::assertStringContainsString('<h1>Error</h1>', $missing->body);
	}

	// --- the skeleton came from plainkit -------------------------------------
	// If that ever silently stops happening, the image ships an empty site that
	// still answers 200.

	public function testComposerRootPackageIsPlainkit(): void
	{
		self::assertSame('getkirby/plainkit', self::$kirby->exec(
			'php', '-r', 'echo json_decode(file_get_contents("/app/composer.json"))->name;',
		));
	}

	#[DataProvider('plainkitFiles')]
	public function testPlainkitProvided(string $path): void
	{
		self::assertTrue(self::$kirby->succeeds('test', '-e', $path), "plainkit did not provide {$path}");
	}

	public static function plainkitFiles(): array
	{
		return self::cases(
			'/app/site/templates/default.php',
			'/app/site/blueprints/site.yml',
			'/app/site/blueprints/pages/default.yml',
			'/app/content/home',
			'/app/content/error',
		);
	}

	// --- environment configuration reaches Kirby and PHP ---------------------

	/** The panel redirects an anonymous visitor to its login view. */
	public function testPanelIsServedFromKirbyPanelSlug(): void
	{
		self::assertSame(200, self::$kirby->get('/steuerung', follow: true)->status);
	}

	public function testDefaultPanelSlugIsGoneOnceOverridden(): void
	{
		self::assertSame(404, self::$kirby->get('/panel')->status);
	}

	/**
	 * PHP_* variables only reach the server, through the Caddyfile's php_ini
	 * block. The CLI keeps the static defaults from zz-kirby.ini, which also
	 * confirms that file is loaded at all.
	 */
	public function testStaticPhpIniDefaultsApplyToTheCli(): void
	{
		self::assertSame('256M', self::$kirby->exec('php', '-r', 'echo ini_get("memory_limit");'));
	}

	#[DataProvider('serverIniSettings')]
	public function testServerIniSetting(string $setting, string $expected): void
	{
		self::assertSame($expected, self::$ini[$setting]);
	}

	public static function serverIniSettings(): array
	{
		return [
			'PHP_MEMORY_LIMIT is applied' => ['memory_limit', '512M'],
			'upload_max_filesize keeps its default' => ['upload_max_filesize', '128M'],
			'OPcache is enabled' => ['opcache.enable', '1'],
			'OPcache timestamp validation is off' => ['opcache.validate_timestamps', '0'],
			'timezone defaults to UTC' => ['date.timezone', 'UTC'],
			'display_errors is off' => ['display_errors', ''],
		];
	}

	// --- hardening ------------------------------------------------------------

	#[DataProvider('blockedPaths')]
	public function testIsNotWebAccessible(string $path): void
	{
		self::assertSame(404, self::$kirby->get($path)->status);
	}

	public static function blockedPaths(): array
	{
		return self::cases(
			'/.env',
			'/.git/config',
			'/site/config/config.php',
			'/content/site.txt',
			'/kirby/bootstrap.php',
			'/storage/sessions',
			'/composer.json',
		);
	}

	public function testSecurityHeadersAreSet(): void
	{
		self::assertSame('nosniff', self::$kirby->get('/')->header('X-Content-Type-Options'));
	}

	public function testPhpVersionIsNotAdvertised(): void
	{
		self::assertNull(self::$kirby->get('/')->header('X-Powered-By'));
	}

	// --- runtime identity and contents ----------------------------------------

	public function testRunsAsTheUnprivilegedKirbyUser(): void
	{
		self::assertSame('1000', self::$kirby->exec('id', '-u'));
		self::assertSame('1000', self::$kirby->exec('id', '-g'));
	}

	public function testKirbyReportsThePinnedVersion(): void
	{
		$expected = self::expected('kirby');
		self::assertSame($expected, self::$kirby->exec(
			'php', '-r', 'require "/app/kirby/bootstrap.php"; echo Kirby::version();',
		));
		self::assertSame($expected, self::$kirby->exec('printenv', 'KIRBY_VERSION'));
	}

	public function testPhpVersion(): void
	{
		self::assertSame(self::expected('php'), self::$kirby->exec(
			'php', '-r', 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;',
		));
	}

	#[DataProvider('extensions')]
	public function testPhpExtensionIsPresent(string $extension): void
	{
		// The whole list costs nothing and saves a round trip: an extension
		// can be missing on one architecture only.
		$modules = self::$kirby->exec('php', '-m');
		self::assertContains($extension, explode("\n", $modules), "php -m reported:\n{$modules}");
	}

	public static function extensions(): array
	{
		return self::cases('gd', 'intl', 'exif', 'zip', 'apcu', 'Zend OPcache');
	}

	#[DataProvider('writableRoots')]
	public function testIsWritable(string $path): void
	{
		self::assertTrue(self::$kirby->succeeds('test', '-w', $path), "{$path} is not writable");
	}

	public static function writableRoots(): array
	{
		return self::cases(
			'/app/content',
			'/app/storage/accounts',
			'/app/storage/cache',
			'/app/storage/sessions',
			'/app/storage/logs',
			'/app/public/media',
		);
	}

	public function testNoFatalPhpErrorsInTheLog(): void
	{
		self::assertStringNotContainsString('PHP Fatal error', self::$kirby->logs());
	}

	public function testCaddyfileIsCanonicallyFormatted(): void
	{
		self::assertStringNotContainsString('not formatted', self::$kirby->logs());
	}
}
