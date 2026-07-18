<?php

namespace App\Services;

use App\Services\ApiService;
use Illuminate\Support\Facades\Log;
use PDO;

class SyncService
{
    public function syncAll()
    {
        $results = [];
        $tables = config('sync.tables');

        $api = new ApiService();
        $test = $api->ping();
        if (!$test['success']) {
            return ['error' => 'API not reachable: ' . ($test['error'] ?? '')];
        }

        foreach ($tables as $table => $config) {
            $results[$table] = $this->syncTable($table, $config);
        }

        return $results;
    }

    public function syncTable($table, $config, ?callable $onProgress = null)
    {
        $keyFields = $config['key_fields'];
        $overrides = $config['overrides'] ?? [];
        $batchSize = config('sync.batch_size', 100);
        $trackTrf = $config['track_trf'] ?? false;
        $api = new ApiService();

        try {
            $rawData = $this->fetchData($table, $config);

            if (empty($rawData)) {
                return ['success' => true, 'synced' => 0, 'message' => 'No data'];
            }

            $pairs = [];
            foreach ($rawData as $rawRow) {
                $pairs[] = [
                    'raw' => $rawRow,
                    'mapped' => $this->mapToClickhouseColumns($rawRow, $overrides),
                ];
            }

            $validPairs = array_values(array_filter($pairs, function ($p) use ($keyFields) {
                foreach ($keyFields as $field) {
                    if (!isset($p['mapped'][$field]) || $p['mapped'][$field] === '' || $p['mapped'][$field] === null) {
                        return false;
                    }
                }
                return true;
            }));

            if (empty($validPairs)) {
                return ['success' => false, 'error' => 'No valid data'];
            }

            $validData = array_map(function ($p) {
                $clean = [];
                foreach ($p['mapped'] as $k => $v) {
                    $clean[$k] = is_string($v) ? trim(strip_tags($v)) : $v;
                }
                return $clean;
            }, $validPairs);

            $total = 0;
            $errors = [];
            $batches = array_chunk($validData, $batchSize);
            $pairBatches = array_chunk($validPairs, $batchSize);
            $totalBatches = count($batches);

            foreach ($batches as $i => $batch) {
                $response = $api->sync($table, $batch);
                if ($response['success']) {
                    $total += count($batch);
                    if ($trackTrf) {
                        $this->markSynced($config, $pairBatches[$i]);
                    }
                } else {
                    $errors[] = "Batch " . ($i + 1) . ": " . ($response['error'] ?? 'Unknown');
                }

                if ($onProgress) {
                    $onProgress($i + 1, $totalBatches);
                }
            }

            return [
                'success' => empty($errors),
                'synced' => $total,
                'total' => count($validData),
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
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

    private function fetchData($table, $config)
    {
        if (!isset($config['source_table'])) {
            throw new \Exception("Konfigurasi 'source_table' tidak ditemukan untuk tabel '$table'.");
        }
        $conn = $this->getDBConnection();
        $sourceTable = $config['source_table'];

        $where = '';
        if ($config['track_trf'] ?? false) {
            $where = "WHERE [trf] = 0 OR ([Batal] = 1 AND [trfbatal] = 0)";
        }

        $stmt = $conn->query("SELECT * FROM [$sourceTable] $where");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    private function getDBConnection()
    {
        $host = env('DB_SYNC_HOST');
        $port = env('DB_SYNC_PORT', '1433');
        $db = env('DB_SYNC_DATABASE');
        $user = env('DB_SYNC_USERNAME');
        $pass = env('DB_SYNC_PASSWORD');

        $dsn = "sqlsrv:Server=$host,$port;Database=$db;TrustServerCertificate=true";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function validateData($data, $keyFields)
    {
        $valid = [];
        foreach ($data as $row) {
            $ok = true;
            foreach ($keyFields as $field) {
                if (!isset($row[$field]) || $row[$field] === '' || $row[$field] === null) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $clean = [];
                foreach ($row as $k => $v) {
                    $clean[$k] = is_string($v) ? trim(strip_tags($v)) : $v;
                }
                $valid[] = $clean;
            }
        }
        return $valid;
    }
}
