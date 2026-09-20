<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\File;

/**
 * One JSON file per run under storage/app/static-publish/runs/. The file is
 * the only channel between the command (writer) and the Control Panel
 * (reader): status, step, timings, the verifier's report, the live URL.
 *
 * Writes are atomic (write to a temp file, then rename) so a poll from the
 * Control Panel never reads half a run.
 */
class RunLog
{
    public const RUNNING = 'running';

    public const LIVE = 'live';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    public const STEPS = ['generating', 'verifying', 'deploying', 'done'];

    protected string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? config('static-publish.runs');
    }

    public function start(?string $user, bool $deploy): array
    {
        File::ensureDirectoryExists($this->dir);

        $run = [
            'id' => now()->format('Ymd-His').'-'.bin2hex(random_bytes(2)),
            'status' => self::RUNNING,
            'step' => 'generating',
            'deploy' => $deploy,
            'user' => $user,
            'pid' => getmypid(),
            'started_at' => now()->toIso8601String(),
            'started_ts' => microtime(true),
            'finished_at' => null,
            'duration' => null,
            'url' => null,
            'version_id' => null,
            'report' => null,
            'error' => null,
        ];

        $this->write($run);

        return $run;
    }

    public function update(array $run, array $changes): array
    {
        $run = array_merge($run, $changes);

        $this->write($run);

        return $run;
    }

    public function step(array $run, string $step): array
    {
        return $this->update($run, ['step' => $step]);
    }

    public function finish(array $run, string $status, array $changes = []): array
    {
        return $this->update($run, $changes + [
            'status' => $status,
            'step' => 'done',
            'finished_at' => now()->toIso8601String(),
            'duration' => (int) round(microtime(true) - (float) $run['started_ts']),
        ]);
    }

    public function fail(array $run, string $error): array
    {
        return $this->finish($run, self::FAILED, ['error' => $error]);
    }

    public function find(string $id): ?array
    {
        $path = "{$this->dir}/{$id}.json";

        return is_file($path) ? json_decode(File::get($path), true) : null;
    }

    /** Newest first. */
    public function recent(int $count): array
    {
        if (! is_dir($this->dir)) {
            return [];
        }

        $files = collect(File::files($this->dir))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->sortByDesc(fn ($file) => $file->getFilename())
            ->take($count);

        return $files
            ->map(fn ($file) => json_decode(File::get($file->getPathname()), true))
            ->filter()
            ->values()
            ->all();
    }

    public function latest(): ?array
    {
        return $this->recent(1)[0] ?? null;
    }

    /**
     * The run in progress, if any. A run whose process is gone (killed, server
     * restarted) is marked failed here, so a dead run never blocks the button.
     */
    public function running(): ?array
    {
        $run = $this->latest();

        if (! $run || $run['status'] !== self::RUNNING) {
            return null;
        }

        if (! $this->processAlive((int) ($run['pid'] ?? 0))) {
            $this->fail($run, 'Processen stoppede uden at melde tilbage. Se storage/app/static-publish/console.log.');

            return null;
        }

        return $run;
    }

    protected function processAlive(int $pid): bool
    {
        // Signal 0 tests for existence without sending anything. EPERM
        // (another user's process) still means it exists.
        return $pid > 0 && (posix_kill($pid, 0) || posix_get_last_error() === 1);
    }

    protected function write(array $run): void
    {
        File::ensureDirectoryExists($this->dir);

        $path = "{$this->dir}/{$run['id']}.json";
        $tmp = "{$path}.tmp";

        File::put($tmp, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }
}
