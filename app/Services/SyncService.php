<?php

namespace App\Services;

use App\Services\ApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PDO;

class SyncService
{
    private ?PDO $conn = null;

    public function syncTable($table, $config, ?callable $onProgress = null)
    {
        $syncStartedAt = now()->format('Y-m-d H:i:s');
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
        $consecutiveFailures = 0;
        $maxConsecutiveFailures = 5;

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
                    $ok = $this->sendBatch($table, $batch, $pairBatch, $trackTrf, $config, $api, $total, $errors); // <-- UBAH: tangkap return value
                    $consecutiveFailures = $ok ? 0 : $consecutiveFailures + 1;                                      // <-- BARU
                    if ($consecutiveFailures >= $maxConsecutiveFailures) {                                          // <-- BARU
                        return ['success' => false, 'error' => "Berhenti: $maxConsecutiveFailures batch gagal berturut-turut (kemungkinan API/server down)"];
                    }
                    if ($onProgress) $onProgress($batchNum, $totalBatches);
                    $batch = [];
                    $pairBatch = [];
                }
            }

            if (!empty($batch)) {
                $batchNum++;
                $ok = $this->sendBatch($table, $batch, $pairBatch, $trackTrf, $config, $api, $total, $errors); // <-- UBAH: tangkap return value
                if (!$ok && ($consecutiveFailures + 1) >= $maxConsecutiveFailures) {                             // <-- BARU (jaga-jaga batch terakhir)
                    return ['success' => false, 'error' => "Berhenti: $maxConsecutiveFailures batch gagal berturut-turut (kemungkinan API/server down)"];
                }
                if ($onProgress) $onProgress($batchNum, $totalBatches);
            }

            if ($totalValid === 0) {
                return ['success' => false, 'error' => 'No valid data'];
            }

            if ($config['updated_field'] ?? null) {
                Cache::forever($this->watermarkKey($config['source_table']), $syncStartedAt);
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
    ): bool {
        $response = $api->sync($table, $batch);
        if ($response['success']) {
            $total += count($batch);
            if ($trackTrf) {
                $this->markSynced($config, $pairBatch);
            }
            return true;
        }
        $errors[] = "Batch gagal (" . count($batch) . " baris): " . ($response['error'] ?? 'Unknown');
        return false;
    }

    private function countBatches($table, $config, int $batchSize): int
    {
        $conn = $this->getDBConnection();
        $sourceTable = $config['source_table'];
        $where = $this->buildWhere($config);
        $stmt = $conn->query("SELECT COUNT(*) AS cnt FROM [$sourceTable] $where");
        $count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        return $batchSize > 0 ? (int) ceil($count / $batchSize) : 0;
    }

    private function buildWhere(array $config): string
    {
        $conditions = [];

        if ($config['track_trf'] ?? false) {
            $conditions[] = "([trf] = 0 OR ([Batal] = 1 AND [trfbatal] = 0))";
        }

        if ($field = $config['updated_field'] ?? null) {
            $last = Cache::get($this->watermarkKey($config['source_table']), '1970-01-01 00:00:00');
            $conditions[] = "([$field] > " . $this->getDBConnection()->quote($last) . ")";
        }

        return $conditions ? 'WHERE ' . implode(' OR ', $conditions) : '';
    }

    private function watermarkKey(string $sourceTable): string
    {
        return "sync:watermark:$sourceTable";
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

    private function fetchData($table, $config, int $chunkSize = 2000): \Generator
    {
        $conn = $this->getDBConnection();
        $sourceTable = $config['source_table'];
        $keyFields = $config['key_fields'];
        $where = ($config['track_trf'] ?? false) ? "WHERE [trf] = 0 OR ([Batal] = 1 AND [trfbatal] = 0)" : '';
        $orderBy = 'ORDER BY [' . implode('], [', $keyFields) . ']';

        $offset = 0;
        while (true) {
            $stmt = $conn->query("SELECT * FROM [$sourceTable] $where $orderBy OFFSET $offset ROWS FETCH NEXT $chunkSize ROWS ONLY");

            $count = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
                $count++;
            }

            if ($count < $chunkSize) {
                break;
            }
            $offset += $chunkSize;
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
