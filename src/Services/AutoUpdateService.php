<?php

namespace Snawbar\SelfUpdater\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AutoUpdateService
{
    public function run(array $data): void
    {
        // Prevent script from terminating during long downloads or heavy SQL imports
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $stagingPath = storage_path('app/update_staging_dir');
        $zipPath = storage_path('app/update_staging_package.zip');
        $sqlPath = storage_path('app/update_staging_database.sql');

        try {
            Cache::put('update_current_progress', 0, 600);
            Cache::put('update_current_progress_status', 'running', 600);

            $update = app(UpdateService::class);

            $update->backupDatabase();

            $zipUrl = $data['zip_url'] ?? '';
            if (empty($zipUrl)) {
                throw new Exception('The update server did not provide a codebase archive url.');
            }

            Cache::put('update_current_progress_status', 'downloading_zip', 600);
            $update->downloadFileResumable($zipUrl, $zipPath, 'update_current_progress', 0, 50);

            $sqlUrl = $data['sql_url'] ?? '';
            if (! empty($sqlUrl)) {
                Cache::put('update_current_progress_status', 'downloading_sql', 600);
                $update->downloadFileResumable($sqlUrl, $sqlPath, 'update_current_progress', 50, 70);
            }

            Cache::put('update_current_progress_status', 'extracting_code', 600);
            Cache::put('update_current_progress', 80, 600);
            $update->extractToStaging($zipPath, $stagingPath);

            // Check if the update bundle includes its own vendor folder
            $hasBundledVendor = File::exists($stagingPath.'/vendor');

            if (! empty($sqlUrl) && File::exists($sqlPath)) {
                Cache::put('update_current_progress_status', 'syncing_database', 600);
                Cache::put('update_current_progress', 85, 600);
                app(DatabaseSyncService::class)->refreshLiteTempWithLocalFile($sqlPath);
                // Refresh the cache after the sync completes so the TTL doesn't expire mid-step
                Cache::put('update_current_progress', 88, 600);
                Cache::put('update_current_progress_status', 'syncing_database', 600);
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

            // Only run composer if the json changed AND there is no bundled vendor folder provided
            $composerChanged = ($composerJsonChanged || $composerLockChanged || ! File::exists(base_path('vendor'))) && ! $hasBundledVendor;

            Cache::put('update_current_progress_status', 'deploying_files', 600);
            Cache::put('update_current_progress', 90, 600);

            $update->swapStagingToProduction($stagingPath, base_path());

            if ($composerChanged) {
                Cache::put('update_current_progress_status', 'updating_dependencies', 600);
                Cache::put('update_current_progress', 95, 600);

                Artisan::call('app:composer-update');
            } else {
                Cache::put('update_current_progress', 95, 600);
            }

            $version = $data['new_version'] ?? $data['version'] ?? config('self-updater.version', '1.0.0');
            $price = $data['price'] ?? 0;
            $update->finalize($version, $price);

            Cache::put('update_current_progress', 100, 600);
            Cache::put('update_current_progress_status', 'completed', 600);

        } catch (\Throwable $e) {
            Cache::put('update_current_progress_status', 'failed', 600);
            Cache::put('update_current_progress_error', $e->getMessage(), 600);
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

            try {
                // Always ensure the application comes back online first!
                Artisan::call('up');
            } catch (\Throwable $e) {
                // Ignore if it fails to come up
            }

            try {
                // Clear cache last, because if this crashes due to a broken state,
                // we don't want it preventing the app from coming back online.
                Artisan::call('optimize:clear');
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }
}
