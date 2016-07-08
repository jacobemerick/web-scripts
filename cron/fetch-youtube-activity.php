<?php

require_once __DIR__ . '/../bootstrap.php';

$client = new Madcoda\Youtube(['key' => $config->youtube->key]);

$mostRecentVideoDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`youtube` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentVideoDateTime = new DateTime($mostRecentVideoDateTime);

try {
    $playlist = $client->getPlaylistItemsByPlaylistId($config->youtube->favorites_playlist, 10);
} catch (Exception $e) {
    $logger->addError($e->getMessage());
    exit();
}

foreach ($playlist as $playlistItem) {
    $datetime = new DateTime($playlistItem->snippet->publishedAt);
    if ($datetime <= $mostRecentVideoDateTime) {
        break;
    }

    $uniqueVideoCheck = $db->getRead()->fetchValue(
        "SELECT 1 FROM `jpemeric_stream`.`youtube` WHERE `video_id` = :video_id LIMIT 1",
        ['video_id' => $playlistItem->contentDetails->videoId]
    );
    if ($uniqueVideoCheck !== false) {
        continue;
    }

    $datetime->setTimezone(new DateTimeZone('America/Phoenix'));

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`youtube` (`video_id`, `datetime`, `metadata`) " .
        "VALUES (:video_id, :datetime, :metadata)",
        [
            'video_id' => $playlistItem->contentDetails->videoId,
            'datetime' => $datetime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($playlistItem),
        ]
    );
}
