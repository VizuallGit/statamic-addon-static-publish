<?php

use Illuminate\Support\Facades\Route;
use Statamic\Http\Controllers\FormController;
use Vizuall\StaticPublish\Http\Middleware\VerifyStaticFormRequest;

/*
 * POST /!/static-publish/forms/{form}
 *
 * The static site's only way back to this server. The Worker forwards a
 * submission here and Statamic's own FormController answers it, so
 * validation, the honeypot, stored submissions, events and mails are
 * Statamic's own — this addon adds a door, not a second form engine.
 *
 * CSRF is off on this route by design, and VerifyStaticFormRequest takes its
 * place. See that class for why, and for what it checks before the request
 * is allowed any further.
 *
 * The route is registered whether or not forms are switched on. With no
 * secret it answers 503 and with a wrong one 403, so a site that never turns
 * forms on has a door that opens for nobody.
 */
Route::post('forms/{form}', [FormController::class, 'submit'])
    ->middleware([VerifyStaticFormRequest::class])
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('static-publish.forms.submit');
