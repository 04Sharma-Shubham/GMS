<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$force = in_array('--force', $argv, true);
$apply = in_array('--apply', $argv, true);
if ((string) env('APP_ENV', 'production') === 'production' && !$force) {
    fwrite(STDERR, "Refusing to touch a production database. Pass --force only when you mean to replace the demo gym.\n");
    exit(1);
}

$sql = dirname(__DIR__) . '/database/database.sql';
if (!is_file($sql)) {
    fwrite(STDERR, "database/database.sql is missing.\n");
    exit(1);
}

if ($apply) {
    $config = require dirname(__DIR__) . '/config/database.php';
    $command = sprintf(
        'mysql --host=%s --port=%s --user=%s --default-character-set=utf8mb4 %s < %s',
        escapeshellarg((string) $config['host']),
        escapeshellarg((string) $config['port']),
        escapeshellarg((string) $config['user']),
        escapeshellarg((string) $config['name']),
        escapeshellarg($sql)
    );
    $password = (string) $config['pass'];
    if ($password !== '') {
        putenv('MYSQL_PWD=' . $password);
    }
    passthru($command, $code);
    putenv('MYSQL_PWD');
    if ($code !== 0) {
        fwrite(STDERR, "Import failed. Import database/database.sql from phpMyAdmin instead.\n");
        exit($code);
    }
}

$gym = db_one('SELECT id, name, slug FROM gyms WHERE slug = ?', ['pulseforge-demo']);
if (!$gym) {
    fwrite(STDERR, "Demo gym is not in this database. Import database/database.sql, or run: php database/seed.php --apply\n");
    exit(1);
}

$owner = db_one('SELECT email FROM users WHERE gym_id = ? AND role = ?', [(int) $gym['id'], 'owner']);
echo "Demo gym: {$gym['name']} ({$gym['slug']})\n";
echo "Password for every sample login: Demo@12345\n";
echo "Owner: " . ($owner['email'] ?? 'owner@pulseforge.demo') . "\n";
echo "Manager: manager@pulseforge.demo\n";
echo "Reception: desk@pulseforge.demo\n";
echo "Trainer: trainer@pulseforge.demo\n";
echo "The sample dates are anchored to 11 November 2026.\n";
