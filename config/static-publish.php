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

    // Views that make an entry an editor page rather than a page of the site:
    // the section galleries (get:preview_section) and the showcase layout
    // that always writes noindex. Matched against each entry's template and
    // layout name; `*` is a wildcard. Such entries are left out of the copy.
    'editor_views' => ['skabelon_*'],

    // How many runs the utility page lists.
    'history' => 5,
];
