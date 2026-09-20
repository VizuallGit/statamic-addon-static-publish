<?php

namespace Vizuall\StaticPublish;

use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider as BaseAddonServiceProvider;
use Statamic\Statamic;
use Vizuall\StaticPublish\Console\PublishCommand;
use Vizuall\StaticPublish\Http\Controllers\UtilityController;

/**
 * Static Publish: one button in the Control Panel that renders the whole site
 * to static files (statamic/ssg) and puts them on Cloudflare Workers.
 *
 * Additive by design. Nothing in this provider runs on an ordinary request:
 * no middleware, no Cascade callbacks, no config overrides. Every export-time
 * hook lives inside the publish command, in its own process. This class only
 * registers the command, the utility page and the utility's own routes.
 */
class AddonServiceProvider extends BaseAddonServiceProvider
{
    protected $viewNamespace = 'static-publish';

    protected $config = true;

    protected $commands = [
        PublishCommand::class,
    ];

    /**
     * Declared here, not published by hand in bootAddon(): Statamic only
     * registers its publish-after-install step for an addon that names its
     * assets in `$scripts`, `$stylesheets`, `$vite` or `$publishables`. A
     * manual `publishes()` call is invisible to it, so `composer install` on
     * the server (post-autoload-dump → statamic:install) never copied the
     * script to public/vendor, and the utility page came up empty there
     * while it worked locally, where the file had been published by hand.
     */
    protected $publishables = [
        __DIR__.'/../resources/js/addon.js' => 'js/addon.js',
    ];

    public function bootAddon(): void
    {
        // Cache-bust on contents: the utility is one Vue component, and a
        // browser holding an old copy would call routes that have changed.
        $script = __DIR__.'/../resources/js/addon.js';

        Statamic::script('static-publish', 'addon.js?v='.md5_file($script));

        Utility::register('static-publish')
            ->title('Static Publish')
            ->description('Udgiv hele sitet som statiske filer på Cloudflare.')
            ->icon('upload-cloud')
            ->view('static-publish::utilities.static-publish')
            ->routes(function ($router) {
                $router->get('state', [UtilityController::class, 'state']);
                $router->post('mode', [UtilityController::class, 'mode']);
                $router->post('publish', [UtilityController::class, 'publish']);
            });
    }
}
