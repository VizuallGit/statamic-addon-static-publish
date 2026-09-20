<?php

namespace Vizuall\StaticPublish;

use RuntimeException;
use Statamic\Addons\Addon;
use Statamic\Facades\Addon as Addons;

/**
 * The addon's settings (resources/addons/static-publish.yaml, edited on the
 * addon's settings page or through the utility's switch) and the two secrets
 * from .env, read in one place. Secrets are never returned to the browser;
 * the page only learns whether they are present.
 */
class Settings
{
    public const SLUG = 'static-publish';

    public const MODE_SERVER = 'server';

    public const MODE_STATIC = 'static';

    public static function addon(): Addon
    {
        $addon = Addons::all()->first(fn (Addon $addon) => $addon->slug() === self::SLUG);

        if (! $addon) {
            throw new RuntimeException('Static Publish er ikke registreret som addon.');
        }

        return $addon;
    }

    public static function mode(): string
    {
        return self::addon()->setting('mode') === self::MODE_STATIC ? self::MODE_STATIC : self::MODE_SERVER;
    }

    public static function setMode(string $mode): void
    {
        if (! in_array($mode, [self::MODE_SERVER, self::MODE_STATIC], true)) {
            throw new RuntimeException("Ukendt tilstand: {$mode}");
        }

        self::addon()->settings()->set('mode', $mode)->save();
    }

    public static function workerName(): string
    {
        return trim((string) self::addon()->setting('worker_name', ''));
    }

    public static function workersSubdomain(): string
    {
        return trim((string) self::addon()->setting('workers_subdomain', ''));
    }

    /** https://{worker_name}.{workers_subdomain}.workers.dev, or null until both are set. */
    public static function liveUrl(): ?string
    {
        $name = self::workerName();
        $subdomain = self::workersSubdomain();

        return ($name !== '' && $subdomain !== '') ? "https://{$name}.{$subdomain}.workers.dev" : null;
    }

    public static function apiToken(): string
    {
        return (string) config('static-publish.cloudflare.api_token', '');
    }

    public static function accountId(): string
    {
        return (string) config('static-publish.cloudflare.account_id', '');
    }

    public static function hasCredentials(): bool
    {
        return self::apiToken() !== '' && self::accountId() !== '';
    }

    /** What the utility page shows. Secrets only as present/absent. */
    public static function summary(): array
    {
        return [
            'mode' => self::mode(),
            'worker_name' => self::workerName(),
            'workers_subdomain' => self::workersSubdomain(),
            'live_url' => self::liveUrl(),
            'credentials' => self::hasCredentials(),
            'settings_url' => self::addon()->settingsUrl(),
        ];
    }
}
