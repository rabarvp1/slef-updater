<?php

namespace Snawbar\SelfUpdater\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class UpdateCheckService
{
    public function check(): ?array
    {
        if (!config('self-updater.enabled', true)) {
            return ['has_update' => false, 'show_force_warning' => false];
        }

        // Cache the result for 24 hours
        return Cache::remember('system_update_check', now()->addDay(), function () {
            try {
                $response = Http::timeout(5)->get(config('self-updater.update_url'));
                if (! $response->ok()) {
                    return ['has_update' => false, 'show_force_warning' => false];
                }

                $serverData = $response->json();

                $currentVersion = DB::table('system_updates')->orderby('applied_at', 'desc')->value('version') ?? config('self-updater.version', '1.0.0');

                // Push current version + user_price for every serial in license.json
                // This runs once per cache cycle (24 hours)
                $this->pushLicenseVersion($currentVersion);

                if (version_compare($serverData['version'], $currentVersion, '>')) {

                    $changelog = 'New features and system enhancements.';
                    if (! empty($serverData['changelog_url'])) {
                        $changelogResponse = Http::timeout(5)->get($serverData['changelog_url']);
                        if ($changelogResponse->ok()) {
                            $changelog = $changelogResponse->body();
                        }
                    }

                    $currentMajor = (int) explode('.', $currentVersion)[0];
                    $serverMajor = (int) explode('.', $serverData['version'])[0];

                    $showForceWarning = (($serverMajor - $currentMajor) > 3);

                    return [
                        'has_update' => true,
                        'show_force_warning' => $showForceWarning,
                        'released_at' => $serverData['released_at'] ?? null,
                        'price' => (empty($serverData['price']) || $serverData['price'] === '0') ? __('all.free') : $serverData['price'],
                        'new_version' => $serverData['version'],
                        'current_version' => $currentVersion,
                        'message' => $serverData['message'] ?? 'New update available',
                        'changelog' => $changelog,
                        'zip_url' => $serverData['zip_url'] ?? '',
                        'sql_url' => $serverData['sql_url'] ?? '',
                    ];
                }

                return ['has_update' => false, 'show_force_warning' => false];

            } catch (\Exception $e) {
                return ['has_update' => false, 'show_force_warning' => false];
            }
        });
    }

    private function pushLicenseVersion(string $currentVersion): void
    {
        try {
            $latestUpdate = DB::table('system_updates')->orderBy('applied_at', 'desc')->first();
            $userPrice = $latestUpdate ? $latestUpdate->user_price : 0;

            $licensePath = config('self-updater.license_local_path');
            $licenseData = file_exists($licensePath)
                ? json_decode(file_get_contents($licensePath), true)
                : null;

            foreach ($licenseData['serials'] ?? [] as $serial => $info) {
                Http::timeout(5)
                    ->withoutVerifying()
                    ->withoutRedirecting()
                    ->withHeaders(['X-License-Secret' => config('self-updater.license_secret')])
                    ->post(config('self-updater.license_write_url'), [
                        'serial' => $serial,
                        'expire' => $info['expire'] ?? null,
                        'title' => $info['title'] ?? null,
                        'phone' => $info['phone'] ?? null,
                        'address' => $info['address'] ?? null,
                        'current_version' => $currentVersion,
                        'user_price' => $userPrice,
                    ]);
            }
        } catch (\Exception $e) {
            // Non-fatal — will retry on next cache cycle
        }
    }
}
