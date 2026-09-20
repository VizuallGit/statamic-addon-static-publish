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
