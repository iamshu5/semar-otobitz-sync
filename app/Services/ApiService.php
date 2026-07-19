<?php

namespace App\Services;

use GuzzleHttp\Client;

class ApiService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim(env('API_BASE_URL'), '/') . '/',
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function sync($table, $data)
    {
        try {
            $response = $this->client->post("sync/$table", ['json' => $data]);
            return ['success' => true, 'code' => $response->getStatusCode()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function ping()
    {
        try {
            $response = $this->client->get('ping', ['timeout' => 10]);
            $body = json_decode($response->getBody(), true);
            return ['success' => $body['ok'] ?? false, 'code' => $response->getStatusCode()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}