<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\Response;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Kirby's Panel installer creates the first account for whoever asks while
 * none exists, if Kirby considers the installation local. It decides that by
 * the host before any address — localhost, *.local, *.test, *.ddev.site —
 * and FrankenPHP takes the host from the request. So the image only lets a
 * visitor on loopback install, or anyone once KIRBY_PANEL_INSTALL is set.
 *
 * The tests reach the container through a published port, so their peer is
 * the Docker bridge, as a remote visitor's would be. The CSRF token is fixed
 * with `api.csrf` so that every request stands on its own; it is not what
 * keeps anyone out.
 */
final class InstallerTest extends SmokeTestCase
{
	private const string CSRF = 'smoke-test-csrf';

	private const string ACCOUNT = '{"email":"admin@example.com","password":"smoke-test-password","role":"admin"}';

	/** No account and nothing configured, as after `docker run`. */
	private static Container $fresh;

	private static Container $allowed;

	private static Container $loopback;

	public static function setUpBeforeClass(): void
	{
		$csrf = ['-e', 'KIRBY_OPTIONS_JSON={"api":{"csrf":"' . self::CSRF . '"}}'];

		self::$fresh = self::startHealthy('install-fresh', $csrf);
		self::$allowed = self::startHealthy('install-allowed', [...$csrf, '-e', 'KIRBY_PANEL_INSTALL=true']);
		self::$loopback = self::startHealthy('install-loopback', $csrf);
	}

	#[DataProvider('localLookingHosts')]
	public function testAHostThatLooksLocalCannotInstall(string $host): void
	{
		self::assertRefused(self::install(self::$fresh, '/api/system/install', ['Host' => $host]));
	}

	public static function localLookingHosts(): array
	{
		return self::cases('localhost', 'LOCALHOST', 'localhost:8080', 'kirby.local', 'kirby.test', 'kirby.ddev.site');
	}

	/** The host of an absolute request target wins over the Host header. */
	public function testAnAbsoluteRequestTargetCannotInstall(): void
	{
		self::assertRefused(self::install(self::$fresh, 'http://localhost/api/system/install', ['Host' => 'example.com']));
	}

	/**
	 * Kirby trims slashes, cuts off URL params and takes the method from
	 * X-HTTP-Method-Override, so the same route has other spellings.
	 *
	 * @param array<string, string> $headers
	 */
	#[DataProvider('otherSpellings')]
	public function testAnotherSpellingOfTheRouteCannotInstall(string $method, string $target, array $headers = []): void
	{
		self::assertRefused(self::install(self::$fresh, $target, ['Host' => 'localhost', ...$headers], $method));
	}

	public static function otherSpellings(): array
	{
		return [
			'trailing slash' => ['POST', '/api/system/install/'],
			'URL params' => ['POST', '/api/system/install/smoke:test'],
			'method override' => ['GET', '/api/system/install', ['X-HTTP-Method-Override' => 'POST']],
		];
	}

	/** Also shows that the request the other tests send would install. */
	public function testKirbyPanelInstallOpensTheInstallerToAnyone(): void
	{
		$response = self::install(self::$allowed, '/api/system/install', ['Host' => 'example.com']);

		self::assertSame(200, $response->status, $response->body);
		self::assertSame(1, self::$allowed->probe('accounts.php')['count']);
	}

	public function testAVisitorOnLoopbackCanInstall(): void
	{
		$status = self::$loopback->exec(
			'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}',
			'-X', 'POST',
			'-H', 'X-CSRF: ' . self::CSRF,
			'-H', 'Content-Type: application/json',
			'--data', self::ACCOUNT,
			'http://127.0.0.1/api/system/install',
		);

		self::assertSame('200', $status);
		self::assertSame(1, self::$loopback->probe('accounts.php')['count']);
	}

	/** @param array<string, string> $headers */
	private static function install(Container $container, string $target, array $headers, string $method = 'POST'): Response
	{
		return $container->request($method, $target, [
			...$headers,
			'X-CSRF' => self::CSRF,
			'Content-Type' => 'application/json',
		], self::ACCOUNT);
	}

	private static function assertRefused(Response $response): void
	{
		self::assertSame(403, $response->status, $response->body);
		self::assertSame(0, self::$fresh->probe('accounts.php')['count'], 'an account was created');
	}
}
