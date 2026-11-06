<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Below the document root, the server process can write to the media root
 * and nowhere else, and nothing it could put into the media root is ever
 * executed. Whatever file-write bug a plugin may have, it cannot add a script
 * that the web server serves directly. This does not cover the code Kirby
 * loads from /app/site, /app/kirby and /app/vendor, which the runtime user
 * still owns.
 */
final class DocumentRootTest extends SmokeTestCase
{
	/** Prints the marker only when executed; its source does not contain it. */
	private const string SCRIPT = "<?php echo 'EXEC' . 'UTED';\n";

	private const string EXECUTED = 'EXECUTED';

	private static Container $kirby;

	public static function setUpBeforeClass(): void
	{
		self::$kirby = self::startHealthy('docroot');

		// As the runtime user, which is the point.
		self::$kirby->exec('mkdir', '-p', '/app/public/media/smoke/dir');
		foreach (['probe.php', 'probe.PHP', 'dir/index.php'] as $file) {
			self::$kirby->write("/app/public/media/smoke/{$file}", self::SCRIPT);
		}
		self::$kirby->write('/app/public/media/smoke/static.txt', "static\n");
	}

	#[DataProvider('readOnlyPaths')]
	public function testIsNotWritableByTheServerProcess(string $path): void
	{
		self::assertFalse(self::$kirby->succeeds('test', '-w', $path), "{$path} is writable");
		self::assertSame('root', self::$kirby->exec('stat', '-c', '%U', $path));
	}

	public static function readOnlyPaths(): array
	{
		return self::cases('/app', '/app/public', '/app/public/index.php');
	}

	/** Shows that the script below does print the marker when it runs. */
	public function testAScriptRootPutIntoTheDocumentRootRuns(): void
	{
		self::$kirby->write('/app/public/_smoke-script.php', self::SCRIPT, asRoot: true);

		self::assertStringContainsString(self::EXECUTED, self::$kirby->get('/_smoke-script.php')->body);
	}

	/** A script itself, a path through one, a directory index. */
	#[DataProvider('scriptsInTheMediaRoot')]
	public function testAScriptInTheMediaRootIsNeitherRunNorServed(string $path): void
	{
		$response = self::$kirby->get($path);

		self::assertStringNotContainsString(self::EXECUTED, $response->body, "{$path} was executed");
		self::assertStringNotContainsString("'EXEC'", $response->body, "{$path} was served as source");
		self::assertSame(404, $response->status);
	}

	public static function scriptsInTheMediaRoot(): array
	{
		return self::cases(
			'/media/smoke/probe.php',
			'/media/smoke/probe.PHP',
			'/media/smoke/probe.php/path-info',
			'/media/smoke/dir/',
			'/media/smoke/dir',
			'/media/smoke/dir/index.php',
		);
	}

	public function testOtherFilesInTheMediaRootAreServed(): void
	{
		$response = self::$kirby->get('/media/smoke/static.txt');

		self::assertSame(200, $response->status);
		self::assertSame("static\n", $response->body);
	}
}
