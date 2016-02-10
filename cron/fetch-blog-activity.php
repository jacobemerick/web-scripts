<?php

require_once __DIR__ . '/../bootstrap.php';

$mostRecentBlogDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`blog` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentBlogDateTime = new DateTime($mostRecentBlogDateTime);

$blogFeed = Feed::loadRss('http://blog.jacobemerick.com/rss.xml');
foreach ($blogFeed->item as $item) {
    $dateTime = new DateTime($item->pubDate);
    if ($dateTime <= $mostRecentBlogDateTime) {
        // break;
    }

    $uniqueBlogCheck = $db->getRead()->fetchValue(
        "SELECT 1 FROM `jpemeric_stream`.`blog` WHERE `permalink` = :guid LIMIT 1",
        ['guid' => (string) $item->guid]
    );
    if ($uniqueBlogCheck !== false) {
        continue;
    }

    $dateTime->setTimezone(new DateTimeZone('America/Phoenix'));
    $metadata = json_decode(json_encode($item), true);

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`blog` (`permalink`, `datetime`, `metadata`) " . 
        "VALUES (:permalink, :datetime, :metadata)",
        [
            'permalink' => (string) $item->guid,
            'datetime' => $dateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($metadata),
        ]
    );
}

$mostRecentBlogCommentDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`blog_comment` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentBlogCommentDateTime = new DateTime($mostRecentBlogCommentDateTime);

$commentFeed = Feed::loadRss('http://blog.jacobemerick.com/rss-comments.xml');
foreach ($commentFeed->item as $item) {
    $dateTime = new DateTime($item->pubDate);
    if ($dateTime <= $mostRecentBlogCommentDateTime) {
        break;
    }

    $uniqueBlogCommentCheck = $db->getRead()->fetchValue(
        "SELECT 1 FROM `jpemeric_stream`.`blog_comment` WHERE `permalink` = :guid LIMIT 1",
        ['guid' => (string) $item->guid]
    );
    if ($uniqueBlogCommentCheck !== false) {
        continue;
    }

    $dateTime->setTimezone(new DateTimeZone('America/Phoenix'));
    $metadata = json_decode(json_encode($item), true);

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`blog_comment` (`permalink`, `datetime`, `metadata`) " .
        "VALUES (;permalink, :datetime, :metadata)",
        [
            'permalink' => (string) $item->guid,
            'datetime' => $dateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($metadata),
        ]
    );
}
