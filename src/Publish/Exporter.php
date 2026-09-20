<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Cascade;
use Statamic\Facades\Entry;
use Statamic\StaticSite\Generator;
use Statamic\StaticSite\Tasks;
use Symfony\Component\Finder\Finder;

/**
 * Runs statamic/ssg in this process with this run's config, then copies the
 * asset originals next to it. Everything that differs from the live PHP site
 * is decided here, at export time, never in the site's templates or config:
 *
 *  - base_url is the live URL, so absolute links point at Cloudflare;
 *  - `environment` is forced to `production` through Cascade::hydrated, so
 *    the layout's noindex tag is not written into the copy;
 *  - CSS/JS pushed with style_push / script_push is written into each page
 *    (PushedAssets), which the Style Push middleware would do on a request;
 *  - editor pages (entries whose template or layout matches one of the
 *    configured `editor_views` patterns, `skabelon_*` by default) are excluded;
 *  - files larger than Cloudflare's per-file limit are left out and reported.
 */
class Exporter
{
    public function __construct(
        protected string $liveUrl,
        protected string $destination,
        protected int $maxFileBytes,
    ) {}

    /**
     * @return array{excluded: list<string>, skipped: list<array{path: string, bytes: int}>}
     *
     * @throws \Statamic\StaticSite\GenerationFailedException when a page fails (failures = errors)
     */
    public function run(): array
    {
        $excluded = $this->excludedUrls();

        config(['statamic.ssg' => array_merge(config('statamic.ssg', []), [
            'base_url' => $this->liveUrl,
            'destination' => $this->destination,
            'copy' => [],
            'symlinks' => [],
            'exclude' => $excluded,
            'failures' => 'errors',
        ])]);

        // Runs after the cascade has set `environment` from the app, so the
        // copy renders as production (no noindex) while the app stays as it is.
        Cascade::hydrated(fn ($cascade) => $cascade->set('environment', 'production'));

        PushedAssets::listen();

        // The Generator singleton read its config when the console booted.
        // Build one from this run's config instead of the boot-time copy.
        $generator = new Generator(app(), app(Filesystem::class), app(Router::class), app(Tasks::class));
        $generator->generate();

        return [
            'excluded' => $excluded,
            'skipped' => array_merge($this->copyPublicPaths(), $this->copyAssets()),
        ];
    }

    /** Relative URLs of the published entries the static site must contain. */
    public function expectedUrls(): array
    {
        $excluded = $this->excludedUrls();

        return Entry::all()
            ->filter(fn ($entry) => $entry->published() && $entry->uri() !== null)
            ->map(fn ($entry) => $entry->url())
            ->reject(fn ($url) => in_array($url, $excluded, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Entries rendered with an editor view are not pages of the site: the
     * section galleries and the showcase layout that always writes noindex.
     * Which views are editor views is one configured list of name patterns,
     * matched against the entry's template and layout.
     */
    public function excludedUrls(): array
    {
        $patterns = (array) config('static-publish.editor_views', []);

        return Entry::all()
            ->filter(fn ($entry) => $entry->uri() !== null)
            ->filter(fn ($entry) => Str::is($patterns, (string) $entry->template()) || Str::is($patterns, (string) $entry->layout()))
            ->map(fn ($entry) => $entry->url())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Copies the files and folders named in `public_paths` into the copy at
     * the same path, because pages link to them by absolute URL: the Vite
     * build (/build/…) and the uploaded fonts (/fonts/… in the @font-face
     * rules the theme pushes). statamic/ssg's own `copy` is left empty so
     * one method decides what comes along.
     *
     * @return list<array{path: string, bytes: int}>
     */
    protected function copyPublicPaths(): array
    {
        $skipped = [];

        foreach ((array) config('static-publish.public_paths', []) as $path) {
            $path = trim((string) $path, '/');
            $source = public_path($path);

            if ($path === '' || ! file_exists($source)) {
                continue;
            }

            if (is_file($source)) {
                $this->copyFile($source, "{$this->destination}/{$path}", "/{$path}", null, $skipped);

                continue;
            }

            foreach ($this->filesIn($source) as $file) {
                $relative = $file->getRelativePathname();

                $this->copyFile(
                    $file->getRealPath(),
                    "{$this->destination}/{$path}/{$relative}",
                    "/{$path}/{$relative}",
                    $file->getSize(),
                    $skipped,
                );
            }
        }

        return $skipped;
    }

    /**
     * Copies every public asset container into the copy under its URL, so
     * originals linked directly (video, svg, downloads) resolve. Hidden files
     * and folders (.meta, .DS_Store) are metadata, not site content.
     *
     * @return list<array{path: string, bytes: int}>
     */
    protected function copyAssets(): array
    {
        $skipped = [];

        foreach (AssetContainer::all() as $container) {
            $root = rtrim((string) $container->diskPath(), '/');
            $url = trim((string) parse_url((string) $container->url(), PHP_URL_PATH), '/');

            if ($url === '' || ! is_dir($root)) {
                continue;
            }

            foreach ($this->filesIn($root) as $file) {
                $relative = $file->getRelativePathname();

                $this->copyFile(
                    $file->getRealPath(),
                    "{$this->destination}/{$url}/{$relative}",
                    "/{$url}/{$relative}",
                    $file->getSize(),
                    $skipped,
                );
            }
        }

        return $skipped;
    }

    protected function filesIn(string $root): Finder
    {
        return Finder::create()->files()->in($root)->ignoreDotFiles(true)->ignoreVCS(true);
    }

    /**
     * Copies one file into the copy, unless it is over Cloudflare's per-file
     * limit; those are left out and reported by the caller.
     */
    protected function copyFile(string $source, string $target, string $url, ?int $size, array &$skipped): void
    {
        $size ??= (int) filesize($source);

        if ($size > $this->maxFileBytes) {
            $skipped[] = ['path' => $url, 'bytes' => $size];

            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy($source, $target);
    }
}
