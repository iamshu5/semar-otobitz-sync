<?php

namespace App\Services;

use App\Services\ApiService;
use Illuminate\Support\Facades\Log;
use PDO;

class SyncService
{
    private ?PDO $conn = null;

    public function syncTable($table, $config, ?callable $onProgress = null)
    {
        $keyFields = $config['key_fields'];
        $overrides = $config['overrides'] ?? [];
        $batchSize = config('sync.batch_size', 100);
        $trackTrf = $config['track_trf'] ?? false;
        $api = new ApiService();

        $total = 0;
        $errors = [];
        $batch = [];
        $pairBatch = [];
        $totalValid = 0;
        $batchNum = 0;
        $totalBatches = $onProgress ? $this->countBatches($table, $config, $batchSize) : 0;

        try {
            foreach ($this->fetchData($table, $config) as $rawRow) {
                $mapped = $this->mapToClickhouseColumns($rawRow, $overrides);

                foreach ($keyFields as $field) {
                    if (!isset($mapped[$field]) || $mapped[$field] === '' || $mapped[$field] === null) {
                        continue 2;
                    }
                }

                $clean = [];
                foreach ($mapped as $k => $v) {
                    $clean[$k] = is_string($v) ? trim(strip_tags($v)) : $v;
                }

                $batch[] = $clean;
                $pairBatch[] = ['raw' => $rawRow, 'mapped' => $clean];
                $totalValid++;

                if (count($batch) >= $batchSize) {
                    $batchNum++;
                    $this->sendBatch($table, $batch, $pairBatch, $trackTrf, $config, $api, $total, $errors);
                    if ($onProgress) $onProgress($batchNum, $totalBatches);
                    $batch = [];
                    $pairBatch = [];
                }
            }

            if (!empty($batch)) {
                $batchNum++;
                $this->sendBatch($table, $batch, $pairBatch, $trackTrf, $config, $api, $total, $errors);
                if ($onProgress) $onProgress($batchNum, $totalBatches);
            }

            if ($totalValid === 0) {
                return ['success' => false, 'error' => 'No valid data'];
            }

            return [
                'success' => empty($errors),
                'synced' => $total,
                'total' => $totalValid,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function sendBatch(
        string $table,
        array $batch,
        array $pairBatch,
        bool $trackTrf,
        array $config,
        ApiService $api,
        int &$total,
        array &$errors
    ): void {
        $response = $api->sync($table, $batch);
        if ($response['success']) {
            $total += count($batch);
            if ($trackTrf) {
                $this->markSynced($config, $pairBatch);
            }
        } else {
            $errors[] = "Batch gagal (" . count($batch) . " baris): " . ($response['error'] ?? 'Unknown');
        }
    }

    private function countBatches($table, $config, int $batchSize): int
    {
        $conn = $this->getDBConnection();
        $sourceTable = $config['source_table'];
        $where = ($config['track_trf'] ?? false)
            ? "WHERE [trf] = 0 OR ([Batal] = 1 AND [trfbatal] = 0)"
            : '';
        $stmt = $conn->query("SELECT COUNT(*) AS cnt FROM [$sourceTable] $where");
        $count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        return $batchSize > 0 ? (int) ceil($count / $batchSize) : 0;
    }

    private function markSynced(array $config, array $pairs): void
    {
        $conn = $this->getDBConnection();
        $sourceTable = $config['source_table'];
        $overrides = $config['overrides'] ?? [];
        $keyFields = $config['key_fields'];

        $reverseMap = [];
        if (!empty($pairs)) {
            foreach (array_keys($pairs[0]['raw']) as $sqlCol) {
                $chCol = $overrides[$sqlCol] ?? strtolower($sqlCol);
                $reverseMap[$chCol] = $sqlCol;
            }
        }

        foreach ($pairs as $pair) {
            $raw = $pair['raw'];

            $whereParts = [];
            foreach ($keyFields as $field) {
                $sqlCol = $reverseMap[$field] ?? null;
                if (!$sqlCol || !isset($raw[$sqlCol])) {
                    continue 2;
                }
                $whereParts[] = "[$sqlCol] = " . $conn->quote((string) $raw[$sqlCol]);
            }
            $whereClause = implode(' AND ', $whereParts);

            $isBatal = isset($raw['Batal']) && ((int) $raw['Batal'] === 1);
            $setClause = "[trf] = 1" . ($isBatal ? ", [trfbatal] = 1" : "");

            try {
                $conn->exec("UPDATE [$sourceTable] SET $setClause WHERE $whereClause");
            } catch (\Exception $e) {
                Log::warning("Gagal markSynced untuk $sourceTable: " . $e->getMessage());
            }
        }
    }

    private function fetchData($table, $config, int $limit = 500): \Generator
    {
        $conn = $this->getDBConnection();
        $sourceTable = $config['source_table'];
        $where = ($config['track_trf'] ?? false)
            ? "WHERE [trf] = 0 OR ([Batal] = 1 AND [trfbatal] = 0)"
            : '';

        $stmt = $conn->query("SELECT TOP ($limit) * FROM [$sourceTable] $where");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    private function mapToClickhouseColumns(array $row, array $overrides = []): array
    {
        $mapped = [];
        foreach ($row as $sqlCol => $value) {
            $chCol = $overrides[$sqlCol] ?? strtolower($sqlCol);
            $mapped[$chCol] = $value;
        }
        return $mapped;
    }

    private function getDBConnection(): PDO
    {
        if ($this->conn) {
            return $this->conn;
        }

        $host = env('DB_SYNC_HOST');
        $port = env('DB_SYNC_PORT', '1433');
        $db = env('DB_SYNC_DATABASE');
        $user = env('DB_SYNC_USERNAME');
        $pass = env('DB_SYNC_PASSWORD');

        $dsn = "sqlsrv:Server=$host,$port;Database=$db;TrustServerCertificate=true";

        return $this->conn = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}