<?php

namespace Snawbar\SelfUpdater\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class UpdateService
{
    public function checkUpdate()
    {
        if (! config('self-updater.enabled', true)) {
            return [
                'status' => false,
                'has_update' => false,
                'message' => 'Self-updater is currently disabled in the configuration.',
            ];
        }
        $latestAppliedUpdate = DB::table('system_updates')
            ->orderBy('applied_at', 'desc')
            ->first();

        $currentDatabaseVersion = $latestAppliedUpdate ? $latestAppliedUpdate->version : config('self-updater.version', '1.0.0');

        try {
            $response = Http::timeout(5)->get(config('self-updater.update_url'));

            if (! $response->ok()) {
                return [
                    'status' => false,
                    'has_update' => false,
                    'message' => 'Cannot connect to update server',
                ];
            }

            $latest = $response->json();

        } catch (\Exception $e) {
            return [
                'status' => false,
                'has_update' => false,
                'message' => 'No internet connection or update server is offline.',
            ];
        }

        if (! isset($latest['version'])) {
            return [
                'status' => false,
                'has_update' => false,
                'message' => 'Invalid update response structure',
            ];
        }

        if (version_compare($latest['version'], $currentDatabaseVersion, '>')) {
            if (! empty($latest['changelog_url'])) {
                try {
                    $changelogResponse = Http::timeout(5)->get($latest['changelog_url']);
                    $latest['changelog'] = $changelogResponse->ok() ? $changelogResponse->body() : '';
                } catch (\Exception $e) {
                    $latest['changelog'] = '';
                }
            }

            $latest['has_update'] = true;
            $latest['current_version'] = $currentDatabaseVersion;
            $latest['new_version'] = $latest['version'];

            return [
                'status' => true,
                'has_update' => true,
                'data' => $latest,
            ];
        }

        return [
            'status' => false,
            'has_update' => false,
            'message' => 'Your system database shows you are already updated to version '.$currentDatabaseVersion,
        ];
    }

    public function downloadFileResumable(string $url, string $savePath, string $cacheProgressKey, int $startPct, int $endPct): void
    {
        $maxRetries = 200;
        $retryDelay = 4;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $downloadedBytes = File::exists($savePath) ? File::size($savePath) : 0;

                $headResponse = Http::timeout(10)->head($url);
                $totalBytes = (int) $headResponse->header('Content-Length');

                if ($downloadedBytes >= $totalBytes && $totalBytes > 0) {
                    return;
                }

                $fp = fopen($savePath, $downloadedBytes > 0 ? 'ab' : 'wb');

                if (function_exists('curl_init')) {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                    curl_setopt($ch, CURLOPT_FILE, $fp);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

                    if ($downloadedBytes > 0) {
                        curl_setopt($ch, CURLOPT_RANGE, $downloadedBytes.'-');
                    }

                    @curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($downloadedBytes, $totalBytes, $cacheProgressKey, $startPct, $endPct) {
                        $currentDownloaded = $downloadedBytes + $downloaded;
                        if ($totalBytes > 0) {
                            $ratio = $currentDownloaded / $totalBytes;
                            $calcPercentage = $startPct + round($ratio * ($endPct - $startPct));
                            Cache::put($cacheProgressKey, min($endPct, $calcPercentage), 120);
                        }
                    });

                    $result = curl_exec($ch);
                    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                } else {
                    $options = [
                        'sink' => $fp,
                        'timeout' => 45,
                        'verify' => false,
                    ];
                    if ($downloadedBytes > 0) {
                        $options['headers'] = ['Range' => 'bytes='.$downloadedBytes.'-'];
                    }
                    $response = Http::timeout(45)->withOptions($options)->get($url);
                    $result = $response->successful() || $response->status() === 206;
                    $statusCode = $response->status();
                }

                fclose($fp);

                if ($statusCode >= 400) {
                    if (File::exists($savePath)) {
                        File::delete($savePath);
                    }
                    throw new \Exception('HTTP Error '.$statusCode.' while downloading update file.');
                }

                if ($result && ($statusCode == 200 || $statusCode == 206)) {
                    return;
                }

