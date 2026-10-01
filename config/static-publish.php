<?php

/*
 * Technical defaults for Static Publish. Nothing here is a secret and nothing
 * here changes how the PHP site behaves; every value is read inside the
 * publish command or the utility page only.
 */
return [

    // Where the static copy is written. Everything under storage/app/ is
    // ignored by git, so neither the copy nor the run log is ever committed.
    'destination' => storage_path('app/static-publish/build'),
    'runs' => storage_path('app/static-publish/runs'),

    // The two secrets. Only ever read from the environment (or the cached
    // config built from it), never from YAML or the Control Panel.
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    ],

    // The secret the Worker proves itself with when it forwards a form
    // submission. Left empty it is derived from APP_KEY, which is already a
    // per-site secret outside git; see Publish\Forms::secret(). Set this only
    // when two installations must share one secret, for instance when the
    // copy is published from somewhere other than the CMS it points at.
    'form_secret' => env('STATIC_PUBLISH_FORM_SECRET'),

    // Submissions a single visitor may send per minute from the static site,
    // matching Statamic's own limit on its form route. Counted in
    // Http\Middleware\VerifyStaticFormRequest, on the address the Worker
    // reports rather than Cloudflare's own.
    'form_rate_limit' => 10,

    // wrangler is run through npx with a pinned major, so the site's
    // package.json is not touched. Override the binaries when the web
    // server's PATH does not know them.
    'npx' => env('STATIC_PUBLISH_NPX', 'npx'),
    'wrangler' => 'wrangler@4',
    'php' => env('STATIC_PUBLISH_PHP'),

    // Written into the generated wrangler.jsonc. Bump deliberately.
    'compatibility_date' => '2026-09-20',

    // Cloudflare's limits for static assets (Workers Free and Paid alike):
    // developers.cloudflare.com/workers/platform/limits/#static-assets
    'limits' => [
        'files' => 20000,
        'file_bytes' => 25 * 1024 * 1024,
    ],

    // Files and folders under public/ that pages link to by absolute URL and
    // that the copy therefore needs: the Vite build and the uploaded fonts
    // (/fonts/… in the theme's @font-face rules), plus the files a browser or
    // a crawler asks for by name. Asset containers are copied automatically,
    // and nothing else in public/ belongs on the static site. A file that is
    // referenced but missing stops the run, so this list stays honest.
    'public_paths' => ['build', 'fonts', 'favicon.ico'],

    // Paths the site answers with PHP that the copy must hold as files.
    // The static site runs no PHP, so a sitemap, a robots.txt or a redirect
    // map built by a route would simply not exist out there. Each is fetched
    // from this site once the pages are written and saved under the same
    // name. The site owns what they say; this list only says to bring them.
    //
    // `_redirects` is Cloudflare's own redirect file: it answers them from
    // the edge, with no code running at all.
    'generated_pages' => ['sitemap.xml', 'robots.txt', '_redirects'],

    // Views that make an entry an editor page rather than a page of the site:
    // the section galleries (get:preview_section) and the showcase layout
    // that always writes noindex. Matched against each entry's template and
    // layout name; `*` is a wildcard. Such entries are left out of the copy.
    'editor_views' => ['skabelon_*'],

    // How many runs the utility page lists, each with its own roll-back
    // button while Cloudflare still holds that version.
    'history' => 10,

    // Publishes at this time of night when the nightly setting is on, so an
    // entry dated for tomorrow morning is live before anyone reads it.
    'nightly_at' => '03:00',
];
