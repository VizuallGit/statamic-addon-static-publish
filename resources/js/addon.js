/**
 * Static Publish — the Control Panel utility.
 *
 * Plain JS, no build step: the file is published as it is to
 * public/vendor/static-publish/js/ and loaded by Statamic. One Vue component
 * built from Statamic's own UI kit (window.__STATAMIC__.ui), so it follows
 * the Control Panel's light and dark themes.
 *
 * One thing at a time. The switch (Server / Statisk site) is always there.
 * On Server there is one line explaining what Statisk site means. On Statisk
 * site: the Udgiv button, the run in progress (polled every two seconds),
 * the latest result and the last runs.
 *
 * Routes are resolved relative to the utility's own URL.
 */
(function () {
    'use strict';

    const ui = (window.__STATAMIC__ && window.__STATAMIC__.ui) || {};

    const STEPS = [
        { key: 'generating', text: 'Genererer' },
        { key: 'verifying', text: 'Tjekker' },
        { key: 'deploying', text: 'Uploader' },
        { key: 'done', text: 'Live' },
    ];

    const STATUS = {
        running: { text: 'Kører', color: 'blue' },
        live: { text: 'Live', color: 'green' },
        verified: { text: 'Kontrolleret, ikke sendt', color: 'default' },
        failed: { text: 'Fejlede', color: 'red' },
    };

    const STYLE = `
.sp{--sp-line:color-mix(in srgb,currentColor 12%,transparent);--sp-fill:color-mix(in srgb,currentColor 4%,transparent);--sp-accent:var(--color-primary,#4f46e5);--sp-accent-soft:color-mix(in srgb,var(--color-primary,#4f46e5) 14%,transparent);display:grid;gap:1.25rem;max-width:56rem}
.sp-switch{display:inline-flex;border:1px solid var(--sp-line);border-radius:.6rem;padding:.2rem;gap:.2rem;background:var(--sp-fill)}
.sp-switch button{border:0;background:transparent;color:inherit;font:inherit;font-size:.875rem;line-height:1.25rem;padding:.45em 1em;border-radius:.45rem;cursor:pointer}
.sp-switch button[aria-pressed="true"]{background:var(--sp-accent);color:#fff}
.sp-switch button:disabled{cursor:default;opacity:.6}
.sp-row{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.sp-hint{font-size:.875rem;line-height:1.4;opacity:.8;max-width:40rem}
.sp-box{background:var(--sp-fill);border:1px solid var(--sp-line);border-radius:.75rem;padding:.9rem 1rem;display:grid;gap:.75rem}
.sp-steps{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.sp-step{display:inline-flex;align-items:center;gap:.4em;font-size:.8125rem;padding:.3em .8em;border-radius:999px;border:1px solid var(--sp-line);opacity:.55}
.sp-step.is-on{opacity:1;border-color:var(--sp-accent);background:var(--sp-accent-soft)}
.sp-step.is-done{opacity:1}
.sp-dot{width:.5em;height:.5em;border-radius:50%;background:currentColor;opacity:.4}
.sp-step.is-on .sp-dot{opacity:1;background:var(--sp-accent);animation:sp-pulse 1s ease-in-out infinite}
@keyframes sp-pulse{50%{opacity:.3}}
.sp-list{margin:0;padding-inline-start:1.1rem;font-size:.8125rem;line-height:1.45}
.sp-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;overflow-wrap:anywhere}
.sp-link{color:var(--sp-accent);text-decoration:underline;text-underline-offset:.15em;overflow-wrap:anywhere}
.sp-runs{display:grid;gap:0}
.sp-run{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:.75rem;align-items:center;padding:.5rem 0;border-top:1px solid var(--sp-line);font-size:.8125rem}
.sp-runs>.sp-run:first-child{border-top:0}
.sp-run-meta{opacity:.75}
.sp-cell{min-width:0;display:flex;flex-direction:column;gap:.15rem}
`;

    const base = () => window.location.pathname.replace(/\/$/, '');

    function ensureStyle() {
        if (document.getElementById('sp-style')) return;
        const el = document.createElement('style');
        el.id = 'sp-style';
        el.textContent = STYLE;
        document.head.appendChild(el);
    }

    function when(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        return d.toLocaleDateString('da-DK', { day: 'numeric', month: 'short' }) + ' ' +
            d.toLocaleTimeString('da-DK', { hour: '2-digit', minute: '2-digit' });
    }

    function seconds(n) {
        if (n === null || n === undefined) return '';
        if (n < 60) return n + ' s';
        return Math.floor(n / 60) + ' min ' + (n % 60) + ' s';
    }

    function mb(bytes) {
        return (bytes / 1000000).toLocaleString('da-DK', { maximumFractionDigits: 1 }) + ' MB';
    }

    const Utility = {
        name: 'StaticPublishUtility',

        components: {
            UiCardPanel: ui.CardPanel,
            UiHeading: ui.Heading,
            UiDescription: ui.Description,
            UiText: ui.Text,
            UiBadge: ui.Badge,
            UiButton: ui.Button,
            UiAlert: ui.Alert,
        },

        props: {
            token: { type: String, default: '' },
        },

        data() {
            return {
                loading: true,
                error: '',
                settings: null,
                running: null,
                runs: [],
                switching: false,
                starting: false,
                elapsed: 0,
                timer: null,
                clock: null,
                pending: false,
                lastId: null,
                steps: STEPS,
            };
        },

        computed: {
            isStatic() {
                return this.settings && this.settings.mode === 'static';
            },
            ready() {
                return this.settings && this.settings.live_url && this.settings.credentials;
            },
            latest() {
                return this.runs.find(r => r.status !== 'running') || null;
            },
            stepIndex() {
                if (!this.running) return -1;
                return STEPS.findIndex(s => s.key === this.running.step);
            },
        },

        created() {
            ensureStyle();
            this.load();
        },

        beforeUnmount() {
            this.stopPolling();
        },

        methods: {
            async call(path, options) {
                const res = await fetch(base() + '/' + path, Object.assign({
                    headers: { 'X-CSRF-TOKEN': this.token, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                }, options || {}));
                const body = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(body.error || body.message || ('Fejl ' + res.status));
                return body;
            },

            apply(state) {
                this.settings = state.settings;
                this.running = state.running;
                this.runs = state.runs || [];
                // A just-started run has no file until its process has booted;
                // keep polling until a run newer than the one we knew appears.
                if (this.pending && (this.running || (this.runs[0] && this.runs[0].id !== this.lastId))) this.pending = false;
                if (this.running || this.pending) this.startPolling(); else this.stopPolling();
            },

            async load() {
                try {
                    this.apply(await this.call('state'));
                    this.error = '';
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.loading = false;
                }
            },

            async setMode(mode) {
                if (this.switching || !this.settings || this.settings.mode === mode) return;
                this.switching = true;
                try {
                    this.apply(await this.call('mode', { method: 'POST', body: JSON.stringify({ mode }) }));
                    this.error = '';
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.switching = false;
                }
            },

            async publish() {
                if (this.starting || this.running) return;
                this.starting = true;
                this.error = '';
                try {
                    this.lastId = this.runs[0] ? this.runs[0].id : null;
                    await this.call('publish', { method: 'POST', body: '{}' });
                    this.pending = true;
                    this.startPolling();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.starting = false;
                }
            },

            startPolling() {
                if (this.timer) return;
                this.timer = setInterval(() => this.load(), 2000);
                this.clock = setInterval(() => this.tick(), 1000);
                this.tick();
            },

            stopPolling() {
                if (this.timer) clearInterval(this.timer);
                if (this.clock) clearInterval(this.clock);
                this.timer = this.clock = null;
            },

            tick() {
                this.elapsed = this.running ? Math.max(0, Math.round((Date.now() - new Date(this.running.started_at).getTime()) / 1000)) : 0;
            },

            badge(run) {
                return STATUS[run.status] || { text: run.status, color: 'default' };
            },

            stepClass(i) {
                return { 'is-on': i === this.stepIndex, 'is-done': i < this.stepIndex };
            },
        },

        template: `
<div class="sp">
    <ui-card-panel heading="Static Publish">
        <ui-description text="Vælg hvordan sitet leveres til de besøgende." />

        <div class="sp-row" style="margin-top:.75rem">
            <div class="sp-switch" role="group" aria-label="Tilstand">
                <button type="button" :aria-pressed="settings && settings.mode === 'server'" :disabled="switching || loading || !!running" @click="setMode('server')">Server</button>
                <button type="button" :aria-pressed="settings && settings.mode === 'static'" :disabled="switching || loading || !!running" @click="setMode('static')">Statisk site</button>
            </div>
            <ui-badge v-if="isStatic && settings.live_url" :text="settings.live_url" size="sm" />
        </div>

        <p v-if="!loading && !isStatic" class="sp-hint" style="margin-top:.75rem">
            Sitet kører som i dag på serveren. Vælg <strong>Statisk site</strong> for at få en Udgiv-knap, der laver en statisk kopi af hele sitet og lægger den på Cloudflare. Redaktørerne arbejder videre her; de besøgende ser kun de statiske filer.
        </p>

        <ui-alert v-if="error" variant="error" :text="error" style="margin-top:.75rem" />
    </ui-card-panel>

    <template v-if="!loading && isStatic">
        <ui-card-panel heading="Udgiv">
            <ui-alert v-if="!settings.live_url" variant="warning" style="margin-bottom:.75rem">
                Worker-navn og workers.dev-underdomæne mangler.
                <a v-if="settings.settings_url" class="sp-link" :href="settings.settings_url">Sæt dem i addonets indstillinger</a>, så live-adressen kendes, før den første udgivelse.
            </ui-alert>
            <ui-alert v-if="!settings.credentials" variant="warning" text="CLOUDFLARE_API_TOKEN og CLOUDFLARE_ACCOUNT_ID mangler i .env på serveren." style="margin-bottom:.75rem" />

            <div class="sp-row">
                <ui-button variant="primary" icon="upload-cloud"
                    :text="running ? 'Udgiver…' : ((starting || pending) ? 'Starter…' : 'Udgiv')"
                    :disabled="!ready || starting || pending || !!running" @click="publish" />
                <ui-text v-if="!running && latest && latest.status === 'live'" size="sm" variant="subtle">
                    Sidst udgivet {{ when(latest.finished_at) }}<template v-if="latest.user"> af {{ latest.user }}</template>.
                </ui-text>
            </div>

            <div v-if="running" class="sp-box" style="margin-top:1rem">
                <div class="sp-steps">
                    <span v-for="(s, i) in steps" :key="s.key" class="sp-step" :class="stepClass(i)"><span class="sp-dot"></span>{{ s.text }}</span>
                    <ui-text size="sm" variant="subtle" :text="seconds(elapsed)" />
                </div>
                <ui-text size="sm" variant="subtle">
                    Startet {{ when(running.started_at) }}<template v-if="running.user"> af {{ running.user }}</template>. Siden opdaterer sig selv.
                </ui-text>
            </div>

            <template v-else-if="latest">
                <ui-alert v-if="latest.status === 'live'" variant="success" style="margin-top:1rem">
                    Sitet er live på <a class="sp-link" :href="latest.url" target="_blank" rel="noopener">{{ latest.url }}</a>
                    <template v-if="latest.report"> – {{ latest.report.pages }} sider, {{ latest.report.files }} filer, {{ mb(latest.report.bytes) }}, {{ seconds(latest.duration) }}.</template>
                </ui-alert>
                <ui-alert v-else-if="latest.status === 'verified'" variant="info" style="margin-top:1rem"
                    :text="'Kopien blev genereret og kontrolleret, men ikke sendt (--no-deploy). ' + (latest.report ? latest.report.pages + ' sider.' : '')" />
                <ui-alert v-else-if="latest.status === 'failed'" variant="error" style="margin-top:1rem">
                    {{ latest.error || 'Udgivelsen fejlede.' }} Live-sitet er ikke rørt.
                    <ul v-if="latest.report && latest.report.errors.length" class="sp-list" style="margin-top:.4rem">
                        <li v-for="e in latest.report.errors" :key="e">{{ e }}</li>
                    </ul>
                </ui-alert>

                <div v-if="latest.report && (latest.report.warnings.length || latest.report.external_hosts.length || (latest.report.excluded || []).length)" class="sp-box" style="margin-top:.75rem">
                    <template v-if="latest.report.warnings.length">
                        <ui-text size="sm" text="Bemærk" />
                        <ul class="sp-list"><li v-for="w in latest.report.warnings" :key="w">{{ w }}</li></ul>
                    </template>
                    <template v-if="(latest.report.excluded || []).length">
                        <ui-text size="sm" text="Udeladt (redaktørsider)" />
                        <span class="sp-mono">{{ latest.report.excluded.join(', ') }}</span>
                    </template>
                    <template v-if="latest.report.external_hosts.length">
                        <ui-text size="sm" text="Eksterne hosts på siderne" />
                        <span class="sp-mono">{{ latest.report.external_hosts.join(', ') }}</span>
                    </template>
                </div>
            </template>
        </ui-card-panel>

        <ui-card-panel v-if="runs.length" heading="Seneste kørsler">
            <div class="sp-runs">
                <div v-for="r in runs" :key="r.id" class="sp-run">
                    <ui-badge :text="badge(r).text" :color="badge(r).color" size="sm" />
                    <div class="sp-cell">
                        <span>{{ when(r.started_at) }}<template v-if="r.user"> · {{ r.user }}</template><template v-if="r.duration !== null"> · {{ seconds(r.duration) }}</template></span>
                        <span v-if="r.error" class="sp-run-meta">{{ r.error }}</span>
                        <span v-else-if="r.report" class="sp-run-meta">{{ r.report.pages }} sider, {{ r.report.files }} filer<template v-if="r.version_id"> · version {{ r.version_id.slice(0, 8) }}</template></span>
                    </div>
                    <a v-if="r.url" class="sp-link" :href="r.url" target="_blank" rel="noopener">Åbn</a>
                    <span v-else></span>
                </div>
            </div>
        </ui-card-panel>
    </template>
</div>
        `,
    };

    Utility.methods.when = when;
    Utility.methods.seconds = seconds;
    Utility.methods.mb = mb;

    Statamic.booting(() => {
        Statamic.component('static-publish-utility', Utility);
    });
}());
