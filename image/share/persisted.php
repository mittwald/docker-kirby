<?php

/**
 * Tells whether a path outlives the container.
 *
 * A path is persisted when the deepest mount that contains it is anything but
 * the container's own root filesystem: a volume, a bind mount, or a path
 * below one of them, such as a site root relocated into the storage volume.
 * Everything else lives in the container's writable layer and is gone once
 * the container is replaced.
 *
 * Returns a function that takes the path. Where the mount table cannot be
 * read, every path counts as persisted, so that the guards built on this
 * never block a change they cannot judge.
 */

return static function (string $path): bool {
	$mounts = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);

	if ($mounts === false) {
		return true;
	}

	$path  = realpath($path) ?: $path;
	$owner = '/';

	foreach ($mounts as $line) {
		// The fifth field is the mount point, with whitespace and
		// backslashes escaped as octal.
		$point = stripcslashes(explode(' ', $line)[4] ?? '');

		$contains = $point === $path || str_starts_with($path, rtrim($point, '/') . '/');

		if ($contains && strlen($point) > strlen($owner)) {
			$owner = $point;
		}
	}

	return $owner !== '/';
};
