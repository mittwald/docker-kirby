<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke;

use RuntimeException;

/**
 * The docker CLI, called without a shell in between. Everything the suite
 * creates carries a label with the run id, so sweep() can remove it even when
 * a test class never got to clean up after itself.
 */
final class Docker
{
	public const string LABEL = 'kirby-smoke.run';

	public static string $runId = 'kirby-smoke';

	/**
	 * Runs docker and returns its stdout with trailing newlines removed, like
	 * a shell's command substitution. A non-zero exit is an exception: a
	 * command that failed must never look like one that printed nothing.
	 *
	 * @param list<string> $args
	 */
	public static function run(array $args, ?string $stdin = null): string
	{
		[$exitCode, $stdout, $stderr] = self::attempt($args, $stdin);

		if ($exitCode !== 0) {
			throw new RuntimeException(sprintf(
				"docker %s exited with %d\n--- stdout\n%s\n--- stderr\n%s",
				implode(' ', $args),
				$exitCode,
				$stdout,
				$stderr,
			));
		}

		return rtrim($stdout, "\n");
	}

	/**
	 * Runs docker and reports the exit code instead of throwing.
	 *
	 * @param list<string> $args
	 * @return array{int, string, string} exit code, stdout, stderr
	 */
	public static function attempt(array $args, ?string $stdin = null): array
	{
		// Files rather than pipes, so a chatty stderr cannot block stdout.
		$stdout = tmpfile();
		$stderr = tmpfile();

		$process = proc_open(
			['docker', ...$args],
			[0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
			$pipes,
		);

		if ($process === false) {
			throw new RuntimeException('could not start the docker CLI');
		}

		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}
		fclose($pipes[0]);

		$exitCode = proc_close($process);

		rewind($stdout);
		rewind($stderr);

		return [$exitCode, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
	}

	/** @return list<string> */
	public static function labelArgs(): array
	{
		return ['--label', self::LABEL . '=' . self::$runId];
	}

	public static function createVolume(string $name): string
	{
		self::run(['volume', 'create', ...self::labelArgs(), $name]);
		return $name;
	}

	public static function createNetwork(string $name): string
	{
		self::run(['network', 'create', ...self::labelArgs(), $name]);
		return $name;
	}

	/** Removes every container, network and volume this run created. */
	public static function sweep(): void
	{
		$filter = ['--filter', 'label=' . self::LABEL . '=' . self::$runId];

		$kinds = [
			[['ps', '-aq', ...$filter], ['rm', '-f', '-v']],
			[['network', 'ls', '-q', ...$filter], ['network', 'rm']],
			[['volume', 'ls', '-q', ...$filter], ['volume', 'rm', '-f']],
		];

		foreach ($kinds as [$list, $remove]) {
			[, $ids] = self::attempt($list);
			$ids = array_values(array_filter(explode("\n", $ids)));
			if ($ids !== []) {
				self::attempt([...$remove, ...$ids]);
			}
		}
	}
}
