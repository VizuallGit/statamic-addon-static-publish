# Static Publish

Én knap i Statamics Control Panel, der udgiver hele sitet som statiske filer på Cloudflare Workers. Redaktørerne arbejder videre som i dag på CMS-serveren i Live Preview; de besøgende ser kun de statiske filer.

Generatoren er Statamics egen `statamic/ssg`. Upload sker med `wrangler deploy` via `npx`, så sitets `package.json` røres ikke.

## Installation

```
composer require statamic-addon/static-publish
```

`.env` på CMS-serveren:

```
CLOUDFLARE_API_TOKEN=   # API-token med skabelonen "Edit Cloudflare Workers"
CLOUDFLARE_ACCOUNT_ID=  # kontoens ID (32 tegn)
```

Serveren skal have Node 20+ (`npx`) og kunne nå `api.cloudflare.com` og `registry.npmjs.org`.

## Indstillinger

Under Tools → Utilities → Static Publish (og Addons → Static Publish → Settings). Gemmes i `resources/addons/static-publish.yaml`; ingen hemmeligheder.

| Nøgle | Betydning |
|---|---|
| `mode` | `server` (standard): sitet kører som i dag, ingen Udgiv-knap. `static`: Udgiv-knap, status og historik. Kontakten ændrer ikke andet. |
| `worker_name` | Workerens navn hos Cloudflare, fx `vizuall-demo`. |
| `workers_subdomain` | Kontoens `workers.dev`-underdomæne. Live-adressen bliver `https://{worker_name}.{workers_subdomain}.workers.dev`. |

## Sådan virker en udgivelse

`php please static-publish:publish [--no-deploy]` er den eneste vej. Knappen i CP'et starter samme kommando i baggrunden.

1. **Lås og run-log.** `Cache::lock('static-publish')` sikrer én kørsel ad gangen. Hver kørsel skriver `storage/app/static-publish/runs/{id}.json`, som CP-siden læser hvert andet sekund.
2. **Generering.** ssg kører i processen med `base_url` = live-adressen, `destination` = `storage/app/static-publish/build`, `failures = errors`. `environment` sættes til `production` via `Cascade::hydrated`, så layoutets noindex ikke skrives i kopien; skabelonen røres ikke. Entries hvis skabelon eller layout matcher `editor_views` i config (`skabelon_*` som standard: sektionsgallerierne og showcase-layoutet, der altid skriver noindex) er redaktørsider og udelades; de vises i rapporten. Asset-containere kopieres med; filer over 25 MiB udelades og meldes.
3. **Kontrol.** Alle forventede sider findes, `404.html` findes, ingen noindex, dev-adressen findes ingen steder, intet spor af Visual Editor, Cloudflares grænser (20.000 filer, 25 MiB pr. fil) overholdes. Én fejl stopper kørslen; live-sitet røres ikke. Formularer giver en advarsel (de virker først i trin 2). Eksterne hosts (fx Adobe Fonts) vises til orientering.
4. **Upload.** `npx --yes wrangler@4 deploy` med en genereret `wrangler.jsonc` (assets-only Worker: `assets.directory`, `not_found_handling = 404-page`, `html_handling = drop-trailing-slash`, så `/om-os` svarer direkte og `/om-os/` sendes til `/om-os` som i Statamic, ingen `main`) i en midlertidig mappe, der slettes bagefter. Token og account ID gives som miljøvariabler til den ene proces.

## Rent additivt

Intet i addonet kører på almindelige requests: ingen middleware, ingen Cascade-callbacks, ingen config-overskrivninger, ingen ændringer i skabeloner, CSS eller JS. Alt, der adskiller kopien fra PHP-sitet, sker inde i kommandoen. Output og logs ligger under `storage/app/static-publish/`, som git ignorerer.

## Begrænsninger i trin 1

- Formularer virker ikke på det statiske site (kommer i trin 2 med en Worker-proxy til Statamics `FormController`).
- Ingen tilbagerulning fra CP'et endnu (`wrangler rollback` kommer i trin 2).
- Ét site; testes på `*.workers.dev`. Kundens eget domæne kræver DNS hos Cloudflare (husk MX-records).
