<?php

namespace App\Routing;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Loads every routes/api/v1/<audience>/**.php file in a fixed (sorted) order.
 * The folder path becomes the URL prefix; the top-level folder picks the middleware.
 */
class ApiRouteLoader
{
    /**
     * A valid token whose device session is still open.
     */
    public const AUTHENTICATED = ['auth:api', 'device.session'];

    /**
     * Middleware per audience folder. `auth/` and `shared/` routes opt in to auth per route
     * (use AUTHENTICATED there). `device.session` rejects tokens of logged-out devices.
     */
    public const AUDIENCES = [
        'auth' => [],
        'shared' => [],
        'client' => [...self::AUTHENTICATED, 'account.active', 'user.type:client'],
        'provider' => [...self::AUTHENTICATED, 'account.active', 'user.type:provider'],
        'admin' => [...self::AUTHENTICATED, 'account.active', 'user.type:admin'],
    ];

    public static function load(string $basePath): void
    {
        if (! is_dir($basePath)) {
            return;
        }

        $files = Finder::create()->files()->name('*.php')->in($basePath)->sortByName();

        foreach ($files as $file) {
            $relativeDir = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePath());
            $audience = strtok($relativeDir, '/');

            if ($relativeDir === '' || ! array_key_exists($audience, self::AUDIENCES)) {
                throw new RuntimeException(sprintf(
                    'Route file [%s] must live in one of the audience folders: %s.',
                    $file->getRelativePathname(),
                    implode(', ', array_keys(self::AUDIENCES)),
                ));
            }

            Route::prefix($relativeDir)
                ->middleware(self::AUDIENCES[$audience])
                ->group($file->getPathname());
        }
    }
}
