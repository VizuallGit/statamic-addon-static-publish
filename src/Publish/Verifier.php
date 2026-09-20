<?php

namespace Vizuall\StaticPublish\Publish;

use Symfony\Component\Finder\Finder;

/**
 * Reads the finished copy and decides whether it may go live. One error stops
 * the run; the live site is not touched. Warnings and the list of external
 * hosts are shown in the Control Panel but do not stop anything.
 */
class Verifier
{
    protected const TEXT = ['html', 'htm', 'css', 'js', 'xml', 'json', 'txt', 'svg', 'webmanifest'];

    public function __construct(
        protected string $dir,
        protected ?string $devHost,
        protected ?string $liveHost,
        protected int $maxFiles,
        protected int $maxFileBytes,
        protected string $publicPath,
    ) {}

    /**
     * @param  list<string>  $expectedUrls  relative URLs that must have a file
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, external_hosts: list<string>, pages: int, files: int, bytes: int, forms: int}
     */
    public function verify(array $expectedUrls): array
    {
        $errors = [];
        $warnings = [];
        $hosts = [];
        $references = [];
        $forms = 0;
        $pages = 0;
        $files = 0;
        $bytes = 0;

        if (! is_dir($this->dir)) {
            return $this->report(['Der blev ikke genereret nogen kopi.'], $warnings, $hosts, 0, 0, 0, 0);
        }

        foreach ($expectedUrls as $url) {
            if (! is_file($file = $this->fileFor($url))) {
                $errors[] = "Siden {$url} mangler i kopien (".$this->relative($file).').';
            }
        }

        if (! is_file("{$this->dir}/404.html")) {
            $errors[] = '404.html mangler i roden af kopien.';
        }

        foreach (Finder::create()->files()->in($this->dir) as $file) {
            $files++;
            $size = $file->getSize();
            $bytes += $size;
            $relative = $file->getRelativePathname();

            if ($size > $this->maxFileBytes) {
                $errors[] = "{$relative} fylder ".$this->mib($size).' MiB. Cloudflares grænse er '.$this->mib($this->maxFileBytes).' MiB pr. fil.';
            }

            $ext = strtolower($file->getExtension());

            if (! in_array($ext, self::TEXT, true)) {
                continue;
            }

            $text = (string) file_get_contents($file->getRealPath());

            if ($this->devHost && stripos($text, $this->devHost) !== false) {
                $errors[] = "{$relative} indeholder dev-adressen {$this->devHost}.";
            }

            if ($ext === 'html' || $ext === 'htm' || $ext === 'css') {
                foreach ($this->references($text, $ext) as $reference) {
                    if ($path = $this->localPath($reference, dirname($relative))) {
                        $references[$path] = true;
                    }
                }
            }

            if ($ext !== 'html' && $ext !== 'htm') {
                continue;
            }

            $pages++;

            if (preg_match('/<meta\s+name=["\']robots["\'][^>]*noindex/i', $text)) {
                $errors[] = "{$relative} har noindex.";
            }

            if (str_contains($text, '__YIELD_STYLES__') || str_contains($text, '__YIELD_SCRIPTS__')) {
                $errors[] = "{$relative} har en tom style_push/script_push-pladsholder: sektionernes CSS/JS blev ikke sat ind.";
            }

            if (str_contains($text, 'data-sid=') || str_contains($text, 'vendor/visual-editor')) {
                $errors[] = "{$relative} indeholder spor af Visual Editor.";
            }

            if (preg_match('/<form\b[^>]*action=["\'][^"\']*\/!\/forms\//i', $text)) {
                $forms++;
            }

            foreach ($this->externalHosts($text) as $host) {
                $hosts[$host] = true;
            }
        }

        [$missingFiles, $missingPages, $brokenLinks] = $this->missing(array_keys($references));

        foreach (array_slice($missingFiles, 0, 10) as $path) {
            $errors[] = "{$path} findes på sitet, men kom ikke med i kopien.";
        }

        if (($more = count($missingFiles) - 10) > 0) {
            $errors[] = "… og {$more} filer mere kom ikke med i kopien.";
        }

        foreach (array_slice($brokenLinks, 0, 10) as $path) {
            $warnings[] = "{$path} bliver brugt på siderne, men filen findes ikke — heller ikke på PHP-sitet.";
        }

        if (($more = count($brokenLinks) - 10) > 0) {
            $warnings[] = "… og {$more} filer mere mangler også på PHP-sitet.";
        }

        foreach (array_slice($missingPages, 0, 10) as $path) {
            $warnings[] = "Der linkes til {$path}, som ikke er med i kopien.";
        }

        if (($more = count($missingPages) - 10) > 0) {
            $warnings[] = "… og {$more} links mere peger på sider, der ikke er med.";
        }

        if ($files > $this->maxFiles) {
            $errors[] = "Kopien har {$files} filer. Cloudflares grænse er {$this->maxFiles}.";
        }

        if ($forms > 0) {
            $warnings[] = "{$forms} ".($forms === 1 ? 'side har en formular' : 'sider har formularer').'. Formularer virker først i trin 2.';
        }

        return $this->report($errors, $warnings, $hosts, $pages, $files, $bytes, $forms);
    }