                throw new \Exception('Network chunk stream cut off.');
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'HTTP Error') !== false) {
                    throw $e;
                }
                Cache::put($cacheProgressKey.'_status', 'paused_network_offline', 120);
                sleep($retryDelay);
            }
        }

        throw new \Exception('Cannot complete download. Internet connection timed out permanently.');
    }

    public function extractToStaging(string $zipPath, string $stagePath): void
    {
        if (File::exists($stagePath)) {
            File::deleteDirectory($stagePath);
        }
        File::makeDirectory($stagePath, 0755, true);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \Exception('Cannot open ZIP archive update bundle.');
        }
        $zip->extractTo($stagePath);
        $zip->close();

        $folders = array_values(array_filter(
            scandir($stagePath),
            fn ($item) => ! in_array($item, ['.', '..', '__MACOSX', '.DS_Store'])
        ));

        if (count($folders) === 1 && is_dir($stagePath.'/'.$folders[0])) {
            $innerFolder = $stagePath.'/'.$folders[0];
            $tempMovePath = $stagePath.'_temp_move';

            // Add a small delay for Windows to release file locks after ZIP extraction
            sleep(1);

            $maxRetries = 3;
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    File::move($innerFolder, $tempMovePath);
                    File::deleteDirectory($stagePath);
                    File::move($tempMovePath, $stagePath);
                    break;
                } catch (\Exception $e) {
                    if ($attempt === $maxRetries) {
                        throw $e;
                    }
                    sleep(2);
                }
            }
        }
    }

    public function swapStagingToProduction(string $stagePath, string $destination): void
    {
        $this->atomicMergeLoop($stagePath, $destination);
    }

    private function atomicMergeLoop(string $source, string $destination): void
    {
        $dirContents = scandir($source);
        $protectedDirectories = ['storage', 'bootstrap', 'node_modules', '.git'];
        $protectedFiles = ['.env'];
        $protectedPaths = [
            'public/images/bill',
            'public/images/products',
        ];

        foreach ($dirContents as $item) {
            if (in_array($item, ['.', '..', '__MACOSX', '.DS_Store'])) {
                continue;
            }

            $srcPath = $source.DIRECTORY_SEPARATOR.$item;
            $destPath = $destination.DIRECTORY_SEPARATOR.$item;
            $normalizedDest = str_replace('\\', '/', $destPath);

            $isProtected = false;
            foreach ($protectedPaths as $protectedPath) {
                if (str_contains($normalizedDest, '/'.trim($protectedPath, '/'))) {
                    $isProtected = true;
                    break;
                }
            }
            if ($isProtected) {
                continue;
            }

            if (is_dir($srcPath)) {
                if (in_array(strtolower($item), $protectedDirectories)) {
                    continue;
                }

                if (strtolower($item) === 'vendor') {
                    if (file_exists($destPath)) {
                        File::deleteDirectory($destPath);
                    }
                    rename($srcPath, $destPath);

                    continue;
                }

                if (! file_exists($destPath)) {
                    mkdir($destPath, 0755, true);
                }

                $this->atomicMergeLoop($srcPath, $destPath);
            } else {
                if (in_array($item, $protectedFiles)) {
                    continue;
                }

                copy($srcPath, $destPath);
            }
        }
    }

    public function backupDatabase()
    {
        $path = storage_path('app/backups');
        if (! File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }

        $file = $path.'/DataBase_Backup_'.date('Y_m_d_H_i_s').'.sql';
        $mysqldumpPath = env('DUMP_BINARY_PATH', 'mysqldump');

        $passwordFlag = env('DB_PASSWORD') ? '-p"'.env('DB_PASSWORD').'"' : '';
        $command = "\"{$mysqldumpPath}\" -u".env('DB_USERNAME')." {$passwordFlag} ".env('DB_DATABASE')." > \"{$file}\"";

        exec($command);

        return $file;
    }

    public function finalize($version, $price)
    {
        DB::table('system_updates')->updateOrInsert(
            ['version' => $version],
            ['version' => $version, 'applied_at' => now(), 'version_price' => $price]
        );

        $configPath = config_path('system.php');
        if (File::exists($configPath)) {
            $configContent = File::get($configPath);
            $newConfigContent = preg_replace(
                "/'version'\s*=>\s*'[^']+'/",
                "'version' => '{$version}'",
                $configContent
            );
            File::put($configPath, $newConfigContent);
        }

        Artisan::call('optimize:clear');
    }
}
