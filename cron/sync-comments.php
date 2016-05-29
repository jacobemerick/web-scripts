<?php

$startTime = microtime(true);

require_once __DIR__ . '/../bootstrap.php';

$commentDBConfig = $config->database->comment;

$commentDB = new Aura\Sql\ExtendedPdo(
    "mysql:host={$commentDBConfig->host}",
    $commentDBConfig->user,
    $commentDBConfig->password
);

$lastBatchLimit = $commentDB->fetchValue("
    SELECT `comment`.`id`
    FROM `comment_service`.`comment`
    ORDER BY `id` DESC
    LIMIT 1");

$commentBatch = $db->getRead()->fetchAll("
    SELECT
        `comment_meta`.`id`,
        `comment`.`body`,
        `comment`.`body_format`,
        `commenter`.`name`,
        `commenter`.`email`,
        `commenter`.`url`,
        `commenter`.`trusted`,
        `comment_page`.`site`,
        `comment_page`.`path`,
        `comment_meta`.`reply`,
        `comment_meta`.`notify`,
        `comment_meta`.`date`,
        `comment_meta`.`display`
    FROM `jpemeric_comment`.`comment_meta`
    INNER JOIN `jpemeric_comment`.`comment` ON `comment`.`id` = `comment_meta`.`comment`
    INNER JOIN `jpemeric_comment`.`commenter` ON `commenter`.`id` = `comment_meta`.`commenter`
    INNER JOIN `jpemeric_comment`.`comment_page` ON `comment_page`.`id` = `comment_meta`.`comment_page`
    WHERE `comment_meta`.`id` > :last_batch_limit
    ORDER BY `id` ASC LIMIT 100",
    [
        'last_batch_limit' => $lastBatchLimit,
    ]);

$commentCount = count($commentBatch);
$logger->addInfo("Found {$commentCount} comments to import, starting from ID: {$lastBatchLimit}.\n");
foreach ($commentBatch as $comment) {
    // todo insert
}

$processTime = microtime(true) - $startTime;
$logger->addInfo("Finished script, total time {$processTime} ms.\n");
