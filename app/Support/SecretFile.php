<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads a secret from a file named by an environment variable (FOO_FILE),
 * the way Docker secrets are handed to a container: /run/secrets/<name>.
 *
 * In Docker the database password is such a file, so it is never in .env, in
 * the image, or in the container's environment (docker inspect shows only the
 * path). Outside Docker the variable is not set and the plain value is used.
 */
final class SecretFile
{
    /**
     * The secret from the file named by $fileVariable, or $fallback when that
     * variable is not set. A variable naming a file that cannot be read is an
     * error: falling back then would sign in with the wrong password.
     */
    public static function value(string $fileVariable, mixed $fallback = null): mixed
    {
        $path = env($fileVariable);
        if ($path === null || $path === '') {
            return $fallback;
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("{$fileVariable} names {$path}, which cannot be read.");
        }

        return trim((string) file_get_contents($path));
    }
}
