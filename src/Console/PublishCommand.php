<?php

namespace Vizuall\StaticPublish\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Statamic\Console\RunsInPlease;
use Statamic\StaticSite\GenerationFailedException;
use Throwable;
use Vizuall\StaticPublish\Publish\Deployer;
use Vizuall\StaticPublish\Publish\Exporter;
use Vizuall\StaticPublish\Publish\RunLog;
use Vizuall\StaticPublish\Publish\Verifier;
use Vizuall\StaticPublish\Settings;
use Wilderborn\Partyline\Facade as Partyline;

/**
 * The one way to publish. The Control Panel button starts this command in
 * the background; a terminal runs it directly. Generate → verify → deploy,
 * with a lock so two runs never overlap and a run log the Control Panel
 * reads. With --no-deploy the copy is generated and checked but not sent.
 */
class PublishCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'static-publish:publish
        {--no-deploy : Generér og kontrollér kopien, men send den ikke til Cloudflare}
        {--user= : ID på den bruger der startede kørslen fra Control Panelet}';

    protected $description = 'Udgiv hele sitet som statiske filer på Cloudflare Workers';

    public function handle(RunLog $log): int
    {
        $deploy = ! $this->option('no-deploy');

        if (! $liveUrl = Settings::liveUrl()) {
            $this->error('Worker-navn og workers.dev-underdomæne skal være sat i addonets indstillinger.');

            return self::FAILURE;
        }

        if ($deploy && ! Settings::hasCredentials()) {
            $this->error('CLOUDFLARE_API_TOKEN og CLOUDFLARE_ACCOUNT_ID mangler i .env.');

            return self::FAILURE;
        }

        $lock = Cache::lock('static-publish', 1800);

        if (! $lock->get()) {
            $this->error('En udgivelse kører allerede.');

            return self::FAILURE;
        }

        $run = $log->start($this->option('user') ?: null, $deploy);
        $destination = config('static-publish.destination');

        Partyline::bind($this);
        $this->info("Kørsel {$run['id']}: {$liveUrl}");

        try {
            $exporter = new Exporter($liveUrl, $destination, (int) config('static-publish.limits.file_bytes'));

            $this->line('Genererer…');
            $exported = $exporter->run();

            $run = $log->step($run, 'verifying');
            $this->line('Tjekker…');

            $verifier = new Verifier(
                $destination,
                parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
                parse_url($liveUrl, PHP_URL_HOST) ?: null,
                (int) config('static-publish.limits.files'),
                (int) config('static-publish.limits.file_bytes'),
                public_path(),
            );

            $report = $verifier->verify($exporter->expectedUrls());
            $report['excluded'] = $exported['excluded'];
            $report['skipped'] = $exported['skipped'];

            if (in_array('/', $exported['excluded'], true)) {
                $report['warnings'][] = 'Forsiden (/) er udeladt, fordi den bruger en redaktør-skabelon eller et redaktør-layout (skabelon_*). Live-sitet har ingen forside, før forsiden bruger en almindelig skabelon.';
            }

            foreach ($exported['skipped'] as $skipped) {
                $report['warnings'][] = $skipped['path'].' fylder '.number_format($skipped['bytes'] / 1048576, 1, ',', '.').' MiB og er ikke med. Cloudflares grænse er 25 MiB pr. fil.';
            }

            $run = $log->update($run, ['report' => $report]);

            foreach ($report['errors'] as $error) {
                $this->error('  ✘ '.$error);
            }

            foreach ($report['warnings'] as $warning) {
                $this->warn('  ! '.$warning);
            }

            $this->line(sprintf('  %d sider, %d filer, %s MB', $report['pages'], $report['files'], number_format($report['bytes'] / 1000000, 1, ',', '.')));

            if ($report['excluded']) {
                $this->line('  Udeladt (redaktørsider): '.implode(', ', $report['excluded']));
            }

            if ($report['external_hosts']) {
                $this->line('  Eksterne hosts: '.implode(', ', $report['external_hosts']));
            }

            if (! $report['ok']) {
                $log->fail($run, 'Kontrollen fandt '.count($report['errors']).' fejl. Live-sitet er ikke rørt.');
                $this->error('Kontrollen fandt fejl. Intet er sendt.');

                return self::FAILURE;
            }

            if (! $deploy) {
                $log->finish($run, RunLog::VERIFIED);
                $this->info("Kopien er kontrolleret og ligger i {$destination}. Ikke sendt (--no-deploy).");

                return self::SUCCESS;
            }

            $run = $log->step($run, 'deploying');
            $this->line('Uploader til Cloudflare…');

            $deployer = new Deployer(Settings::workerName(), Settings::apiToken(), Settings::accountId());
            $deployed = $deployer->deploy($destination, $run['id'], fn ($text) => $this->output->write($text));

            $log->finish($run, RunLog::LIVE, [
                'url' => $deployed['url'] ?? $liveUrl,
                'version_id' => $deployed['version_id'],
            ]);

            $this->info('Live: '.($deployed['url'] ?? $liveUrl));

            return self::SUCCESS;
        } catch (GenerationFailedException $e) {
            $message = trim(strip_tags(preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', (string) $e->getConsoleMessage())));
            $this->line($e->getConsoleMessage());
            $log->fail($run, 'Genereringen fejlede: '.$message);
            $this->error('Genereringen fejlede. Intet er sendt.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $log->fail($run, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
