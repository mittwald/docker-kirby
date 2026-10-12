<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * Kirby throttles failed logins per visitor address and opens its installer
 * to loopback visitors, so PHP has to see the client behind the proxy, and
 * nothing a client sends may make it look local.
 *
 * The tests reach the container through a published port, so the peer is the
 * Docker bridge gateway: a private address, trusted by default, which makes
 * it stand in for an ingress.
 */
final class ClientAddressTest extends SmokeTestCase
{
	private const string CLIENT = '203.0.113.7';

	private static Container $default;

	/** Trusts the private networks the peer is on, but not loopback. */
	private static Container $narrowed;

	public static function setUpBeforeClass(): void
	{
		self::$default = self::startHealthy('client-default');
		self::$narrowed = self::startHealthy('client-narrowed', [
			'-e', 'TRUSTED_PROXIES=10.0.0.0/8 172.16.0.0/12 192.168.0.0/16',
		]);
	}

	public function testForwardedClientReachesKirby(): void
	{
		$visitor = self::$default->webProbe('visitor.php', ['X-Forwarded-For' => self::CLIENT]);

		self::assertSame(self::CLIENT, $visitor['REMOTE_ADDR']);
		self::assertSame(self::CLIENT, $visitor['visitor.ip']);
	}

	/** A proxy appends the address it saw to whatever the client sent. */
	public function testClientCannotPrependItsOwnAddress(): void
	{
		$visitor = self::$default->webProbe('visitor.php', ['X-Forwarded-For' => '198.51.100.1, ' . self::CLIENT]);

		self::assertSame(self::CLIENT, $visitor['visitor.ip']);
	}

	public function testForwardedLoopbackIsNotLocal(): void
	{
		$visitor = self::$narrowed->webProbe('visitor.php', ['X-Forwarded-For' => '127.0.0.1']);

		self::assertNotSame('127.0.0.1', $visitor['visitor.ip']);
		self::assertFalse($visitor['isLocal']);
	}

	/** The guard must not break forwarding when loopback is not trusted. */
	public function testNarrowedTrustStillForwards(): void
	{
		$visitor = self::$narrowed->webProbe('visitor.php', ['X-Forwarded-For' => self::CLIENT]);

		self::assertSame(self::CLIENT, $visitor['visitor.ip']);
	}
}
