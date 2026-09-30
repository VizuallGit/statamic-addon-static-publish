<?php

namespace Vizuall\StaticPublish\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Statamic\Console\RunsInPlease;
use Throwable;
use Vizuall\StaticPublish\Publish\Deployer;
use Vizuall\StaticPublish\Publish\RunLog;
use Vizuall\StaticPublish\Settings;

/**
 * Puts an earlier version of the live site back.
 *
 * Nothing is generated and nothing is uploaded: Cloudflare still holds the
 * files of every version, so this only says which one to serve. It takes the
 * same lock as a publish, because a roll-back landing in the middle of an
 * upload would leave the site somewhere between two versions.
 */
class RollbackCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'static-publish:rollback
        {version : Versions-ID fra en tidligere kørsel}
        {--user= : ID på den bruger der startede tilbagerulningen}';

    protected $description = 'Sæt en tidligere udgave af det statiske site live igen';

    public function handle(RunLog $log): int
    {
        $version = (string) $this->argument('version');

        if (! preg_match('/^[0-9a-f-]{8,64}$/i', $version)) {
            $this->error('Det ligner ikke et versions-ID.');

            return self::FAILURE;
        }

        if (! Settings::hasCredentials()) {
            $this->error('CLOUDFLARE_API_TOKEN og CLOUDFLARE_ACCOUNT_ID mangler i .env.');

            return self::FAILURE;
        }

        if (! $worker = Settings::workerName()) {
            $this->error('Worker-navn mangler i addonets indstillinger.');

            return self::FAILURE;
        }

        $lock = Cache::lock('static-publish', 300);

        if (! $lock->get()) {
            $this->error('En udgivelse kører allerede.');

            return self::FAILURE;
        }

        $run = $log->start($this->option('user') ?: null, true, RunLog::ROLLBACK);
        $run = $log->update($run, ['version_id' => $version]);

        try {
            $deployer = new Deployer($worker, Settings::apiToken(), Settings::accountId());

            $deployer->rollback(
                $version,
                'Rullet tilbage fra Static Publish',
                fn ($text) => $this->output->write($text)
            );

            $log->finish($run, RunLog::LIVE, ['url' => Settings::liveUrl()]);

            $this->info('Live igen på version '.$version.'.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $log->fail($run, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
