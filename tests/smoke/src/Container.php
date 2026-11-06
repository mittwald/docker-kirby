<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke;

use JsonException;
use RuntimeException;

/**
 * One running container, with the ports the tests talk to published on free
 * host ports picked here.
 *
 * Not `-P`: Docker Desktop occasionally starts a `-P` container with every
 * binding empty and never fills them in, which surfaced as a health check
 * timing out on a server that was up. A port named explicitly either binds or
 * makes `docker run` fail, and a failed `docker run` started nothing, so it
 * is safe to retry with fresh ports.
 */
final class Container
{
	private const string PROBES = __DIR__ . '/../probes/';

	/** @param array<int, int> $ports container port => host port */
	private function __construct(public readonly string $name, private readonly array $ports)
	{
	}

	/**
	 * @param list<string> $args docker run options
	 * @param list<int> $ports container ports to publish on 127.0.0.1
	 */
	public static function start(string $image, string $name, array $args = [], array $ports = [80, 8090]): self
	{
		for ($attempt = 1; ; $attempt++) {
			$hostPorts = [];
			$publish = [];
			foreach ($ports as $port) {
				$hostPorts[$port] = self::freePort();
				array_push($publish, '-p', "127.0.0.1:{$hostPorts[$port]}:{$port}");
			}

			Docker::attempt(['rm', '-f', '-v', $name]);
			$run = ['run', '-d', '--name', $name, ...Docker::labelArgs(), ...$publish, ...$args, $image];

			// Another process can take a free port before docker binds it.
			[$exitCode, , $stderr] = Docker::attempt($run);
			if ($exitCode !== 0 && $attempt < 3 && preg_match('/already allocated|address already in use/i', $stderr)) {
				continue;
			}
			if ($exitCode !== 0) {
				throw new RuntimeException("docker run for {$name} exited with {$exitCode}\n{$stderr}");
			}

			return new self($name, $hostPorts);
		}
	}

	private static function freePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
		if ($socket === false) {
			throw new RuntimeException("cannot find a free port: {$error}");
		}
		$address = (string) stream_socket_get_name($socket, false);
		fclose($socket);

