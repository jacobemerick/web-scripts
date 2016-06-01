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
if (!$lastBatchLimit) {
    $lastBatchLimit = 0;
}

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
if ($commentCount < 1) {
    $logger->addInfo("Found no new comments to import, exiting early");
    exit;
}
$logger->addInfo("Found {$commentCount} comments to import, starting from ID: {$lastBatchLimit}.");

foreach ($commentBatch as $comment) {
    $query = "
        SELECT `id` FROM `comment_service`.`commenter`
        WHERE `name` = :name AND `email` = :email AND `website` = :website
        LIMIT 1";
    $bindings = [
        'name' => $comment['name'],
        'email' => $comment['email'],
        'website' => $comment['url'],
    ];
    $commenterId = $commentDB->fetchValue($query, $bindings);
    if (!$commenterId) {
        $query = "
            INSERT INTO `comment_service`.`commenter` (`name`, `email`, `website`, `is_trusted`)
            VALUES (:name, :email, :website, :is_trusted)";
        $bindings = [
            'name' => $comment['name'],
            'email' => $comment['email'],
            'website' => $comment['url'],
            'is_trusted' => $comment['trusted'],
        ];
        if (!$commentDB->perform($query, $bindings)) {
            $logger->addError('Could not insert commenter - exiting out');
            exit;
        }
        $commenterId = $commentDB->lastInsertId();
    }

    $query = "
        INSERT INTO `comment_service`.`comment_body` (`body`)
        VALUES (:body)";
    $bindings = [
        'body' => $comment['body'],
    ];
    if (!$commentDB->perform($query, $bindings)) {
        $logger->addError('Could not insert comment_body - exiting out');
        exit;
    }
    $bodyId = $commentDB->lastInsertId();

    $domain = ($comment['site'] == 2 ? 'blog.jacobemerick.com' : 'waterfallsofthekeweenaw.com');
    $query = "
        SELECT `id` FROM `comment_service`.`comment_domain`
        WHERE `domain` = :domain LIMIT 1";
    $bindings = [
        'domain' => $domain,
    ];
    $domainId = $commentDB->fetchValue($query, $bindings);
    if (!$domainId) {
        $query = "
            INSERT INTO `comment_service`.`comment_domain` (`domain`)
            VALUES (:domain)";
        $bindings = [
            'domain' => $domain,
        ];
        if (!$commentDB->perform($query, $bindings)) {
            $logger->addError('Could not insert comment_domain - exiting out');
            exit;
        }
        $domainId = $commentDB->lastInsertId();
    }

    if ($comment['site'] == 2) {
        $query = "
            SELECT `category` FROM `jpemeric_blog`.`post`
            WHERE `path` = :path LIMIT 1";
        $bindings = [
            'path' => $comment['path'],
        ];
        $category = $db->getRead()->fetchValue($query, $bindings);
        if (!$category) {
            $logger->addError("Could not find post for path {$comment['path']}");
            exit;
        }
        $path = "{$category}/{$comment['path']}";
    } else if (!strstr('/', $comment['path'])) {
        $path = "journal/{$comment['path']}";
    } else {
        $path = $comment['path'];
    }

    $query = "
        SELECT `id` FROM `comment_service`.`comment_path`
        WHERE `path` = :path LIMIT 1";
    $bindings = [
        'path' => $path,
    ];
    $pathId = $commentDB->fetchValue($query, $bindings);
    if (!$pathId) {
        $query = "
            INSERT INTO `comment_service`.`comment_path` (`path`)
            VALUES (:path)";
        $bindings = [
            'path' => $path,
        ];
        if (!$commentDB->perform($query, $bindings)) {
            $logger->addError('Could not insert comment_path - exiting out');
            exit;
        }
        $pathId = $commentDB->lastInsertId();
    }

    $query = "
        SELECT `id` FROM `comment_service`.`comment_thread`
        WHERE `thread` = :thread LIMIT 1";
    $bindings = [
        'thread' => 'comments',
    ];
    $threadId = $commentDB->fetchValue($query, $bindings);
    if (!$threadId) {
        $query = "
            INSERT INTO `comment_service`.`comment_thread` (`thread`)
            VALUES (:thread)";
        $bindings = [
            'thread' => 'comments',
        ];
        if (!$commentDB->perform($query, $bindings)) {
            $logger->addError('Could not insert comment_thread - exiting out');
            exit;
        }
        $threadId = $commentDB->lastInsertId();
    }

    $query = "
        SELECT `id` FROM `comment_service`.`comment_location`
        WHERE `domain` = :domain AND `path` = :path AND `thread` = :thread
        LIMIT 1";
    $bindings = [
        'domain' => $domainId,
        'path' => $pathId,
        'thread' => $threadId,
    ];
    $locationId = $commentDB->fetchValue($query, $bindings);
    if (!$locationId) {
        $query = "
            INSERT INTO `comment_service`.`comment_location` (`domain`, `path`, `thread`)
            VALUES (:domain, :path, :thread)";
        $bindings = [
            'domain' => $domainId,
            'path' => $pathId,
            'thread' => $threadId,
        ];
        if (!$commentDB->perform($query, $bindings)) {
            $logger->addError('Could not insert comment_location - exiting out');
            exit;
        }
        $locationId = $commentDB->lastInsertId();
    }

    $url = ($comment['site'] == 2 ? 'blog.jacobemerick.com' : 'www.waterfallsofthekeweenaw.com');
    $url = "https://{$url}/{$path}/#comment-{$comment['id']}";
    $query = "
        INSERT INTO `comment_service`.`comment` (`id`, `commenter`, `comment_body`, `comment_location`,
                                                 `comment_request`, `url`, `notify`, `display`, `create_time`)
        VALUES (:id, :commenter, :body, :location, :request, :url, :notify, :display, :create_time)";
    $bindings = [
        'id' => $comment['id'],
        'commenter' => $commenterId,
        'body' => $bodyId,
        'location' => $locationId,
        'request' => 0,
        'url' => $url,
        'notify' => $comment['notify'],
        'display' => $comment['display'],
        'create_time' => $comment['date'],
    ];
    if (!$commentDB->perform($query, $bindings)) {
        $logger->addError('Could not insert into comment - exiting out');
        exit;
    }
}

$processTime = microtime(true) - $startTime;
$logger->addInfo("Finished script, total time {$processTime} s.");
