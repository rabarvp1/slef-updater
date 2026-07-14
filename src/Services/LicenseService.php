<?php

namespace Snawbar\SelfUpdater\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public function info(): array
    {
        $data = $this->load();

        // Always return an array so callers can safely access ['expire'] without
        // getting "attempt to read property on null". When there is no license,
        // return a stub with expire = null so the middleware falls through safely.
        return empty($data['expire']) ? ['expire' => null] : $data;
    }

    private function load(): array
    {
        $cacheKey = 'license_data_'.$this->serial;

        return Cache::remember($cacheKey, now()->addHours(6), function () {

            $data = $this->fromServer() ?? $this->fromLocal() ?? [];

            if (! empty($data) && function_exists('setting')) {
                $data['title'] = setting('invoice_title');
                $data['phone'] = setting('phone');
                $data['address'] = setting('address');
            }

            if (! empty($data['settings']) && is_array($data['settings']) && Schema::hasTable('settings')) {
                foreach ($data['settings'] as $key => $item) {
                    // Check if it's the rich format {"type": "...", "value": "..."}
                    if (is_array($item) && array_key_exists('value', $item) && array_key_exists('type', $item)) {
                        $valToSave = $item['value'];
                    } else {
                        // Fallback for old flat format or raw arrays
                        $valToSave = is_array($item) ? json_encode($item) : $item;
                    }

                    DB::table('settings')->updateOrInsert(
                        ['key' => $key],
                        ['value' => $valToSave]
                    );
                }
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

            $clientSettings = [];
            if (Schema::hasTable('settings')) {
                $flatSettings = DB::table('settings')->pluck('value', 'key')->toArray();

                $schema = [];
                $bladePath = resource_path('views/setting/form.blade.php');
                if (! file_exists($bladePath)) {
                    $bladePath = resource_path('views/setting/index.blade.php');
                }

                if (file_exists($bladePath)) {
                    $content = file_get_contents($bladePath);
                    $originalLocale = app()->getLocale();
                    app()->setLocale('ku');
                    
                    if (preg_match_all('/<select[^>]*name=["\']([^"\']+)["\'][^>]*>(.*?)<\/select>/is', $content, $selectMatches, PREG_SET_ORDER)) {
                        foreach ($selectMatches as $match) {
                            $name = $match[1];
                            $optionsHtml = $match[2];
                            $options = [];
                            if (preg_match_all('/<option[^>]*value=["\']([^"\']+)["\'][^>]*>(.*?)<\/option>/is', $optionsHtml, $optMatches, PREG_SET_ORDER)) {
                                foreach ($optMatches as $opt) {
                                    $val = $opt[1];
                                    $label = strip_tags($opt[2]);
                                    if (preg_match('/__\([\'"]([^\'"]+)[\'"]\)/', $label, $transMatch)) {
                                        $label = function_exists('trans') ? trans($transMatch[1], [], 'ku') : $transMatch[1];
                                    }
                                    $label = trim(str_replace(['{{', '}}'], '', $label));
                                    if (empty($label)) {
                                        $label = $val;
                                    }
                                    $options[$val] = $label;
                                }
                            }
                            $schema[$name] = ['type' => 'select', 'options' => $options];
                        }
                    }
                    if (preg_match_all('/<input[^>]*type=["\']color["\'][^>]*name=["\']([^"\']+)["\']/i', $content, $matches)) {
                        foreach ($matches[1] as $name) {
                            $schema[$name] = ['type' => 'color'];
                        }
                    }
                    if (preg_match_all('/<input[^>]*name=["\']([^"\']+)["\'][^>]*type=["\']color["\']/i', $content, $matches2)) {
                        foreach ($matches2[1] as $name) {
                            if (!isset($schema[$name])) $schema[$name] = [];
                            $schema[$name]['type'] = 'color';
                        }
                    }
                    
                    if (preg_match_all('/<label>(.*?)<\/label>\s*<(?:input|select)[^>]*name=[\'"]([^\'"]+)[\'"]/is', $content, $labelMatches, PREG_SET_ORDER)) {
                        foreach ($labelMatches as $match) {
                            $labelContent = $match[1];
                            $name = $match[2];
                            if (preg_match('/__\([\'"]([^\'"]+)[\'"]\)/', $labelContent, $transMatch)) {
                                $labelTitle = function_exists('trans') ? trans($transMatch[1], [], 'ku') : $transMatch[1];
                            } else {
                                $labelTitle = trim(strip_tags($labelContent));
                            }
                            if (!isset($schema[$name])) $schema[$name] = [];
                            $schema[$name]['title'] = $labelTitle;
                        }
                    }
                    
                    app()->setLocale($originalLocale);
                }

                // Build rich structure
                foreach ($flatSettings as $k => $v) {
                    if ($k === 'settings_schema' || $k === 'settings_html') {
                        continue;
                    }

                    $type = 'string';
                    $options = [];
                    $title = null;
                    
                    if (isset($schema[$k])) {
                        if (is_array($schema[$k])) {
                            $type = $schema[$k]['type'] ?? 'string';
                            $options = $schema[$k]['options'] ?? [];
                            $title = $schema[$k]['title'] ?? null;
                        } else {
                            $type = $schema[$k];
                        }
                    } else {
                        // Fallback guesses if not in schema
                        $lowerVal = strtolower((string) $v);
                        if ($lowerVal === 'true' || $lowerVal === 'false' || is_bool($v)) {
                            $type = 'boolean';
                        } elseif (is_numeric($v)) {
                            $type = 'number';
                        }
                    }

                    $richObj = [
                        'type' => $type,
                        'value' => $v,
                    ];
                    
                    if ($title) {
                        $richObj['title'] = $title;
                    }
                    
                    if (!empty($options)) {
                        $richObj['options'] = $options;
                    }

                    $clientSettings[$k] = $richObj;
                }
            }

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
                    'settings' => $clientSettings,
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

    public function sync(): void
    {
        $data = $this->fromLocal();
        if (! empty($data['expire'])) {
            $this->pushToServer($data['expire']);
        }
    }

    public function clearCache(): void
    {
        Cache::forget('license_data_'.$this->serial);
    }
}
