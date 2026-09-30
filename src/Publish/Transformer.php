<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * The one step that changes the copy after it has been generated.
 *
 * A form on the PHP site posts and the page reloads; a static page has no
 * session to reload into, so it needs a little JavaScript. That script is
 * written into the copy here rather than into the site's own templates,
 * which is the whole point: install the addon and the PHP site renders
 * exactly the same bytes it did before.
 */
class Transformer
{
    public function __construct(
        protected string $dir,
        protected string $successText,
    ) {}

    /**
     * Copies the browser script in and adds one tag to every page that has a
     * form. The success text rides along on the tag, so the script itself
     * stays a plain file that is copied, not generated.
     *
     * @return int pages the tag was added to
     */
    public function run(): int
    {
        $target = "{$this->dir}/".Forms::SCRIPT_PATH;

        File::ensureDirectoryExists(dirname($target));
        File::copy(__DIR__.'/../../resources/js/forms.js', $target);

        $tag = '<script src="/'.Forms::SCRIPT_PATH.'" data-success="'
            .htmlspecialchars($this->successText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'" defer></script>';

        $count = 0;

        foreach ($this->pages() as $file) {
            $path = $file->getRealPath();
            $html = (string) file_get_contents($path);

            if (! self::hasForm($html) || str_contains($html, Forms::SCRIPT_PATH)) {
                continue;
            }

            // Last </body>, not the first: a page may mention the tag in text.
            if (($position = strripos($html, '</body>')) === false) {
                continue;
            }

            file_put_contents($path, substr_replace($html, $tag."\n", $position, 0));
            $count++;
        }

        return $count;
    }

    /** Whether a page posts to Statamic's form action, which is what the Worker answers. */
    public static function hasForm(string $html): bool
    {
        return (bool) preg_match(
            '/<form\b[^>]*action=["\'][^"\']*'.preg_quote(Forms::ACTION_PREFIX, '/').'/i',
            $html
        );
    }

    protected function pages(): Finder
    {
        return Finder::create()->files()->in($this->dir)->name('*.html')->name('*.htm');
    }
}
