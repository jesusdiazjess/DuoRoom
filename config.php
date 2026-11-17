<?php
// IMPORTANT: Replace these four database values with your InfinityFree MySQL details.
// On XAMPP, DB_HOST is normally '127.0.0.1', DB_USER is 'root', DB_PASS is ''.
// On InfinityFree, DB_HOST is NOT localhost. Find it under MySQL Databases.
return [
    'database' => [
        'host' => 'sqlXXX.infinityfree.com',
        'name' => 'if0_XXXXXXXX_duoroom',
        'user' => 'if0_XXXXXXXX',
        'pass' => 'PASTE_YOUR_INFINITYFREE_MYSQL_PASSWORD',
    ],
    // HTTPS is mandatory for microphone/camera on public domains.
    'ice_servers' => [
        ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']],
        // Reliable cross-network calls often need a paid or self-hosted TURN service.
        // Add its credentials here, for example:
        // ['urls' => ['turn:turn.example.com:3478?transport=udp', 'turn:turn.example.com:3478?transport=tcp'],
        //  'username' => 'YOUR_TURN_USER', 'credential' => 'YOUR_TURN_PASSWORD'],
    ],
    'room_lifetime_hours' => 3,
];