    protected function report(array $errors, array $warnings, array $hosts, int $pages, int $files, int $bytes, int $forms): array
    {
        ksort($hosts);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'external_hosts' => array_keys($hosts),
            'pages' => $pages,
            'files' => $files,
            'bytes' => $bytes,
            'forms' => $forms,
        ];
    }

    /** Where statamic/ssg writes a URL: {url}/index.html, or index.html for the root. */
    public function fileFor(string $url): string
    {
        $path = rtrim($url, '/');

        return $this->dir.($path === '' ? '' : $path).'/index.html';
    }

    /**
     * Sorts the referenced paths into three: files the copy lost, links to
     * pages that are not in the copy, and references that are broken on the
     * PHP site as well. Only the first stops the run, because only there does
     * the copy differ from the site. A link may point at a page left out on
     * purpose, and a reference that is already broken live is not made worse
     * by publishing; both are reported as warnings.
     *
     * @param  list<string>  $paths
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    protected function missing(array $paths): array
    {
        $files = [];
        $pages = [];
        $broken = [];

        sort($paths);

        foreach ($paths as $path) {
            $decoded = rawurldecode($path);

            if (preg_match('/\.[a-z0-9]{1,6}$/i', $path) && ! preg_match('/\.html?$/i', $path)) {
                if (is_file($this->dir.$decoded)) {
                    continue;
                }

                if (is_file(rtrim($this->publicPath, '/').$decoded)) {
                    $files[] = $path;
                } else {
                    $broken[] = $path;
                }

                continue;
            }

            if (! is_file($this->fileFor($decoded))) {
                $pages[] = $path;
            }
        }

        return [$files, $pages, $broken];
    }

    /** Every path a page or a stylesheet points at: src/href/srcset and url(). */
    protected function references(string $text, string $ext): array
    {
        $found = [];

        if ($ext !== 'css') {
            preg_match_all('/\b(?:src|href|poster)\s*=\s*["\']([^"\']+)["\']/i', $text, $matches);
            $found = $matches[1];

            preg_match_all('/\bsrcset\s*=\s*["\']([^"\']+)["\']/i', $text, $matches);

            foreach ($matches[1] as $set) {
                foreach (explode(',', $set) as $candidate) {
                    $found[] = (string) strtok(trim($candidate), ' ');
                }
            }
        }

        preg_match_all('/url\(\s*["\']?([^"\')]+)/i', $text, $matches);

        return array_merge($found, $matches[1]);
    }

    /**
     * The path inside the copy a reference points at, or null when it is not
     * ours: another host, a data URI, an anchor, or one of Statamic's action
     * routes, which the Worker answers rather than a file.
     */
    protected function localPath(string $reference, string $baseDir): ?string
    {
        $reference = trim($reference);

        if ($reference === '' || preg_match('/^(data:|mailto:|tel:|javascript:|#)/i', $reference)) {
            return null;
        }

        if (str_starts_with($reference, '//')) {
            $reference = 'https:'.$reference;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $reference)) {
            if (strtolower((string) parse_url($reference, PHP_URL_HOST)) !== strtolower((string) $this->liveHost)) {
                return null;
            }

            $reference = (string) parse_url($reference, PHP_URL_PATH);
        }

        $path = (string) strtok($reference, '?#');

        if ($path === '' || str_starts_with($path, '/!/')) {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.trim($baseDir === '.' ? '' : $baseDir, '/').'/'.$path;
        }

        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    /** Hosts in src/href other than the live site itself. */
    protected function externalHosts(string $html): array
    {
        preg_match_all('/\b(?:src|href)=["\']https?:\/\/([^\/"\'\s:?#]+)/i', $html, $matches);

        return array_values(array_unique(array_filter(
            array_map('strtolower', $matches[1]),
            fn ($host) => $host !== strtolower((string) $this->liveHost)
        )));
    }

    protected function relative(string $path): string
    {
        return ltrim(substr($path, strlen($this->dir)), '/');
    }

    protected function mib(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.');
    }
}
