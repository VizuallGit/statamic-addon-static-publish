# Static Publish

Udgiv hele Statamic-sitet som statiske filer på Cloudflare Workers med én knap
i Control Panelet. Redaktørerne arbejder videre i CMS'et som altid; de
besøgende ser kun færdige filer fra Cloudflares net.

Formularer virker stadig. De sendes gennem Workeren tilbage til CMS'et, hvor
Statamics egen formular-håndtering gemmer indsendelsen og sender mails.

---

## Sådan hænger det sammen

```
Redaktør klikker Udgiv
  → static-publish:publish starter i baggrunden
  → statamic/ssg renderer hele sitet til filer
  → kontrol (én fejl stopper alt, live-sitet røres ikke)
  → wrangler deploy → Cloudflare

Besøgende sender en formular
  → POST /!/forms/{handle} på det statiske site
  → Workeren sender den videre med en hemmelig nøgle
  → POST /!/static-publish/forms/{handle} på CMS'et
  → Statamics FormController gemmer og sender mails
```

Alt andet end formularen rører aldrig Workerens kode. Sider, billeder,
stylesheets og fonte kommer direkte fra fillageret.

---

## Installation

```bash
composer require statamic-addon/static-publish
```

Addonet er rent additivt. Installationen ændrer intet på sitet, i Control
Panelet, i Live Preview eller i Visual Editor, og afinstallationen gør heller
ikke. Ingen middleware kører på almindelige requests, ingen af sitets
skabeloner, CSS eller config bliver rørt. Det, den statiske kopi har brug for,
laves i eksportprocessen og lander kun i kopien.

## Krav på serveren

| Krav | Hvorfor |
|---|---|
| Node 20 eller nyere | `wrangler` køres gennem `npx` |
| Netadgang til `api.cloudflare.com` og `registry.npmjs.org` | upload og hentning af wrangler |
| Laravel-scheduleren | kun til natlig udgivelse |

Webserverens PHP-proces har sjældent `node` på sin PATH. Sker det, fejler
knappen med `npx: not found`. Sæt den fulde sti i `.env`:

```dotenv
STATIC_PUBLISH_NPX="/home/forge/.nvm/versions/node/v22.22.0/bin/npx"
```

Deployeren lægger selv mappen forrest på PATH for den ene proces, så `node`
findes ved siden af.

## Nøgler i `.env`

```dotenv
CLOUDFLARE_API_TOKEN=...      # skabelonen "Edit Cloudflare Workers"
CLOUDFLARE_ACCOUNT_ID=...
```

Begge læses kun inde i udgivelses-kommandoen og sendes med som miljøvariabler
til den ene wrangler-proces. De skrives aldrig på disk og vises aldrig i
Control Panelet — kun som til stede eller ikke.

Formular-nøglen mellem Workeren og CMS'et udledes af sitets `APP_KEY` og skal
derfor ikke sættes. Se afsnittet om formularer for det ene tilfælde, hvor den
skal.

## Indstillinger

Under **Addons → Static Publish → Settings**, gemt i
`resources/addons/static-publish.yaml`:

| Indstilling | Betydning |
|---|---|
| Tilstand | `Server` (som i dag, ingen Udgiv-knap) eller `Statisk site` |
| Worker-navn | Workerens navn hos Cloudflare, fx `kunde-dk` |
| workers.dev-underdomæne | Kontoens underdomæne fra Cloudflare-dashboardet |
| Formularer | Om Workeren skal sende indsendelser tilbage til CMS'et |
| CMS-adresse | Hvor Workeren sender dem hen. Tom = sitets egen adresse |
| Tekst efter indsendelse | Vises, når formularen ikke har en takkeside |
| Udgiv automatisk om natten | Kl. 03, hvis en entrys dato er blevet aktuel |

Live-adressen bliver `https://{worker-navn}.{underdomæne}.workers.dev`. Begge
skal være sat før første udgivelse, for de absolutte adresser i de genererede
sider skrives med den.

---

## Formularer

Slå **Formularer** til, sæt **CMS-adresse** til den adresse CMS'et kan nås på
udefra, og udgiv. Så sker der tre ting:

1. Workeren får sin kode med og bliver spurgt ved `POST /!/forms/*`.
2. Et lille script lægges i kopien på de sider, der har en formular.
3. Nøglen, der er udledt af `APP_KEY`, sættes som Worker-secret hos Cloudflare.

Markup'en på sitet ændres ikke. Formularerne sender som de altid har gjort;
scriptet fanger blot indsendelsen, sender den med `fetch` og viser svaret,
fordi en statisk side ikke har en session at genindlæse ind i.

**Styling.** Scriptet skriver to klasser og ingen CSS:

| Klasse | Hvor |
|---|---|
| `sp-form-error` | et `<p>` med fejlbeskeden lige efter det felt, den hører til |
| `sp-form-message` | et `<p>` over formularen med kvitteringen eller alle fejl samlet |

Begge er neutrale. Hvordan de ser ud, hører til i sitets eget stylesheet.

**Nøglen.** Den udledes af `APP_KEY` med HMAC, så hvert site har sin egen,
den ligger allerede uden for git, og den kan ikke regnes tilbage til
`APP_KEY`. Det betyder, at nøglen hører til den installation, der udgiver.
Udgives kopien fra en anden maskine end det CMS, formularerne peger på, ville
de to sider regne sig frem til hver sin nøgle, og hver indsendelse ville blive
afvist. Kørslen stopper med den besked, før noget bliver sendt. De to udveje:

