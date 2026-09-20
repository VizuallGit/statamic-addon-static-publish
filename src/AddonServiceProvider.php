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

    public function bootAddon(): void
    {
        // Cache-bust on contents: the utility is one Vue component, and a
        // browser holding an old copy would call routes that have changed.
        $script = __DIR__.'/../resources/js/addon.js';

        $this->publishes([
            $script => public_path('vendor/static-publish/js/addon.js'),
        ], 'static-publish');

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
