<?php
$pdo = new PDO('mysql:host=localhost;dbname=gelsoft;charset=utf8mb4', 'gelsoft', 'devpass',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    "SET no_such_variable = '+05:30'",
    "SET time_zone = 'Not/AZone'",
    "SET time_zone = 'rubbish'",
    "SET SESSION no_such_variable = 1",
] as $sql) {
    try {
        $pdo->exec($sql);
        printf("  %-36s accepted (no throw)\n", $sql);
    } catch (Throwable $e) {
        printf("  %-36s THROWS %s\n", $sql, substr($e->getMessage(), 0, 52));
    }
}
