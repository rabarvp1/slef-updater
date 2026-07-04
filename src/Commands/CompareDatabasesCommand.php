<?php

namespace Snawbar\SelfUpdater\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CompareDatabasesCommand extends Command
{
    protected $signature = 'db:compare 
                            {source : Source database (reference)} 
                            {target : Target database (to be synced)}
                            {--dry-run : Preview changes without applying them}
                            {--no-foreign-keys : Skip foreign key recreation}
                            {--auto : Automatically apply changes without confirmation}';

    protected $description = 'Sync target database schema to match source database (including drops)';

    private array $stats = [
        'tables_created' => 0,
        'tables_dropped' => 0, // Added stat track
        'columns_added' => 0,
        'columns_modified' => 0,
        'columns_dropped' => 0, // Added stat track
    ];

    private array $queries = [];
    private string $source;
    private string $target;
    private bool $dryRun;

    public function handle(): int
    {
        $this->source = $this->argument('source');
        $this->target = $this->argument('target');
        $this->dryRun = $this->option('dry-run');

        $this->displayHeader();

        try {
            if (! $this->validateDatabases()) {
                return self::FAILURE;
            }

            if ($this->dryRun) {
                $this->warn('🔍 DRY RUN MODE - No changes will be applied');
                $this->newLine();
            }

            $this->collectChanges();

            if (empty($this->queries)) {
                $this->info('No changes needed - databases are already in sync');
                return self::SUCCESS;
            }

            if (! $this->dryRun && ! $this->confirmExecution()) {
                $this->warn('❌ Operation cancelled by user');
                return self::FAILURE;
            }

            if (! $this->dryRun) {
                $this->executeChanges();
            }

            $this->displaySummary();

            return self::SUCCESS;

        } catch (Exception $e) {
            $this->error("❌ Sync failed: {$e->getMessage()}");
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function displayHeader(): void
    {
        $this->info("Schema Sync: [{$this->source}] → [{$this->target}]");
        $this->line(str_repeat('=', 60));
    }

    private function collectChanges(): void
    {
        $this->queries = [];

        // 1. Structural Additions / Creates
        $this->collectTableChanges();
        $this->collectColumnChanges();
        $this->collectModifyChanges();

        // 2. Structural Removals / Drops
        $this->collectDropColumnChanges();
        $this->collectDropTableChanges();
    }

    private function confirmExecution(): bool
    {
        if ($this->option('auto')) {
            $this->info('🤖 AUTO MODE - Changes will be applied automatically');
            $this->newLine();
            return true;
        }

        if (empty($this->queries)) {
            return true;
        }

        $this->warn("\n📜 SQL QUERIES TO BE EXECUTED:");
        $this->line(str_repeat('=', 60));

        foreach ($this->queries as $i => $query) {
            $num = $i + 1;
            $color = str_contains($query['type'], 'DROP') ? 'red' : 'yellow';
            $this->line("<fg=cyan>[{$num}]</> <fg={$color}>{$query['type']}</>: {$query['description']}");
            $this->line("<fg=gray>    {$query['sql']}</>");
            $this->newLine();
        }

        $this->line(str_repeat('=', 60));
        $this->warn('⚠️  Total Queries: '.count($this->queries));
        $this->newLine();

        return $this->confirm('👉 Do you want to execute these changes?', false);
    }

    private function switchDatabase(string $database): void
    {
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function executeChanges(): void
    {
        $this->info("\n⏳ Executing changes dynamically on target connection...");
        $this->newLine();

        $this->switchDatabase($this->target);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $bar = $this->output->createProgressBar(count($this->queries));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');

        // Disable FK checks for the whole batch
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->queries as $query) {
                DB::statement($query['sql']);
                $bar->advance();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $bar->finish();
        $this->newLine(2);

        // Restore original database
        config(['database.connections.mysql.database' => $this->target]);
        DB::purge('mysql');
    }

    private function validateDatabases(): bool
    {
        $databases = DB::select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?, ?)',
            [$this->source, $this->target]);

        $existing = collect($databases)->pluck('SCHEMA_NAME')->toArray();

        if (! in_array($this->source, $existing)) {
            $this->error("Source database '{$this->source}' does not exist");
            return false;
        }

        if (! in_array($this->target, $existing)) {
            $this->error("Target database '{$this->target}' does not exist");
            return false;
        }

        return true;
    }

    private function collectTableChanges(): void
    {
        $missingTables = $this->getMissingTables();

        if (empty($missingTables)) {
            return;
        }

        $this->warn("\n📋 MISSING TABLES (".count($missingTables).'):');
        $this->line(str_repeat('-', 60));

        foreach ($missingTables as $table) {
            $tableName = $table->TABLE_NAME;

            $this->switchDatabase($this->source);
            $createStmt = DB::selectOne("SHOW CREATE TABLE `{$tableName}`");
            
            $createStmtArray = (array)$createStmt;
            $createSql = $createStmtArray['Create Table'] ?? $createStmtArray['create table'] ?? '';

            $this->switchDatabase($this->target);

            $this->line("  🟢 Will create: <fg=green>{$tableName}</>");

            $this->queries[] = [
                'type' => 'CREATE TABLE',
                'description' => $tableName,
                'sql' => $createSql,
            ];

            $this->stats['tables_created']++;
        }

        $this->newLine();
    }

    private function getMissingTables(): array
    {
        return DB::select("
            SELECT t1.TABLE_NAME
            FROM information_schema.TABLES t1
            LEFT JOIN information_schema.TABLES t2
                ON t1.TABLE_NAME = t2.TABLE_NAME
               AND t2.TABLE_SCHEMA = ?
            WHERE t1.TABLE_SCHEMA = ?
              AND t2.TABLE_NAME IS NULL
              AND t1.TABLE_TYPE = 'BASE TABLE'
        ", [$this->target, $this->source]);
    }

    private function collectDropTableChanges(): void
    {
        $tablesToDrop = DB::select("
            SELECT t1.TABLE_NAME
            FROM information_schema.TABLES t1
            LEFT JOIN information_schema.TABLES t2
                ON t1.TABLE_NAME = t2.TABLE_NAME
               AND t2.TABLE_SCHEMA = ?
            WHERE t1.TABLE_SCHEMA = ?
              AND t2.TABLE_NAME IS NULL
              AND t1.TABLE_TYPE = 'BASE TABLE'
        ", [$this->source, $this->target]);

        if (empty($tablesToDrop)) {
            return;
        }

        $this->error("\n🗑️  DEPRECATED TABLES TO DROP (".count($tablesToDrop).'):');
        $this->line(str_repeat('-', 60));

        foreach ($tablesToDrop as $table) {
            $tableName = $table->TABLE_NAME;

            $this->line("  🔴 Will drop table: <fg=red>{$tableName}</>");

            $this->queries[] = [
                'type' => 'DROP TABLE',
                'description' => $tableName,
                'sql' => "DROP TABLE IF EXISTS `{$tableName}`",
            ];

            $this->stats['tables_dropped']++;
        }
        $this->newLine();
    }

    private function collectColumnChanges(): void
    {
        $missingColumns = $this->getMissingColumns();

        if (empty($missingColumns)) {
            return;
        }

        $this->warn("\n📝 MISSING COLUMNS (".count($missingColumns).'):');
        $this->line(str_repeat('-', 60));

        foreach ($missingColumns as $col) {
            $details = $this->getColumnDetails($col);
            $this->line("  🟡 Will add: <fg=yellow>{$col->TABLE_NAME}</>.<fg=cyan>{$col->COLUMN_NAME}</> <fg=gray>{$details}</>");

            $sql = $this->buildColumnDefinition($col, 'ADD');

            $this->queries[] = [
                'type' => 'ADD COLUMN',
                'description' => "{$col->TABLE_NAME}.{$col->COLUMN_NAME}",
                'sql' => $sql,
            ];

            $this->stats['columns_added']++;
        }

        $this->newLine();
    }

    private function getMissingColumns(): array
    {
        return DB::select('
            SELECT 
                c1.TABLE_NAME,
                c1.COLUMN_NAME,
                c1.COLUMN_TYPE,
                c1.IS_NULLABLE,
                c1.COLUMN_DEFAULT,
                c1.EXTRA
            FROM information_schema.COLUMNS c1
            JOIN information_schema.TABLES t
                ON t.TABLE_NAME = c1.TABLE_NAME
               AND t.TABLE_SCHEMA = ?
            LEFT JOIN information_schema.COLUMNS c2
                ON c1.TABLE_NAME = c2.TABLE_NAME
               AND c1.COLUMN_NAME = c2.COLUMN_NAME
               AND c2.TABLE_SCHEMA = ?
            WHERE c1.TABLE_SCHEMA = ?
              AND c2.COLUMN_NAME IS NULL
        ', [$this->target, $this->target, $this->source]);
    }

    private function collectDropColumnChanges(): void
    {
        $columnsToDrop = DB::select('
            SELECT 
                c1.TABLE_NAME,
                c1.COLUMN_NAME
            FROM information_schema.COLUMNS c1
            JOIN information_schema.TABLES t
                ON t.TABLE_NAME = c1.TABLE_NAME
               AND t.TABLE_SCHEMA = ?
            LEFT JOIN information_schema.COLUMNS c2
                ON c1.TABLE_NAME = c2.TABLE_NAME
               AND c1.COLUMN_NAME = c2.COLUMN_NAME
               AND c2.TABLE_SCHEMA = ?
            WHERE c1.TABLE_SCHEMA = ?
              AND c2.COLUMN_NAME IS NULL
        ', [$this->source, $this->source, $this->target]);

        if (empty($columnsToDrop)) {
            return;
        }

        $this->error("\n🗑️  DEPRECATED COLUMNS TO DROP (".count($columnsToDrop).'):');
        $this->line(str_repeat('-', 60));

        foreach ($columnsToDrop as $col) {
            $isTableBeingDropped = collect($this->queries)
                ->where('type', 'DROP TABLE')
                ->where('description', $col->TABLE_NAME)
                ->isNotEmpty();

            if ($isTableBeingDropped) {
                continue;
            }

            $this->line("  🔴 Will drop column: <fg=red>{$col->TABLE_NAME}</>.<fg=cyan>{$col->COLUMN_NAME}</>");

            if (! $this->option('no-foreign-keys')) {
                $foreignKeys = $this->getForeignKeys($col->TABLE_NAME, $col->COLUMN_NAME);
                foreach ($foreignKeys as $fk) {
                    $this->queries[] = [
                        'type' => 'DROP FK',
                        'description' => "{$col->TABLE_NAME}.{$fk->CONSTRAINT_NAME}",
                        'sql' => "ALTER TABLE `{$col->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`",
                    ];
                }
            }

            $this->queries[] = [
                'type' => 'DROP COLUMN',
                'description' => "{$col->TABLE_NAME}.{$col->COLUMN_NAME}",
                'sql' => "ALTER TABLE `{$col->TABLE_NAME}` DROP COLUMN `{$col->COLUMN_NAME}`",
            ];

            $this->stats['columns_dropped']++;
        }
        $this->newLine();
    }

    private function collectModifyChanges(): void
    {
        $changedColumns = $this->getChangedColumns();

        if (empty($changedColumns)) {
            return;
        }

        $this->warn("\n🔧 CHANGED COLUMNS (".count($changedColumns).'):');
        $this->line(str_repeat('-', 60));

        foreach ($changedColumns as $col) {
            $details = $this->getColumnDetails($col);
            $this->line("  🔵 Will modify: <fg=blue>{$col->TABLE_NAME}</>.<fg=cyan>{$col->COLUMN_NAME}</> <fg=gray>{$details}</>");

            if (! $this->option('no-foreign-keys')) {
                $foreignKeys = $this->getForeignKeys($col->TABLE_NAME, $col->COLUMN_NAME);

                foreach ($foreignKeys as $fk) {
                    $this->queries[] = [
                        'type' => 'DROP FK',
                        'description' => "{$col->TABLE_NAME}.{$fk->CONSTRAINT_NAME}",
                        'sql' => "ALTER TABLE `{$col->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`",
                    ];
                }
            }

            $sql = $this->buildColumnDefinition($col, 'MODIFY');

            $this->queries[] = [
                'type' => 'MODIFY COLUMN',
                'description' => "{$col->TABLE_NAME}.{$col->COLUMN_NAME}",
                'sql' => $sql,
            ];

            if (! $this->option('no-foreign-keys')) {
                $sourceForeignKeys = $this->getSourceForeignKeys($col->TABLE_NAME, $col->COLUMN_NAME);

                foreach ($sourceForeignKeys as $fk) {
                    $this->cleanOrphanedRecords($col, $fk);

                    $fkSql = "ALTER TABLE `{$col->TABLE_NAME}` 
                        ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}` 
                        FOREIGN KEY (`{$col->COLUMN_NAME}`) 
                        REFERENCES `{$fk->REFERENCED_TABLE_NAME}`(`{$fk->REFERENCED_COLUMN_NAME}`)";

                    if ($fk->DELETE_RULE !== 'NO ACTION') {
                        $fkSql .= " ON DELETE {$fk->DELETE_RULE}";
                    }

                    if ($fk->UPDATE_RULE !== 'NO ACTION') {
                        $fkSql .= " ON UPDATE {$fk->UPDATE_RULE}";
                    }

                    $this->queries[] = [
                        'type' => 'ADD FK',
                        'description' => "{$col->TABLE_NAME}.{$fk->CONSTRAINT_NAME}",
                        'sql' => $fkSql,
                    ];
                }
            }

            $this->stats['columns_modified']++;
        }

        $this->newLine();
    }

    private function getChangedColumns(): array
    {
        return DB::select("
            SELECT 
                c1.TABLE_NAME,
                c1.COLUMN_NAME,
                c1.COLUMN_TYPE,
                c1.IS_NULLABLE,
                c1.COLUMN_DEFAULT,
                c1.EXTRA
            FROM information_schema.COLUMNS c1
            JOIN information_schema.COLUMNS c2
                ON c1.TABLE_NAME = c2.TABLE_NAME
               AND c1.COLUMN_NAME = c2.COLUMN_NAME
            WHERE c1.TABLE_SCHEMA = ?
              AND c2.TABLE_SCHEMA = ?
              AND (
                    c1.COLUMN_TYPE <> c2.COLUMN_TYPE OR
                    c1.IS_NULLABLE <> c2.IS_NULLABLE OR
                    IFNULL(c1.COLUMN_DEFAULT,'') <> IFNULL(c2.COLUMN_DEFAULT,'') OR
                    c1.EXTRA <> c2.EXTRA
              )
        ", [$this->source, $this->target]);
    }

    private function buildColumnDefinition(object $col, string $action): string
    {
        $sql = "ALTER TABLE `{$col->TABLE_NAME}` {$action} COLUMN `{$col->COLUMN_NAME}` {$col->COLUMN_TYPE}";

        if ($col->IS_NULLABLE === 'NO') {
            $sql .= ' NOT NULL';
        }

        if ($col->COLUMN_DEFAULT !== null) {
            $sql .= ' DEFAULT '.DB::getPdo()->quote($col->COLUMN_DEFAULT);
        }

        if ($col->EXTRA) {
            $sql .= " {$col->EXTRA}";
        }

        return $sql;
    }

    private function getColumnDetails(object $col): string
    {
        $parts = [];

        $parts[] = $col->COLUMN_TYPE;
        $parts[] = $col->IS_NULLABLE === 'NO' ? 'NOT NULL' : 'NULL';

        if ($col->COLUMN_DEFAULT !== null) {
            $parts[] = "DEFAULT '{$col->COLUMN_DEFAULT}'";
        }

        if ($col->EXTRA) {
            $parts[] = strtoupper($col->EXTRA);
        }

        return '('.implode(', ', $parts).')';
    }

    private function getForeignKeys(string $tableName, string $columnName): array
    {
        return DB::select('
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ', [$this->target, $tableName, $columnName]);
    }

    private function getSourceForeignKeys(string $tableName, string $columnName): array
    {
        return DB::select('
            SELECT 
                kcu.CONSTRAINT_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ?
              AND kcu.TABLE_NAME = ?
              AND kcu.COLUMN_NAME = ?
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ', [$this->source, $tableName, $columnName]);
    }

    private function cleanOrphanedRecords(object $col, object $fk): void
    {
        $this->switchDatabase($this->target);

        $isNullable = $col->IS_NULLABLE === 'YES';

        $orphanedRes = DB::selectOne("
            SELECT COUNT(*) as count
            FROM `{$col->TABLE_NAME}` t
            WHERE t.`{$col->COLUMN_NAME}` IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM `{$fk->REFERENCED_TABLE_NAME}` r
                  WHERE r.`{$fk->REFERENCED_COLUMN_NAME}` = t.`{$col->COLUMN_NAME}`
              )
        ");
        
        $orphanedCount = $orphanedRes->count ?? 0;

        if ($orphanedCount > 0) {
            if ($isNullable) {
                $cleanupSql = "UPDATE `{$col->TABLE_NAME}` t
                    SET t.`{$col->COLUMN_NAME}` = NULL
                    WHERE t.`{$col->COLUMN_NAME}` IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM `{$fk->REFERENCED_TABLE_NAME}` r
                          WHERE r.`{$fk->REFERENCED_COLUMN_NAME}` = t.`{$col->COLUMN_NAME}`
                      )";

                $this->queries[] = [
                    'type' => 'CLEAN DATA',
                    'description' => "Set {$orphanedCount} orphaned {$col->TABLE_NAME}.{$col->COLUMN_NAME} to NULL",
                    'sql' => $cleanupSql,
                ];

                $this->line("  ⚠️  <fg=yellow>Found {$orphanedCount} orphaned records - will set to NULL</>");
            } else {
                // Modified: check if the 'suppliers' table actually exists in the target DB
                // before doing the POS-specific supplier logic, so it works on any system.
                $hasSuppliersTable = DB::selectOne("
                    SELECT count(*) as count 
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'suppliers'
                ", [$this->target])->count > 0;

                if ($hasSuppliersTable && $col->COLUMN_NAME === 'supplier_id' && $fk->REFERENCED_TABLE_NAME === 'suppliers') {
                    $columnExists = DB::selectOne('
                        SELECT COUNT(*) as count
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                          AND TABLE_NAME = ?
                          AND COLUMN_NAME = ?
                    ', [$this->target, $col->TABLE_NAME, $col->COLUMN_NAME])->count > 0;

                    if (! $columnExists) {
                        $maxSupplierId = DB::table('suppliers')->max('id') ?? 0;
                        $defaultSupplierId = $maxSupplierId + 1;

                        $this->queries[] = [
                            'type' => 'CREATE DEFAULT',
                            'description' => 'Create default supplier (دیاری نەکراو)',
                            'sql' => "INSERT INTO `suppliers` (`id`, `name`, `phone`, `address`, `first_balance`) 
                                      VALUES ({$defaultSupplierId}, 'دیاری نەکراو', '', '', 0)",
                        ];
                    } else {
                        $defaultSupplier = DB::table('suppliers')
                            ->where('name', 'دیاری نەکراو')
                            ->first();

                        if ($defaultSupplier) {
                            $defaultSupplierId = $defaultSupplier->id;
                        } else {
                            $maxSupplierId = DB::table('suppliers')->max('id') ?? 0;
                            $defaultSupplierId = $maxSupplierId + 1;

                            $this->queries[] = [
                                'type' => 'CREATE DEFAULT',
                                'description' => 'Create default supplier for orphaned records',
                                'sql' => "INSERT INTO `suppliers` (`id`, `name`, `phone`, `address`, `first_balance`) 
                                          VALUES ({$defaultSupplierId}, 'دیاری نەکراو', '', '', 0)",
                            ];
                        }

                        $updateSql = "UPDATE `{$col->TABLE_NAME}` t
                            SET t.`{$col->COLUMN_NAME}` = {$defaultSupplierId}
                            WHERE t.`{$col->COLUMN_NAME}` IS NOT NULL
                              AND NOT EXISTS (
                                  SELECT 1 FROM `{$fk->REFERENCED_TABLE_NAME}` r
                                  WHERE r.`{$fk->REFERENCED_COLUMN_NAME}` = t.`{$col->COLUMN_NAME}`
                              )";

                        $this->queries[] = [
                            'type' => 'CLEAN DATA',
                            'description' => "Update {$orphanedCount} orphaned {$col->TABLE_NAME} to default supplier",
                            'sql' => $updateSql,
                        ];
                    }
                } else {
                    $cleanupSql = "DELETE t FROM `{$col->TABLE_NAME}` t
                        WHERE t.`{$col->COLUMN_NAME}` IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM `{$fk->REFERENCED_TABLE_NAME}` r
                              WHERE r.`{$fk->REFERENCED_COLUMN_NAME}` = t.`{$col->COLUMN_NAME}`
                          )";

                    $this->queries[] = [
                        'type' => 'CLEAN DATA',
                        'description' => "Delete {$orphanedCount} orphaned records from {$col->TABLE_NAME}",
                        'sql' => $cleanupSql,
                    ];
                }
            }
        }
    }

    private function displaySummary(): void
    {
        $this->line(str_repeat('=', 60));
        $this->info('✅ SYNC COMPLETED SUCCESSFULLY');
        $this->line(str_repeat('=', 60));

        $total = array_sum($this->stats);

        if ($total === 0) {
            $this->info('No changes needed - databases are already in sync');
            return;
        }

        $this->table(
            ['Action', 'Count'],
            [
                ['Tables Created', "<fg=green>{$this->stats['tables_created']}</>"],
                ['Tables Dropped', "<fg=red>{$this->stats['tables_dropped']}</>"],
                ['Columns Added', "<fg=yellow>{$this->stats['columns_added']}</>"],
                ['Columns Modified', "<fg=blue>{$this->stats['columns_modified']}</>"],
                ['Columns Dropped', "<fg=red>{$this->stats['columns_dropped']}</>"],
                ['Total Changes', "<fg=bright-white>{$total}</>"],
            ]
        );
    }
}
