<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Everything this addon says to Cloudflare goes through here: the deploy of
 * a verified copy, the secret the Worker needs to reach back to the CMS, and
 * a roll-back to an earlier version.
 *
 * The wrangler config is generated per run in a temporary folder that is
 * removed afterwards, and the API token and account ID go in as environment
 * variables for that one process. Neither is ever written to disk.
 */
class Deployer
{
    public function __construct(
        protected string $workerName,
        protected string $apiToken,
        protected string $accountId,
        protected ?string $cmsOrigin = null,
        protected ?string $formSecret = null,
    ) {}

    /** Whether this deploy carries the Worker script that answers form posts. */
    public function handlesForms(): bool
    {
        return $this->cmsOrigin !== null && $this->formSecret !== null && $this->formSecret !== '';
    }

    /**
     * @param  callable(string): void  $line  receives wrangler's output as it arrives
     * @return array{url: ?string, version_id: ?string}
     */
    public function deploy(string $buildDir, string $runId, callable $line): array
    {
        $work = storage_path("app/static-publish/tmp/{$runId}");
        File::ensureDirectoryExists($work);

        try {
            File::put("{$work}/wrangler.jsonc", json_encode(
                $this->config($buildDir, $work),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            $result = $this->wrangler($work, [
                'deploy', '--config', "{$work}/wrangler.jsonc",
            ], $line);

            if (! $result->successful()) {
                throw new RuntimeException('wrangler deploy fejlede: '.trim($result->errorOutput() ?: $result->output()));
            }

            $output = $result->output();

            preg_match('/https:\/\/[a-z0-9.-]+\.workers\.dev/i', $output, $url);
            preg_match('/Current Version ID:\s*([0-9a-f-]+)/i', $output, $version);

            $versionId = $version[1] ?? null;

            // Setting a secret makes a new version of the Worker, so the id
            // wrangler just printed would no longer be the one live. Asking
            // which version is current keeps the roll-back button honest.
            if ($this->handlesForms() && $this->ensureSecret($work, $line)) {
                $versionId = $this->currentVersionId($work) ?? $versionId;
            }

            return ['url' => $url[0] ?? null, 'version_id' => $versionId];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Puts an earlier version back. Cloudflare keeps the files of every
     * version, so this needs no copy and no build: the same bytes that were
     * live then are live again within seconds.
     *
     * @param  callable(string): void  $line
     */
    public function rollback(string $versionId, string $message, callable $line): void
    {
        $work = storage_path('app/static-publish/tmp/rollback-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($work);

        try {
            $result = $this->wrangler($work, [
                'rollback', $versionId, '--name', $this->workerName, '--message', $message,
            ], $line);

            if (! $result->successful()) {
                throw new RuntimeException('wrangler rollback fejlede: '.trim($result->errorOutput() ?: $result->output()));
            }
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Without forms this is an assets-only Worker: static files and no code
     * at all, so nothing runs per request. With forms the Worker script comes
     * along, and run_worker_first sends that one path to it while everything
     * else is still answered straight from the files.
     */
    protected function config(string $buildDir, string $work): array
    {
        $assets = [
            'directory' => $buildDir,
            'not_found_handling' => '404-page',
            // Statamic's canonical URLs have no trailing slash: /om-os serves
            // om-os/index.html directly, and /om-os/ redirects to /om-os.
            'html_handling' => 'drop-trailing-slash',
        ];

        $config = [
            'name' => $this->workerName,
            'compatibility_date' => config('static-publish.compatibility_date'),
        ];

        if ($this->handlesForms()) {
            File::copy(__DIR__.'/../../resources/worker/worker.js', "{$work}/worker.js");

            $config['main'] = 'worker.js';
            $assets['binding'] = 'ASSETS';
            $assets['run_worker_first'] = [Forms::ACTION_PREFIX.'*'];
            $config['vars'] = [Forms::ORIGIN_VAR => $this->cmsOrigin];
        }

        $config['assets'] = $assets;

        return $config;
    }

    /**
     * Cloudflare keeps a secret across deploys, so it is only sent when it is
     * not already the one up there. What is up there cannot be read back, so
     * a fingerprint of the last one sent is kept beside the run log — the
     * secret itself is never written down.
     *
     * @return bool whether the secret was sent, which means a new version exists
     */
    protected function ensureSecret(string $work, callable $line): bool
    {
        $path = storage_path('app/static-publish/worker-secret.json');
        $fingerprint = hash('sha256', $this->workerName.':'.$this->formSecret);

        if (is_file($path) && (json_decode(File::get($path), true)['fingerprint'] ?? null) === $fingerprint) {
            return false;
        }

        $line("Sætter formular-nøglen på Workeren…\n");

        $result = $this->wrangler($work, [
            'secret', 'put', Forms::SECRET_VAR, '--name', $this->workerName,
        ], $line, $this->formSecret);

        if (! $result->successful()) {
            throw new RuntimeException('Formular-nøglen kunne ikke sættes på Workeren: '.trim($result->errorOutput() ?: $result->output()));
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'worker' => $this->workerName,
            'fingerprint' => $fingerprint,
            'set_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        return true;
    }

    /** The newest version Cloudflare holds for this Worker. */
    protected function currentVersionId(string $work): ?string
    {
        $result = $this->wrangler($work, [
            'versions', 'list', '--name', $this->workerName, '--json',
        ], fn () => null);

        if (! $result->successful()) {
            return null;
        }

        $versions = json_decode($result->output(), true);

        if (! is_array($versions) || $versions === []) {
            return null;
        }

        usort($versions, fn ($a, $b) => strcmp(
            (string) ($b['metadata']['created_on'] ?? ''),
            (string) ($a['metadata']['created_on'] ?? '')
        ));

        return $versions[0]['id'] ?? null;
    }

    /**
     * @param  list<string>  $arguments
     * @param  callable(string): void  $line
     */
    protected function wrangler(string $work, array $arguments, callable $line, ?string $input = null)
    {
        $npx = (string) config('static-publish.npx');

        // A web server's PHP process rarely has node on its PATH. When npx is
        // given as an absolute path (STATIC_PUBLISH_NPX), its folder goes first
        // on PATH for this one process, so the `node` that npx itself needs
        // is found next to it.
        $path = str_starts_with($npx, '/')
            ? dirname($npx).PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin')
            : (getenv('PATH') ?: '/usr/bin:/bin');

        $process = Process::path($work)
            ->timeout(900)
            ->env([
                'PATH' => $path,
                'CLOUDFLARE_API_TOKEN' => $this->apiToken,
                'CLOUDFLARE_ACCOUNT_ID' => $this->accountId,
                'WRANGLER_SEND_METRICS' => 'false',
                'CI' => 'true',
                'NO_COLOR' => '1',
            ]);

        if ($input !== null) {
            $process = $process->input($input);
        }

        return $process->run(
            array_merge([$npx, '--yes', config('static-publish.wrangler')], $arguments),
            fn ($type, $buffer) => $line($buffer)
        );
    }
}
