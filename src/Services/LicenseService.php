<?php

namespace Snawbar\SelfUpdater\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LicenseService
{
    private string $serial;

    private string $localPath;

    private bool $online;

    public function __construct()
    {
        $host = request()->getHost();
        $this->online = $host !== 'localhost' && ! filter_var($host, FILTER_VALIDATE_IP);
        $this->serial = $this->online
            ? $host
            : optional(app('system'))->token;
        $this->localPath = config('self-updater.license_local_path', base_path('license.json'));
    }

    public function check(): bool
    {
        $data = $this->load();
        $expire = Carbon::parse($data['expire'] ?? null);

        // Only checks THIS client's own expire — no other serials
        return Carbon::today()->lte($expire);
    }

    public function info(): ?array
    {
        $data = $this->load();

        return empty($data['expire']) ? null : $data;
    }

    private function load(): array
    {
        $cacheKey = 'license_data_'.$this->serial;

        return Cache::remember($cacheKey, now()->addDay(), function () {

            $data = $this->fromServer() ?? $this->fromLocal() ?? [];

            if (! empty($data) && function_exists('setting')) {
                $data['title'] = setting('invoice_title');
                $data['phone'] = setting('phone');
                $data['address'] = setting('address');
            }

            // Step 3: Save flat local file — only this client's data
            $this->saveLocal($data);

            // Step 4: Register if new, otherwise push to server
            if (empty($data['expire'])) {
                $data = $this->register($data);
            } else {
                $this->pushToServer($data['expire']);
            }

            return $data;
        });
    }

    private function register(array $data): array
    {
        $latestUpdate = DB::table('system_updates')->orderBy('applied_at', 'desc')->first();
        $currentVersion = $latestUpdate ? $latestUpdate->version : config('self-updater.version', '1.0.0');
        $userPrice = $latestUpdate ? (string) $latestUpdate->user_price : '0';
        $expire = optional(app('system_payment'))->expire;

        if (blank($expire)) {
            return $data;
        }

        $data = [
            'serial' => $this->serial,
            'expire' => $expire,
            'title' => function_exists('setting') ? setting('invoice_title') : '',
            'phone' => function_exists('setting') ? setting('phone') : '',
            'address' => function_exists('setting') ? setting('address') : '',
            'price' => optional(app('system_payment'))->price,
            'current_version' => $currentVersion,
            'user_price' => $userPrice,
        ];

        $this->saveLocal($data);
        $this->pushToServer($expire);

        return $data;
    }

    private function fromServer(): ?array
    {
        try {
            // Send ?serial=xxx so server returns ONLY this client's serial
            $response = Http::timeout(5)
                ->withoutVerifying()
                ->get(config('self-updater.license_url'), [
                    'serial' => $this->serial,
                ]);

            if ($response->failed()) {
                return null;
            }

            $json = $response->json();

            // Server returns { "serials": { "xxx": { ... } } }
            // Extract only this serial's flat data
            $flat = $json['serials'][$this->serial] ?? null;

            if (empty($flat)) {
                return null;
            }

            // Return flat array — only this client's data
            return $flat;

        } catch (\Exception $e) {
            return null;
        }
    }

    private function fromLocal(): ?array
    {
        if (! file_exists($this->localPath)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->localPath), true);

        if (empty($data) || ! is_array($data)) {
            return null;
        }

        // Safety: handle old format that had "serials" wrapper
        if (isset($data['serials'])) {
            return $data['serials'][$this->serial] ?? null;
        }

        // New format: flat array — just return it
        return $data;
    }

    private function saveLocal(array $data): void
    {
        if (empty($data)) {
            return;
        }

        // FLAT structure — no "serials" key, no other clients
        // { "serial": "xxx", "expire": "2026-09-28", "current_version": "200.0.1", ... }
        file_put_contents(
            $this->localPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function pushToServer(string $expire): void
    {
        try {
            // Read THIS client's own system_updates — never reads other clients' tables
            $latestUpdate = DB::table('system_updates')->orderBy('applied_at', 'desc')->first();
            $currentVersion = $latestUpdate ? $latestUpdate->version : config('self-updater.version', '1.0.0');
            $userPrice = $latestUpdate ? (string) $latestUpdate->user_price : '0';

            $response = Http::timeout(5)
                ->withoutVerifying()
                ->withoutRedirecting()
                ->withHeaders(['X-License-Secret' => config('self-updater.license_secret')])
                ->post(config('self-updater.license_write_url'), [
                    'serial' => $this->serial,
                    'expire' => $expire,
                    'title' => function_exists('setting') ? setting('invoice_title') : '',
                    'phone' => function_exists('setting') ? setting('phone') : '',
                    'address' => function_exists('setting') ? setting('address') : '',
                    'price' => optional(app('system_payment'))->price,
                    'current_version' => $currentVersion,
                    'user_price' => $userPrice,
                ]);

            if ($response->failed()) {
                Log::warning('LicenseService: push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('LicenseService: push exception', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function clearCache(): void
    {
        Cache::forget('license_data_'.$this->serial);
    }
}
