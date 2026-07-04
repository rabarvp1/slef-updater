<?php

namespace Snawbar\SelfUpdater\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class DatabaseSyncService
{
    public function refreshLiteTempWithLocalFile($localSqlPath): void
    {
        $sql = File::get($localSqlPath);

        if (!$sql || strlen(trim($sql)) < 10) {
            throw new \Exception('SQL payload file configuration is empty.');
        }

        $defaultConnection = config('database.default', 'mysql');
        $defaultConfig = config("database.connections.{$defaultConnection}");
        
        $targetDatabase = $defaultConfig['database'] ?? env('DB_DATABASE', 'lite');
        $tempDatabase = $targetDatabase . '_temp';

        // Connect without specifying a database so we can CREATE the temp db if it doesn't exist.
        config(['database.connections.update_temp_root' => [
            'driver'    => 'mysql',
            'host'      => $defaultConfig['host'] ?? '127.0.0.1',
            'port'      => $defaultConfig['port'] ?? '3306',
            'database'  => '',
            'username'  => $defaultConfig['username'] ?? 'root',
            'password'  => $defaultConfig['password'] ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options'   => [
                \PDO::ATTR_TIMEOUT => 3600,
            ],
        ]]);

        DB::connection('update_temp_root')->statement("CREATE DATABASE IF NOT EXISTS `{$tempDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::purge('update_temp_root');

        config(['database.connections.update_temp_sync' => [
            'driver'    => 'mysql',
            'host'      => $defaultConfig['host'] ?? '127.0.0.1',
            'port'      => $defaultConfig['port'] ?? '3306',
            'database'  => $tempDatabase,
            'username'  => $defaultConfig['username'] ?? 'root',
            'password'  => $defaultConfig['password'] ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options'   => [
                \PDO::ATTR_TIMEOUT => 3600,
            ],
        ]]);

        try {
            $tempConn = DB::connection('update_temp_sync');
            $tempConn->statement('SET FOREIGN_KEY_CHECKS=0');

            $tables = $tempConn->select("
                SELECT TABLE_NAME as name 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = '{$tempDatabase}'
            ");

            foreach ($tables as $table) {
                $tempConn->statement("DROP TABLE IF EXISTS `{$table->name}`");
            }

            $tempConn->statement('SET FOREIGN_KEY_CHECKS=1');
            $tempConn->unprepared($sql);

            DB::purge('update_temp_sync');

            Artisan::call('db:compare', [
                'source'   => $tempDatabase,
                'target'   => $targetDatabase,
                '--auto'   => true,
            ]);
        } finally {
            // Always clean up temp database after sync so the PC stays clean
            DB::purge('update_temp_sync');
            config(['database.connections.update_temp_cleanup' => [
                'driver'   => 'mysql',
                'host'     => $defaultConfig['host'] ?? '127.0.0.1',
                'port'     => $defaultConfig['port'] ?? '3306',
                'database' => '',
                'username' => $defaultConfig['username'] ?? 'root',
                'password' => $defaultConfig['password'] ?? '',
                'charset'  => 'utf8mb4',
            ]]);
            DB::connection('update_temp_cleanup')->statement("DROP DATABASE IF EXISTS `{$tempDatabase}`");
            DB::purge('update_temp_cleanup');
        }
    }
}
