<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts one of the addon's commands detached from the web request, so the
 * Control Panel gets its answer at once and follows the work through the run
 * log rather than holding a connection open for a minute. The console output
 * lands in storage/app/static-publish/console.log.
 */
class Launcher
{
    /** @param list<string> $arguments */
    public static function start(string $command, array $arguments, ?string $userId): void
    {
        $php = config('static-publish.php') ?: (new PhpExecutableFinder)->find(false);

        if (! $php) {
            throw new RuntimeException('Fandt ingen php-kommandolinje. Sæt STATIC_PUBLISH_PHP i .env.');
        }

        $log = storage_path('app/static-publish/console.log');
        File::ensureDirectoryExists(dirname($log));

        $parts = array_map('escapeshellarg', array_merge(
            [$php, base_path('please'), $command],
            $arguments,
            ['--no-interaction', '--user='.$userId]
        ));

        exec('nohup '.implode(' ', $parts).' > '.escapeshellarg($log).' 2>&1 &');
    }
}
