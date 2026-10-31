<?php

use function Kipchak\Core\env;

return [
    'connections' => [
        // Qdrant, over its REST API.
        'default' => [
            'store' => 'qdrant',
            'url' => env('QDRANT_URL', 'http://qdrant:6333'),
            'api_key' => env('QDRANT_API_KEY', ''),

            // Seconds; connection failures are retried this many times.
            'timeout' => 10,
            'connect_timeout' => 2,
            'retries' => 1,

            // Points per request when upsert() is given more.
            'batch_size' => 256,

            // Optional: embed text with a connection of the OpenAI driver (or any OpenAI-compatible server it
            // points at), for upsertTexts() and searchText().
            // 'embeddings' => [
            //     'openai' => 'default',
            //     'model' => 'text-embedding-3-small',
            //     'dimensions' => null,
            //     'text_field' => 'text',
            // ],

            // TLS, for https:// URLs.
            // 'tls' => ['ca' => '/etc/ssl/qdrant/ca.pem'],
        ],

        // PostgreSQL with pgvector, on a DBAL connection of the Doctrine driver (kipchak.doctrine).
        // 'postgres' => [
        //     'store' => 'pgvector',
        //     'doctrine' => 'primary',
        //     'table_prefix' => 'vector_',
        // ],

        // OpenSearch with the k-NN plugin.
        // 'search' => [
        //     'store' => 'opensearch',
        //     'url' => env('OPENSEARCH_URL', 'https://opensearch:9200'),
        //     'username' => env('OPENSEARCH_USER', ''),
        //     'password' => env('OPENSEARCH_PASSWORD', ''),
        //     'index_prefix' => 'vectors-',
        //     'engine' => 'lucene',       // or 'faiss'
        //     'refresh' => 'wait_for',    // writes are searchable when upsert() returns; 'false' is faster
        // ],
    ],
];
