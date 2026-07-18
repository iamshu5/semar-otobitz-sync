<?php

namespace App\Console\Commands;

use App\Services\ApiService;
// use App\Services\SyncService;
use Illuminate\Console\Command;
use PDO;

class SyncTest extends Command
{
    protected $signature = 'sync:test';
    protected $description = 'Test sync configuration';

    public function handle()
    {
        $this->info('TESTING SYNC CONFIGURASI...');
        $this->newLine();

        $this->testDatabase();

        $this->testApi();

        $this->testConfig();

        $this->newLine();
        $this->info('SEMUA TEST BERHASIL!');
        return 0;
    }

    private function testDatabase()
    {
        $this->info('TESTING SQL SERVER...');

        try {
            $host = env('DB_SYNC_HOST');
            $port = env('DB_SYNC_PORT', '1433');
            $db = env('DB_SYNC_DATABASE');
            $user = env('DB_SYNC_USERNAME');
            $pass = env('DB_SYNC_PASSWORD');

            $dsn = "sqlsrv:Server=$host,$port;Database=$db;TrustServerCertificate=true";
            $conn = new PDO($dsn, $user, $pass);
            $conn->query('SELECT 1');

            $this->info("✅ SQL SERVER OK");
            $this->info("Host: $host:$port");
            $this->info("Database: $db");
        } catch (\Exception $e) {
            $this->error("❌ SQL SERVER FAILED: " . $e->getMessage());
            throw $e;
        }
    }

    private function testApi()
    {
        $this->info('TESTING API...');

        try {
            $api = new ApiService();
            $result = $api->ping();

            if ($result['success']) {
                $this->info("✅ API OK");
                $this->info("URL: " . env('API_BASE_URL'));
            } else {
                $this->error("❌ API GAGAL: " . ($result['error'] ?? 'Unknown'));
                $this->error("RAW RESPONSE: " . ($result['raw'] ?? 'kosong'));
                throw new \Exception('API test failed');
            }
        } catch (\Exception $e) {
            $this->error("❌ API GAGAL: " . $e->getMessage());
            throw $e;
        }
    }

    private function testConfig()
    {
        $this->info('TESTING CONFIGURATION...');

        $tables = config('sync.tables');
        $this->info("✅ " . count($tables) . " tables configured");

        foreach ($tables as $name => $config) {
            $this->line("      • $name: source=" . ($config['source_table'] ?? '⚠️ MISSING') . " | keys=" . implode(', ', $config['key_fields'] ?? []));
        }
    }
}
