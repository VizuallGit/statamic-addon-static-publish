<?php

namespace Vizuall\StaticPublish\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Facades\Form;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vizuall\StaticPublish\Publish\Forms;

/**
 * The gate in front of the one route the static site may reach on this
 * server. Three jobs, in an order that cannot be rearranged, because each
 * one is only safe once the one before it has run.
 *
 * 1. Prove the request came from our Worker. The route has no CSRF token to
 *    check — the token in the static HTML was minted when the copy was
 *    generated and belongs to no session — so this shared secret is the only
 *    thing standing between the open internet and Statamic's form handler.
 *    It is compared in constant time, and nothing else runs before it.
 *
 * 2. Put the Form object on the route. Statamic's FrontendFormRequest reads
 *    `$this->route()->parameter('form')` and calls ->blueprint() on it, but
 *    Statamic only binds {form} on its own !/forms/ routes. Turning on
 *    statamic.routes.bindings would bind it everywhere and change how the
 *    whole site routes, so the binding is done here, for this route alone.
 *
 * 3. Count the request against the visitor's own address, which the Worker
 *    reports because every request to this route arrives from Cloudflare and
 *    the server would otherwise see one address for the whole world. The
 *    header is only believed here, after step 1 proved who sent it.
 *
 *    The counting is done here rather than with a throttle middleware on the
 *    route, and that is not a matter of taste: Laravel sorts ThrottleRequests
 *    ahead of an addon's own middleware, so a throttle would have counted
 *    before this class had checked the secret, read an address nobody had
 *    proved, and shared one bucket between every visitor. In one class the
 *    order is the order of the lines.
 *
 * 4. Put the Form object on the route, once the request has earned it.
 */
class VerifyStaticFormRequest
{
    public function handle(Request $request, Closure $next)
    {
        if (($secret = Forms::secret()) === '') {
            return response()->json([
                'error' => 'Formularen er ikke sat op på dette site.',
            ], 503);
        }

        if (! hash_equals($secret, (string) $request->header(Forms::SECRET_HEADER))) {
            return response()->json(['error' => 'Afvist.'], 403);
        }

        $ip = Forms::visitorIp($request);
        $key = Forms::RATE_LIMITER.':'.$ip;
        $limit = (int) config('static-publish.form_rate_limit', 10);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'error' => 'Der er sendt for mange beskeder herfra. Prøv igen om lidt.',
            ], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);

        $request->attributes->set(Forms::IP_ATTRIBUTE, $ip);

        $route = $request->route();

        if (is_string($handle = $route->parameter('form'))) {
            if (! $form = Form::find($handle)) {
                throw new NotFoundHttpException("Form [{$handle}] not found.");
            }

            $route->setParameter('form', $form);
        }

        return $next($request);
    }
}
