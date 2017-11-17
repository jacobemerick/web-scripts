<?php

require_once __DIR__ . '/../bootstrap.php';

$client = new GuzzleHttp\Client(['base_uri' => 'https://www.goodreads.com']);

$mostRecentReviewDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`goodread` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentReviewDateTime = new DateTime($mostRecentReviewDateTime);

try {
    $response = $client->get("/review/list_rss/{$config->goodread->shelf_id}");
} catch (Exception $e) {
    $logger->addError($e->getMessage());
    exit();
}

$reviews = (string) $response->getBody();
$reviews = simplexml_load_string($reviews, 'SimpleXMLElement', LIBXML_NOCDATA);

foreach ($reviews->channel->item as $review) {
    $dateTime = new DateTime((string) $review->user_read_at);
    if ($dateTime <= $mostRecentReviewDateTime) {
        break;
    }

    // there seems to be a problem with goodread data source... it lies
    // the user_read_at field is not always reliable
    // this check is to make sure that the user_read_at field is sane
    if ($dateTime <= new DateTime('-24 hours')) {
        continue;
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
            'permalink' => (string) $review->guid,
            'book_id' => (string) $review->book_id,
            'datetime' => $dateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($review),
        ]
    );
}
