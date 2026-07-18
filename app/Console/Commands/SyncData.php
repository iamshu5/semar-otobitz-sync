<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncData extends Command
{
    protected $signature = 'sync:data {--table= : Sync specific table only}';
    protected $description = 'Sync data dari SQL Server ke Cloud API';

    public function handle()
    {
        $this->info('🔄 MULAI SYNC - ' . now());

        $sync = new SyncService();

        try {
            if ($table = $this->option('table')) {
                $config = config("sync.tables.$table");
                if (!$config) {
                    $this->error("TABLE '$table' NOT FOUND!");
                    return 1;
                }
                $this->info("📊 PROSES SYNC TABLE: $table");

                $bar = $this->output->createProgressBar();
                $bar->start();

                $result = $sync->syncTable($table, $config, function ($current, $total) use ($bar) {
                    $bar->setMaxSteps($total);
                    $bar->setProgress($current);
                });

                $bar->finish();
                $this->newLine(2);

                $results = [$table => $result];
            } else {
                $this->info("📊 PROSES SYNC SEMUA TABLE...");
                $tables = config('sync.tables');
                $bar = $this->output->createProgressBar(count($tables));
                $bar->start();

                $results = [];
                foreach ($tables as $name => $config) {
                    $results[$name] = $sync->syncTable($name, $config);
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine(2);
            }

            $this->displayResults($results);
            $this->info('✅ SYNC COMPLETED - ' . now());
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ " . $e->getMessage());
            return 1;
        }
    }

    private function displayResults($results)
    {
        $this->newLine();
        $this->line('📊 HASIL');
        $this->line('──────────');

        $totalSynced = 0;
        foreach ($results as $table => $result) {
            if (!is_array($result)) continue;

            $status = ($result['success'] ?? false) ? '✅' : '❌';
            $synced = $result['synced'] ?? 0;
            $totalSynced += $synced;
            $errors = isset($result['errors']) ? count($result['errors']) : 0;

            $this->line("  $status $table: $synced records" . ($errors ? " ($errors errors)" : ""));

            if (isset($result['error'])) {
                $this->error("      └─ " . $result['error']);
            }
        }

        $this->newLine();
        $this->info("✅ TOTAL: $totalSynced records di sync");
    }
}
