<?php

require_once __DIR__ . '/../bootstrap.php';

$client = new GuzzleHttp\Client(['base_uri' => 'http://www.goodreads.com']);

$mostRecentReviewDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`goodread` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentReviewDateTime = new DateTime($mostRecentReviewDateTime);

$response = $client->get("/review/list_rss/{$config->goodread->shelf_id}");
$reviews = (string) $response->getBody();
$reviews = simplexml_load_string($reviews, 'SimpleXMLElement', LIBXML_NOCDATA);

foreach ($reviews->channel->item as $review) {
    $dateTime = new DateTime((string) $review->pubDate);
    if ($dateTime <= $mostRecentReviewDateTime) {
        break;
    }

    $uniqueReviewCheck = $db->getRead()->fetchOne(
        "SELECT `metadata` FROM `jpemeric_stream`.`goodread` WHERE `permalink` = :guid LIMIT 1",
        ['guid' => (string) $review->guid]
    );
    if ($uniqueReviewCheck !== false) {
        if ($uniqueReviewCheck['metadata'] != json_encode($review)) {
            $db->getWrite()->perform(
                "UPDATE `jpemeric_stream`.`goodread` SET `metadata` = :metadata WHERE `permalink` = :guid",
                [
                    'metadata' => json_encode($review),
                    'guid' => (string) $review->guid,
                ]
            );
        }
        continue;
    }

    $dateTime->setTimezone(new DateTimeZone('America/Phoenix'));

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`goodread` (`permalink`, `book_id`, `datetime`, `metadata`) " . 
        "VALUES (:permalink, :book_id, :datetime, :metadata)",
        [
            'permalink' => (string) $review->pubData,
            'book_id' => (string) $review->book_id,
            'datetime' => $dateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($review),
        ]
    );
}
