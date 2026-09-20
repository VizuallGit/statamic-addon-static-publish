<?php

namespace Vizuall\StaticPublish\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Vizuall\StaticPublish\Publish\Launcher;
use Vizuall\StaticPublish\Publish\RunLog;
use Vizuall\StaticPublish\Settings;

/**
 * The utility page's three calls: read the state, flip the switch, start a
 * run. What a run is, RunLog decides; what the settings are, Settings decides.
 * This class only translates.
 */
class UtilityController
{
    public function state(RunLog $log): JsonResponse
    {
        return response()->json($this->payload($log));
    }

    public function mode(Request $request, RunLog $log): JsonResponse
    {
        $mode = (string) $request->input('mode');

        if (! in_array($mode, [Settings::MODE_SERVER, Settings::MODE_STATIC], true)) {
            return response()->json(['error' => 'Ugyldig tilstand.'], 422);
        }

        Settings::setMode($mode);

        return response()->json($this->payload($log));
    }

    public function publish(RunLog $log): JsonResponse
    {
        if (Settings::mode() !== Settings::MODE_STATIC) {
            return response()->json(['error' => 'Sitet står på Server. Vælg Statisk site først.'], 422);
        }

        if (! Settings::liveUrl()) {
            return response()->json(['error' => 'Worker-navn og workers.dev-underdomæne mangler i indstillingerne.'], 422);
        }

        if (! Settings::hasCredentials()) {
            return response()->json(['error' => 'CLOUDFLARE_API_TOKEN og CLOUDFLARE_ACCOUNT_ID mangler i .env.'], 422);
        }

        if ($log->running()) {
            return response()->json(['error' => 'En udgivelse kører allerede.'], 409);
        }

        Launcher::start(User::current()?->id());

        return response()->json(['started' => true]);
    }

    protected function payload(RunLog $log): array
    {
        $running = $log->running();

        return [
            'settings' => Settings::summary(),
            'running' => $running ? $this->present($running) : null,
            'runs' => array_map(fn ($run) => $this->present($run), $log->recent((int) config('static-publish.history'))),
        ];
    }

    protected function present(array $run): array
    {
        $user = $run['user'] ? User::find($run['user']) : null;

        return [
            'id' => $run['id'],
            'status' => $run['status'],
            'step' => $run['step'],
            'deploy' => $run['deploy'],
            'user' => $user?->name() ?? ($run['user'] ? null : 'Terminal'),
            'started_at' => $run['started_at'],
            'finished_at' => $run['finished_at'],
            'duration' => $run['duration'],
            'url' => $run['url'],
            'version_id' => $run['version_id'],
            'error' => $run['error'],
            'report' => $run['report'] ? [
                'ok' => $run['report']['ok'],
                'errors' => $run['report']['errors'],
                'warnings' => $run['report']['warnings'],
                'external_hosts' => $run['report']['external_hosts'],
                'excluded' => $run['report']['excluded'] ?? [],
                'pages' => $run['report']['pages'],
                'files' => $run['report']['files'],
                'bytes' => $run['report']['bytes'],
            ] : null,
        ];
    }
}
