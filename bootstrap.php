<?php

require_once __DIR__ . '/vendor/autoload.php';

// load the config for the application
$config_path = __DIR__ . '/config.json';

$handle = @fopen($config_path, 'r');
if ($handle === false) {
    throw new RuntimeException("Could not load config");
}
$config = fread($handle, filesize($config_path));
fclose($handle);

$config = json_decode($config);
$last_json_error = json_last_error();
if ($last_json_error !== JSON_ERROR_NONE) {
    throw new RuntimeException("Could not parse config - JSON error detected");
}

// timezones are fun
date_default_timezone_set('America/Phoenix');

// configure the db connections holder
$db = new Aura\Sql\ConnectionLocator();
$db->setDefault(function () use ($config) {
    $connection = $config->database->slave;
    return new Aura\Sql\ExtendedPdo(
        "mysql:host={$connection->host}",
        $connection->user,
        $connection->password
    );
});
$db->setWrite('master', function () use ($config) {
    $connection = $config->database->master;
    return new Aura\Sql\ExtendedPdo(
        "mysql:host={$connection->host}",
        $connection->user,
        $connection->password
    );
});
$db->setRead('slave', function () use ($config) {
    $connection = $config->database->slave;
    $pdo = new Aura\Sql\ExtendedPdo(
        "mysql:host={$connection->host}",
        $connection->user,
        $connection->password
    );
    $profiler = new Aura\Sql\Profiler();
    $profiler->setActive(true);
    $pdo->setProfiler($profiler);
    return $pdo;
});
