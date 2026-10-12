<?php

/**
 * The secrets Kirby needs but cannot generate itself.
 *
 * Kirby signs the preview links of drafts and unsaved changes and builds media
 * URLs with `content.salt`, and signs cookies with `cookie.key`. Left unset,
 * the salt falls back to the path of the content folder and the key to a
 * constant. In this image, that path is `/app/content` in every container, so
 * anyone could compute valid preview tokens offline.
 *
 * Each secret can be set with its variable, or with the same variable suffixed
 * `_FILE` pointing at a mounted secret. When neither is set, the entrypoint
 * generates a random value into the storage root on the first start, and
 * `env-options.php` reads it from there. It has to persist: a new salt changes
 * every media URL, and a new cookie key ends every session.
 *
 * Returns a function that takes the storage root and lists, per option, the
 * variable that sets it and the file the generated value is kept in.
 *
 * @see https://getkirby.com/security/content-salt
 */

return static fn (string $storage): array => [
	'content.salt' => ['KIRBY_CONTENT_SALT', $storage . '/content.salt'],
	'cookie.key'   => ['KIRBY_COOKIE_KEY', $storage . '/cookie.key'],
];
