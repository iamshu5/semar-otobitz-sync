<?php

namespace App\Services;

use GuzzleHttp\Client;

class ApiService
{
    public function sync($table, $data)
    {
        $baseUrl = rtrim(env('API_BASE_URL'), '/');
        $url = "$baseUrl/sync/$table";

        $client = new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        try {
            $response = $client->post($url, ['json' => $data]);
            return [
                'success' => true,
                'code' => $response->getStatusCode(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function ping()
    {
        $baseUrl = rtrim(env('API_BASE_URL'), '/');
        $url = "$baseUrl/ping";

        $client = new Client(['timeout' => 10]);

        try {
            $response = $client->get($url);
            $body = json_decode($response->getBody(), true);
            return [
                'success' => $body['ok'] ?? false,
                'code' => $response->getStatusCode(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