		return (int) substr((string) strrchr($address, ':'), 1);
	}

	public function remove(): void
	{
		// -v takes the anonymous volumes from the image's VOLUME instruction
		// along; named volumes are left alone.
		Docker::attempt(['rm', '-f', '-v', $this->name]);
	}

	public function isRunning(): bool
	{
		[$exitCode, $running] = Docker::attempt(['inspect', '-f', '{{.State.Running}}', $this->name]);
		return $exitCode === 0 && trim($running) === 'true';
	}

	public function port(int $containerPort): int
	{
		if (isset($this->ports[$containerPort]) === false) {
			throw new RuntimeException("{$this->name} does not publish port {$containerPort}");
		}

		return $this->ports[$containerPort];
	}

	public function waitHealthy(): void
	{
		$this->waitForHttp('/healthz', 8090, 60);
	}

	/** Waits until the path answers 200, and fails with the logs if it never does. */
	public function waitForHttp(string $path, int $port, int $seconds): void
	{
		$last = 'no attempt made';

		for ($attempt = 0; $attempt < $seconds; $attempt++) {
			if (!$this->isRunning()) {
				throw new RuntimeException("{$this->name} exited before {$path} answered\n" . $this->logs());
			}

			try {
				$status = $this->get($path, port: $port)->status;
				if ($status === 200) {
					return;
				}
				$last = "answered {$status}";
			} catch (RuntimeException $e) {
				// Usually just not listening yet, but kept for the message
				// below: a timeout that does not say why costs a rerun.
				$last = $e->getMessage();
			}

			sleep(1);
		}

		throw new RuntimeException("{$this->name} did not answer {$path} within {$seconds}s; last attempt: {$last}\n" . $this->logs());
	}

	/**
	 * For containers that are supposed to stop on their own. Returns the exit
	 * code, or null if the container is still running after the timeout, so a
	 * container that never stopped cannot pass for one that exited.
	 */
	public function waitExited(int $seconds = 30): ?int
	{
		for ($attempt = 0; $attempt < $seconds; $attempt++) {
			if (!$this->isRunning()) {
				return (int) Docker::run(['inspect', '-f', '{{.State.ExitCode}}', $this->name]);
			}
			sleep(1);
		}

		return null;
	}

	public function logs(): string
	{
		[, $stdout, $stderr] = Docker::attempt(['logs', $this->name]);
		return $stdout . $stderr;
	}

	/** Runs a command in the container and returns its output; throws if it fails. */
	public function exec(string ...$command): string
	{
		return Docker::run(['exec', $this->name, ...$command]);
	}

	/** Whether a command in the container exits with 0, e.g. `test -w PATH`. */
	public function succeeds(string ...$command): bool
	{
		[$exitCode] = Docker::attempt(['exec', $this->name, ...$command]);
		return $exitCode === 0;
	}

	/**
	 * Writes a file in the container, as the runtime user unless asked for
	 * root. Throws if that user cannot write it.
	 */
	public function write(string $path, string $content, bool $asRoot = false): void
	{
		$user = $asRoot ? ['-u', '0'] : [];

		Docker::run(['exec', '-i', ...$user, $this->name, 'sh', '-c', 'cat > "$0"', $path], $content);
	}

	/**
	 * Runs a script from probes/ through the PHP CLI and returns the JSON
	 * object it prints. A probe that did not run, or printed anything else,
	 * is an error, never an empty result.
	 *
	 * @param array<string, string> $env
	 * @return array<string, mixed>
	 */
	public function probe(string $script, array $env = []): array
	{
		$envArgs = [];
		foreach ($env as $name => $value) {
			array_push($envArgs, '-e', "{$name}={$value}");
		}

		$output = Docker::run(['exec', '-i', ...$envArgs, $this->name, 'php'], $this->probeSource($script));

		return $this->decode($script, $output);
	}

	/**
	 * Like probe(), but served by FrankenPHP rather than run by the CLI, for
	 * settings that only apply to the server.
	 *
	 * Written as root: the document root belongs to root, so that the server
	 * process cannot put a script there itself.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	public function webProbe(string $script, array $headers = []): array
	{
		$target = '/app/public/_probe.php';

		$this->write($target, $this->probeSource($script), asRoot: true);
		try {
			$response = $this->get('/_probe.php', headers: $headers);
		} finally {
			Docker::run(['exec', '-u', '0', $this->name, 'rm', '-f', $target]);
		}

		if ($response->status !== 200) {
			throw new RuntimeException("web probe {$script} answered {$response->status}\n{$response->body}");
		}

		return $this->decode($script, $response->body);
	}

	/** @param array<string, string> $headers */
	public function get(string $path, bool $follow = false, int $port = 80, array $headers = []): Response
	{
		$headerLines = [];
		foreach ($headers as $name => $value) {
			$headerLines[] = "{$name}: {$value}";
		}

		$context = stream_context_create(['http' => [
			'ignore_errors' => true,
			'follow_location' => $follow ? 1 : 0,
			'timeout' => 10,
			'header' => $headerLines,
		]]);

		$body = @file_get_contents("http://127.0.0.1:{$this->port($port)}{$path}", false, $context);

		if ($body === false) {
			throw new RuntimeException("GET {$path} on {$this->name}:{$port} failed: " . (error_get_last()['message'] ?? 'no response'));
		}

		return Response::fromWrapper(http_get_last_response_headers() ?? [], $body);
	}

	/**
	 * Sends one request exactly as given, for what PHP's HTTP wrapper cannot
	 * send: any method with a body, or an absolute request target such as
	 * `http://localhost/`. HTTP/1.0, so that the response is never chunked
	 * and ends with the connection.
	 *
	 * @param array<string, string> $headers
	 */
	public function request(string $method, string $target, array $headers = [], string $body = '', int $port = 80): Response
	{
		$socket = @stream_socket_client("tcp://127.0.0.1:{$this->port($port)}", $errorCode, $error, 10);
		if ($socket === false) {
			throw new RuntimeException("{$method} {$target} on {$this->name}:{$port} failed: {$error}");
		}
		stream_set_timeout($socket, 10);

		$headers += ['Host' => 'localhost', 'Content-Length' => (string) strlen($body), 'Connection' => 'close'];
		$request = "{$method} {$target} HTTP/1.0\r\n";
		foreach ($headers as $name => $value) {
			$request .= "{$name}: {$value}\r\n";
		}
		fwrite($socket, $request . "\r\n" . $body);

		$raw = (string) stream_get_contents($socket);
		$timedOut = stream_get_meta_data($socket)['timed_out'];
		fclose($socket);

		if ($timedOut || !str_contains($raw, "\r\n\r\n")) {
			throw new RuntimeException("{$method} {$target} on {$this->name}:{$port} got no complete response:\n{$raw}");
		}

		[$head, $responseBody] = explode("\r\n\r\n", $raw, 2);

		return Response::fromWrapper(explode("\r\n", $head), $responseBody);
	}

	private function probeSource(string $script): string
	{
		$source = file_get_contents(self::PROBES . $script);
		if ($source === false) {
			throw new RuntimeException("no such probe: {$script}");
		}
		return $source;
	}

	/** @return array<string, mixed> */
	private function decode(string $script, string $output): array
	{
		try {
			$decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("probe {$script} did not print JSON ({$e->getMessage()}):\n{$output}", previous: $e);
		}

		if (!is_array($decoded)) {
			throw new RuntimeException("probe {$script} did not print a JSON object:\n{$output}");
		}

		return $decoded;
	}
}
