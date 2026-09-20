<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Sends the verified copy to Cloudflare with `wrangler deploy`. The Worker
 * is assets-only: a generated wrangler.jsonc with `assets` and no `main`, in
 * a temporary folder that is removed afterwards. The token and account ID go
 * in as environment variables for that one process and are never written to
 * disk.
 */
class Deployer
{
    public function __construct(
        protected string $workerName,
        protected string $apiToken,
        protected string $accountId,
    ) {}

    /**
     * @param  callable(string): void  $line  receives wrangler's output as it arrives
     * @return array{url: ?string, version_id: ?string, output: string}
     */
    public function deploy(string $buildDir, string $runId, callable $line): array
    {
        $work = storage_path("app/static-publish/tmp/{$runId}");
        File::ensureDirectoryExists($work);

        File::put("{$work}/wrangler.jsonc", json_encode([
            'name' => $this->workerName,
            'compatibility_date' => config('static-publish.compatibility_date'),
            'assets' => [
                'directory' => $buildDir,
                'not_found_handling' => '404-page',
                // Statamic's canonical URLs have no trailing slash: /om-os serves
                // om-os/index.html directly, and /om-os/ redirects to /om-os.
                'html_handling' => 'drop-trailing-slash',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $npx = (string) config('static-publish.npx');

        // A web server's PHP process rarely has node on its PATH. When npx is
        // given as an absolute path (STATIC_PUBLISH_NPX), its folder goes first
        // on PATH for this one process, so the `node` that npx itself needs
        // is found next to it.
        $path = str_starts_with($npx, '/')
            ? dirname($npx).PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin')
            : (getenv('PATH') ?: '/usr/bin:/bin');

        try {
            $result = Process::path($work)
                ->timeout(900)
                ->env([
                    'PATH' => $path,
                    'CLOUDFLARE_API_TOKEN' => $this->apiToken,
                    'CLOUDFLARE_ACCOUNT_ID' => $this->accountId,
                    'WRANGLER_SEND_METRICS' => 'false',
                    'CI' => 'true',
                    'NO_COLOR' => '1',
                ])
                ->run([
                    $npx, '--yes', config('static-publish.wrangler'),
                    'deploy', '--config', "{$work}/wrangler.jsonc",
                ], fn ($type, $buffer) => $line($buffer));
        } finally {
            File::deleteDirectory($work);
        }

        if (! $result->successful()) {
            throw new RuntimeException('wrangler deploy fejlede: '.trim($result->errorOutput() ?: $result->output()));
        }

        $output = $result->output();

        preg_match('/https:\/\/[a-z0-9.-]+\.workers\.dev/i', $output, $url);
        preg_match('/Current Version ID:\s*([0-9a-f-]+)/i', $output, $version);

        return [
            'url' => $url[0] ?? null,
            'version_id' => $version[1] ?? null,
            'output' => $output,
        ];
    }
}
