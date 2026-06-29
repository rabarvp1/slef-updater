<?php

namespace Snawbar\SelfUpdater\Services;

use App\Services\DatabaseSyncService;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AutoUpdateService
{
    public function run(array $data): void
    {
        $stagingPath = storage_path('app/update_staging_dir');
        $zipPath = storage_path('app/update_staging_package.zip');
        $sqlPath = storage_path('app/update_staging_database.sql');

        try {
            Cache::put('update_current_progress', 0, 120);
            Cache::put('update_current_progress_status', 'running', 120);

            $update = app(UpdateService::class);

            $update->backupDatabase();

            $zipUrl = $data['zip_url'] ?? '';
            if (empty($zipUrl)) {
                throw new Exception('The update server did not provide a codebase archive url.');
            }

            Cache::put('update_current_progress_status', 'downloading_zip', 120);
            $update->downloadFileResumable($zipUrl, $zipPath, 'update_current_progress', 0, 50);

            $sqlUrl = $data['sql_url'] ?? '';
            if (! empty($sqlUrl)) {
                Cache::put('update_current_progress_status', 'downloading_sql', 120);
                $update->downloadFileResumable($sqlUrl, $sqlPath, 'update_current_progress', 50, 70);
            }

            Cache::put('update_current_progress_status', 'extracting_code', 120);
            Cache::put('update_current_progress', 80, 120);
            $update->extractToStaging($zipPath, $stagingPath);

            if (File::exists($stagingPath.'/vendor')) {
                File::deleteDirectory($stagingPath.'/vendor');
            }

            if (! empty($sqlUrl) && File::exists($sqlPath)) {
                Cache::put('update_current_progress_status', 'syncing_database', 120);
                Cache::put('update_current_progress', 85, 120);
                // We'll leave the DatabaseSyncService as is, assuming it remains in the main app
                // If it should also be in the package, we would need to move it as well.
                app(DatabaseSyncService::class)->refreshLiteTempWithLocalFile($sqlPath);
            }

            Artisan::call('down');

            $composerJsonChanged = false;
            $composerLockChanged = false;

            $stagingJson = $stagingPath.'/composer.json';
            $prodJson = base_path('composer.json');
            if (File::exists($stagingJson)) {
                if (! File::exists($prodJson) || md5_file($stagingJson) !== md5_file($prodJson)) {
                    $composerJsonChanged = true;
                }
            }

            $stagingLock = $stagingPath.'/composer.lock';
            $prodLock = base_path('composer.lock');
            if (File::exists($stagingLock)) {
                if (! File::exists($prodLock) || md5_file($stagingLock) !== md5_file($prodLock)) {
                    $composerLockChanged = true;
                }
            }

            $composerChanged = $composerJsonChanged || $composerLockChanged || ! File::exists(base_path('vendor'));

            Cache::put('update_current_progress_status', 'deploying_files', 120);
            Cache::put('update_current_progress', 90, 120);

            $update->swapStagingToProduction($stagingPath, base_path());

            if ($composerChanged) {
                Cache::put('update_current_progress_status', 'updating_dependencies', 120);
                Cache::put('update_current_progress', 95, 120);

                Artisan::call('app:composer-update');
            } else {
                Cache::put('update_current_progress', 95, 120);
            }

            $version = $data['new_version'] ?? $data['version'] ?? config('self-updater.version', '1.0.0');
            $price = $data['price'] ?? 0;
            $update->finalize($version, $price);

            Cache::put('update_current_progress', 100, 120);
            Cache::put('update_current_progress_status', 'completed', 120);

        } catch (Exception $e) {
            Cache::put('update_current_progress_status', 'failed', 120);
            Cache::put('update_current_progress_error', $e->getMessage(), 120);
            throw new Exception('Auto-update failed: '.$e->getMessage());
        } finally {
            if (File::exists($stagingPath)) {
                File::deleteDirectory($stagingPath);
            }
            if (File::exists($zipPath)) {
                File::delete($zipPath);
            }
            if (File::exists($sqlPath)) {
                File::delete($sqlPath);
            }

            Artisan::call('optimize:clear');
            Artisan::call('up');
        }
    }
}
