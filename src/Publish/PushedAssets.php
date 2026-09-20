<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Support\Facades\Event;
use RuntimeException;
use Statamic\Events\ResponseCreated;
use Vizuall\StylePush\Http\Middleware\InjectAssets;
use Vizuall\StylePush\Tags\ScriptPush;
use Vizuall\StylePush\Tags\StylePush;

/**
 * `style_push` / `script_push` collect CSS and JS while a page renders, and
 * the Style Push addon's InjectAssets middleware writes them into the page's
 * placeholders on an HTTP request. statamic/ssg calls toResponse() directly,
 * so no middleware runs and the placeholders would stay in the copy, with all
 * section CSS gone. This does the middleware's work per generated page, in the
 * export process only: inject on Statamic's ResponseCreated event, then empty
 * the stacks so the next page starts clean.
 *
 * Style Push is detected, never required: a site without it has no
 * placeholders to fill.
 */
class PushedAssets
{
    public static function isInstalled(): bool
    {
        return class_exists(InjectAssets::class);
    }

    public static function listen(): void
    {
        if (! self::isInstalled()) {
            return;
        }

        if (! method_exists(StylePush::class, 'flush')) {
            throw new RuntimeException('Style Push skal være mindst 1.0.6 (flush) for at kunne eksporteres side for side.');
        }

        self::flush();

        Event::listen(ResponseCreated::class, function (ResponseCreated $event) {
            app(InjectAssets::class)->handle(request(), fn () => $event->response);
            self::flush();
        });
    }

    public static function flush(): void
    {
        StylePush::flush();
        ScriptPush::flush();
    }
}
