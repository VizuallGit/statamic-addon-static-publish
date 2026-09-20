<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts `please static-publish:publish` detached from the web request, so
 * the Control Panel gets its answer at once and follows the run through the
 * run log. Its console output lands in storage/app/static-publish/console.log.
 */
class Launcher
{
    public static function start(?string $userId): void
    {
        $php = config('static-publish.php') ?: (new PhpExecutableFinder)->find(false);

        if (! $php) {
            throw new RuntimeException('Fandt ingen php-kommandolinje. Sæt STATIC_PUBLISH_PHP i .env.');
        }

        $log = storage_path('app/static-publish/console.log');
        File::ensureDirectoryExists(dirname($log));

        $command = sprintf(
            'nohup %s %s static-publish:publish --no-interaction --user=%s > %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('please')),
            escapeshellarg((string) $userId),
            escapeshellarg($log)
        );

        exec($command);
    }
}
