<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\Response;
use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * An empty volume mounted over /app/site, as platforms that do not copy image
 * contents into a new volume hand it to the container. `volume-nocopy` stops
 * Docker from doing that copy itself, which would otherwise hide the case.
 */
final class PersistentSiteTest extends SmokeTestCase
{
	private const string LICENSE = '{"license":"K-SMOKE-TEST"}';

	private const string MARKER = '<!-- persisted-site -->';

	private static Response $seeded;

	private static string $seededOwner;

	private static Container $replacement;

	private static Container $unwritable;

	public static function setUpBeforeClass(): void
	{
		$site = ['--mount', 'type=volume,src=' . self::volume('site') . ',dst=/app/site,volume-nocopy'];
		$license = ['-e', 'KIRBY_LICENSE=' . self::LICENSE];

		// Root, because the fresh volume is owned by root and only the root
		// start may take ownership of it.
		$first = self::startHealthy('site1', ['--user', '0:0', ...$site, ...$license]);
		self::$seeded = $first->get('/');
		self::$seededOwner = $first->exec('stat', '-c', '%u', '/app/site/config/config.php');
		$first->exec('sh', '-c', 'printf "%s\n" ' . escapeshellarg(self::MARKER) . ' >> /app/site/templates/default.php');
		self::stop($first);

		self::$replacement = self::startHealthy('site2', [...$site, ...$license]);

		self::$unwritable = self::start('site-ro', [
			'--mount', 'type=volume,src=' . self::volume('site-ro') . ',dst=/app/site,volume-nocopy',
		]);
	}

	public function testTheEmptySiteRootIsSeededAndServed(): void
	{
		self::assertSame(200, self::$seeded->status);
		self::assertStringContainsString('<h1>Home</h1>', self::$seeded->body);
	}

	public function testTheSeededSiteBelongsToTheKirbyUser(): void
	{
		self::assertSame('1000', self::$seededOwner);
	}

	public function testTheLicenseLandsInTheMountedSite(): void
	{
		self::assertSame(self::LICENSE, self::$replacement->exec('cat', '/app/site/config/.license'));
	}

	public function testChangesToTheSiteSurviveAReplacement(): void
	{
		self::assertStringContainsString(self::MARKER, self::$replacement->get('/')->body);
	}

	public function testTheEnvironmentStillConfiguresAPersistedSite(): void
	{
		self::assertStringContainsString(
			'/usr/local/share/kirby/env-options.php',
			self::$replacement->exec('cat', '/app/site/config/config.php'),
		);
	}

	public function testAnUnwritableEmptySiteRootFailsWithAnExplicitError(): void
	{
		self::assertSame(1, self::$unwritable->waitExited());
		self::assertStringContainsString('/app/site is empty but not writable by uid 1000', self::$unwritable->logs());
	}
}
