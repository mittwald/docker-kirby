<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke;

final readonly class Response
{
	/** @param array<string, string> $headers lower-cased names of the final response */
	public function __construct(
		public int $status,
		public array $headers,
		public string $body,
	) {
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}

	/**
	 * @param list<string> $raw what PHP's HTTP wrapper reports; after redirects
	 *                          it holds every response in turn, so only the
	 *                          last status line and what follows it count
	 */
	public static function fromWrapper(array $raw, string $body): self
	{
		$status = 0;
		$headers = [];

		foreach ($raw as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
				$status = (int) $match[1];
				$headers = [];
			} elseif (str_contains($line, ':')) {
				[$name, $value] = explode(':', $line, 2);
				$headers[strtolower(trim($name))] = trim($value);
			}
		}

		return new self($status, $headers, $body);
	}
}
