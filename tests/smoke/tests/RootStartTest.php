<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * A root start with a foreign-owned bind mount. The entrypoint has to take
 * ownership and then drop back to the kirby user.
 */
final class RootStartTest extends SmokeTestCase
{
	private const string LICENSE = '{"license":"K-SMOKE-TEST"}';

	private static Container $kirby;

	public static function setUpBeforeClass(): void
	{
		$content = self::directory() . '/content';
		mkdir("{$content}/bound", 0700, true);
		file_put_contents("{$content}/bound/default.txt", "Title: Bound\n");
		file_put_contents("{$content}/site.txt", "Title: Site\n");
		chmod("{$content}/bound/default.txt", 0700);
		chmod("{$content}/site.txt", 0700);
		chmod($content, 0700);

		self::$kirby = self::startHealthy('root', [
			'--user', '0:0',
			'-v', "{$content}:/app/content",
			'-e', 'KIRBY_LICENSE=' . self::LICENSE,
		]);
	}

	public function testContentFromTheBindMountIsServed(): void
	{
		self::assertSame(200, self::$kirby->get('/bound')->status);
	}

	public function testTheServerProcessDroppedToTheKirbyUser(): void
	{
		self::assertSame('kirby', self::$kirby->exec('stat', '-c', '%U', '/proc/1'));
	}

	public function testBindMountOwnershipWasFixedUp(): void
	{
		self::assertSame('1000', self::$kirby->exec('stat', '-c', '%u', '/app/content'));
	}

	public function testKirbyLicenseWasWrittenToTheLicenseFile(): void
	{
		self::assertSame(self::LICENSE, self::$kirby->exec('cat', '/app/site/config/.license'));
	}

	public function testLicenseFileIsNotWorldReadable(): void
	{
		self::assertSame('600', self::$kirby->exec('stat', '-c', '%a', '/app/site/config/.license'));
	}
}
