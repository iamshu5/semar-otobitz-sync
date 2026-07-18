<?php

return [
    'tables' => [
        //  CLICKHOUSE AJA
        'trnt_pengajuanstnk' => [
            'source_table' => 'TrnT_PengajuanSTNK',
            'key_fields' => ['nomor', 'nomor_fakturselesai', 'norangka'],
            'overrides' => [],
            'track_trf' => true,
        ],
        'trnt_penerimaanstnkselesai' => [
            'source_table' => 'TrnT_PenerimaanSTNKSelesai',
            'key_fields' => ['nomor', 'nomor_pengajuanstnk', 'norangka'],
            'overrides' => [],
            'track_trf' => true,
        ],
        'trnt_pengajuandokumenlain' => [
            'source_table' => 'TrnT_PengajuanDokumenLain',
            'key_fields' => ['nomor'],
            'overrides' => [],
            'track_trf' => true,
        ],
        'trnt_penerimaandokumenlainselesai' => [
            'source_table' => 'TrnT_PenerimaanDokumenLainSelesai',
            'key_fields' => ['nomor'],
            'overrides' => [],
            'track_trf' => true,
        ],
        'trnt_datapengurusanstck' => [
            'source_table' => 'TrnT_DataPengurusanSTCK',
            'key_fields' => ['nomor', 'norangka', 'kodedealer'],
            'overrides' => [],
            'track_trf' => true,
        ],

        // CLICKHOUSE + POSTGRESQL
        'glbm_masterdealer' => [
            'source_table' => 'GlbM_MasterDealer',
            'key_fields' => ['kodedealer'],
            'overrides' => [],
        ],
        'glbm_mastersamsat' => [
            'source_table' => 'GlbM_MasterSamsat',
            'key_fields' => ['kodesamsat'],
            'overrides' => [],
        ],
        'glbm_mastercabang' => [
            'source_table' => 'GlbM_MasterCabang',
            'key_fields' => ['kodecabang'],
            'overrides' => [],
        ],
        'glbm_grupcabang' => [
            'source_table' => 'GlbM_GrupCabang',
            'key_fields' => ['kode'],
            'overrides' => [],
        ],
        'glbm_mastertipe' => [
            'source_table' => 'GlbM_MasterTipe',
            'key_fields' => ['kodetipe'],
            'overrides' => [],
        ],
        'stpm_konfigurasi' => [
            'source_table' => 'STPM_Konfigurasi',
            'key_fields' => ['kode'],
            'overrides' => [],
        ],
    ],

    'batch_size' => env('SYNC_BATCH_SIZE', 100),
];
