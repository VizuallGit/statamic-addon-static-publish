<?php

namespace Vizuall\StaticPublish\Publish;

use Illuminate\Http\Request;
use Vizuall\StaticPublish\Settings;

/**
 * The contract between the Cloudflare Worker and this site, in one place.
 *
 * Four parties speak it: the Worker (forwards the submission), the guard
 * middleware (lets it in), the transformer (puts the browser script in the
 * copy) and the verifier (checks the copy). Spelling the header names or the
 * script path in four files is how they drift apart, so they are spelled
 * once, here.
 */
class Forms
{
    /** The shared secret travels in this header, Worker → site. */
    public const SECRET_HEADER = 'X-Static-Publish-Secret';

    /** The visitor's own address, which Cloudflare knows and the site cannot see. */
    public const IP_HEADER = 'X-Static-Publish-Ip';

    /** Where the guard puts that address once the secret has proved it. */
    public const IP_ATTRIBUTE = 'static-publish-ip';

    /** The browser script, written into the copy and nowhere else. */
    public const SCRIPT_PATH = '_static-publish/forms.js';

    /** The Worker's variable names for the two values it is deployed with. */
    public const ORIGIN_VAR = 'CMS_ORIGIN';

    public const SECRET_VAR = 'FORM_SECRET';

    public const RATE_LIMITER = 'static-publish.forms';

    /** A form's action on the static site, which is also the Worker's route. */
    public const ACTION_PREFIX = '/!/forms/';

    public static function enabled(): bool
    {
        return Settings::formsEnabled();
    }

    /**
     * The secret the Worker proves itself with.
     *
     * Derived from this installation's APP_KEY, so a site needs no second
     * secret in .env: APP_KEY is already unique per site and already outside
     * git, and an HMAC cannot be turned back into it. The derived value only
     * ever leaves here as a Cloudflare Worker secret. Set
     * STATIC_PUBLISH_FORM_SECRET when two installations must share one
     * secret — see problem() for the case where that matters.
     */
    public static function secret(): string
    {
        if (($explicit = trim((string) config('static-publish.form_secret'))) !== '') {
            return $explicit;
        }

        $key = (string) config('app.key');

        return $key === '' ? '' : hash_hmac('sha256', 'static-publish:forms:v1', $key);
    }

    /** The site the Worker forwards submissions to. */
    public static function cmsOrigin(): string
    {
        return rtrim(Settings::cmsOrigin() ?: (string) config('app.url'), '/');
    }

    public static function successText(): string
    {
        return Settings::formSuccessText() ?: 'Tak for din besked. Vi vender tilbage hurtigst muligt.';
    }

    /**
     * Why forms cannot work as configured, in one sentence the editor can
     * act on, or null when they can. Checked before a run starts, so a copy
     * is never published with a Worker that would answer 403.
     */
    public static function problem(): ?string
    {
        if (self::secret() === '') {
            return 'Der er ingen nøgle mellem Workeren og sitet, fordi APP_KEY er tom. Kør php artisan key:generate, eller sæt STATIC_PUBLISH_FORM_SECRET i .env.';
        }

        $origin = self::cmsOrigin();

        if (! preg_match('#^https?://[^/]+$#i', $origin)) {
            return "CMS-adressen skal være en fuld adresse uden sti, fx https://cms.kunde.dk. Den er nu: {$origin}";
        }

        // The derived secret belongs to this installation. Publishing from a
        // laptop while pointing the Worker at the production CMS would mean
        // the two sides derive different secrets from different APP_KEYs, and
        // every submission would be rejected. An explicit shared secret is
        // the way to do that on purpose, so only the derived case is stopped.
        if (trim((string) config('static-publish.form_secret')) !== '') {
            return null;
        }

        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($originHost !== '' && $appHost !== '' && $originHost !== $appHost) {
            return "Formularerne sendes til {$originHost}, men de udgives fra {$appHost}. Nøglen udledes af APP_KEY, og de to installationer har hver sin, så {$originHost} ville afvise hver indsendelse. Udgiv fra {$originHost}, eller sæt den samme STATIC_PUBLISH_FORM_SECRET i .env begge steder.";
        }

        return null;
    }

    /**
     * The visitor's address, as the Worker reported it. Only ever called
     * after the guard has checked the secret: without that, anyone could
     * name their own address and step past the rate limit.
     */
    public static function visitorIp(Request $request): string
    {
        $forwarded = trim((string) $request->header(self::IP_HEADER));

        return filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : (string) $request->ip();
    }
}