- udgiv fra det CMS, adressen peger på, eller
- sæt den samme `STATIC_PUBLISH_FORM_SECRET` i `.env` begge steder.

**Sikkerhed.** Ruten `/!/static-publish/forms/{handle}` er den eneste vej fra
det statiske site ind til serveren. CSRF er slået fra på den, fordi tokenet i
den statiske HTML blev lavet, da kopien blev genereret, og ikke hører til
nogen session. I stedet skal hver request bære den hemmelige nøgle, som
sammenlignes i konstant tid. Uden den svares 403, og er der slet ingen nøgle
på installationen, 503. Statamics egen begrænsning på ti indsendelser i
minuttet gælder stadig, men tælles på den besøgendes egen adresse, som
Cloudflare oplyser — ellers ville alle besøgende tælle som én.

Workeren bygger sin request til CMS'et fra bunden. Den besøgendes cookies når
aldrig frem til CMS'et, og CMS'ets session-cookie når aldrig tilbage til den
besøgende.

---

## Udgiv

Knappen ligger på **Utilities → Static Publish** og kræver rettigheden
`access static-publish utility`, som kun super-brugere har som standard.

Forløbet vises mens det kører: Genererer → Tjekker → Uploader → Live. To klik
starter ikke to kørsler. Dør processen undervejs, låser den ikke knappen.

Fra terminalen:

```bash
php please static-publish:publish             # hele turen
php please static-publish:publish --no-deploy # generér og kontrollér, send ikke
php please static-publish:publish --if-due    # kun hvis en dato er blevet aktuel
php please static-publish:rollback <version>  # sæt en tidligere udgave live
```

## Kontrollen

Én fejl stopper udgivelsen, og live-sitet røres ikke:

- generatoren sluttede uden fejl
- hver forventet side har en fil, og `404.html` findes
- ingen side har `noindex`
- dev-domænet står ingen steder i kopiens tekstfiler
- intet spor af Visual Editor (`data-sid`, `vendor/visual-editor`)
- ingen tom `style_push`/`script_push`-pladsholder
- en fil, der findes på PHP-sitet men mangler i kopien
- har en side en formular, mens Formularer er slået til, skal scriptet være der
- filantal og filstørrelser inden for Cloudflares grænser

Advarsler stopper ikke noget: links til sider, der er udeladt med vilje,
henvisninger der også er brudt på PHP-sitet, for store filer der er sprunget
over, og listen over eksterne hosts (fx Adobe Fonts).

## Rul tilbage

Cloudflare gemmer filerne for hver udgivelse. I historikken har hver tidligere
udgave en **Rul tilbage**-knap, der sætter præcis de bytes live igen på nogle
sekunder. Der genereres ikke noget, og der uploades ikke noget. Kun versioner,
dette site selv har udgivet, kan vælges.

## Natlig udgivelse

Slås **Udgiv automatisk om natten** til, kører `--if-due` kl. 03. Den udgiver
kun, hvis en entry med en dato frem i tiden er blevet aktuel siden sidste
udgivelse — altså blev til en side, uden at nogen rørte noget. Almindelige
rettelser udgives med knappen, af den der lavede dem.

Kræver Laravel-scheduleren på serveren (`schedule:run` hvert minut).

## Hvad der ikke kommer med

- Sider renderet med en redaktør-skabelon (`editor_views` i config, som
  standard `skabelon_*`). De er værktøjer i CMS'et, ikke sider på sitet.
- Filer over Cloudflares grænse på 25 MiB. De nævnes i advarslerne.
- Alt i `public/` som ikke står i `public_paths` (`build`, `fonts`,
  `favicon.ico`). Asset-containere kopieres altid.

### Sider sitet laver med PHP

Det statiske site kører ingen PHP, så en sitemap, en robots.txt eller et
redirect-kort bygget af en rute ville slet ikke findes derude. `generated_pages`
i config siger hvilke adresser der skal hentes fra sitet, når siderne er
skrevet, og gemmes som filer under samme navn — som standard:

| Fil | Hvad |
|---|---|
| `sitemap.xml` | Sitets sitemap, som sitet bygger den |
| `robots.txt` | Peger på sitemappen, med sitets eget domæne |
| `_redirects` | Cloudflares eget redirect-format. Omdirigeringerne sker på kanten, uden at noget kører |

Sitet ejer hvad de siger; listen her siger kun at de skal med. Svarer en adresse
ikke 200, springes den over — en manglende sitemap henter søgemaskinerne igen
senere, mens en sitemap der *er* en fejlside lærer dem at lade være.

Mens de hentes, kører appen som `production`, ligesom cascaden gør under
renderingen: ellers kunne en robots.txt bygget på en server i `local` komme til
at sige `Disallow: /` på det udgivne site.

## Filer og logs

Alt under `storage/app/static-publish/` og dermed uden for git:

| Sti | Indhold |
|---|---|
| `build/` | den seneste kopi |
| `runs/*.json` | én fil pr. kørsel: status, forløb, kontrollens svar |
| `console.log` | det kommandoen skrev |
| `worker-secret.json` | et aftryk af den nøgle, Workeren sidst fik — aldrig nøglen selv |
