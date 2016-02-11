<?php

require_once __DIR__ . '/../bootstrap.php';

$client = new DailymilePHP\Client();

$mostRecentEntryDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`dailymile` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentEntryDateTime = new DateTime($mostRecentEntryDateTime);

$entries = $client->getEntries([
    'username' => 'JacobE4',
    'since' => $mostRecentEntryDateTime->getTimestamp(),
]);

foreach ($entries as $entry) {
    $uniqueEntryCheck = $db->getRead()->fetchValue(
        "SELECT 1 FROM `jpemeric_stream`.`dailymile` WHERE `entry_id` = :entry_id LIMIT 1",
        ['entry_id' => $entry['id']]
    );
    if ($uniqueEntryCheck !== false) {
        continue;
    }

    $dateTime = new DateTime($entry['at']);
    $dateTime->setTimezone(new DateTimeZone('America/Phoenix'));

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`dailymile` (`entry_id`, `type`, `datetime`, `metadata`) " . 
        "VALUES (:entry_id, :workout, :datetime, :metadata)",
        [
            'entry_id' => $entry['id'],
            'workout' => $entry['workout']['activity_type'],
            'datetime' => $dateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($entry),
        ]
    );
}
