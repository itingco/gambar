<?php

return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'sqlsrv_ingco' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST_INGCO', '127.0.0.1'),
            'port' => env('DB_PORT_INGCO', '1433'),
            'database' => env('DB_DATABASE_INGCO', 'AS_INGCO'),
            'username' => env('DB_USERNAME_INGCO'),
            'password' => env('DB_PASSWORD_INGCO'),
            'charset' => 'utf8',
            'prefix' => '',
            'encrypt' => env('DB_ENCRYPT_INGCO', 'no'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE_INGCO', 'true'),
        ],
        'sqlsrv_smi' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST_SMI', '127.0.0.1'),
            'port' => env('DB_PORT_SMI', '1433'),
            'database' => env('DB_DATABASE_SMI', 'AS_SMI'),
            'username' => env('DB_USERNAME_SMI'),
            'password' => env('DB_PASSWORD_SMI'),
            'charset' => 'utf8',
            'prefix' => '',
            'encrypt' => env('DB_ENCRYPT_SMI', 'no'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE_SMI', 'true'),
        ],
    ],
    'migrations' => 'migrations',
];
