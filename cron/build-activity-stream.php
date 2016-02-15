<?php

require_once __DIR__ . '/../bootstrap.php';

// common functions that really should belong in a data layer
$getActivityLastUpdateByType = function ($type) use ($db) {
    $query = "
        SELECT *
        FROM `jpemeric_stream`.`activity`
        WHERE `type` = :type
        ORDER BY `updated_at` DESC
        LIMIT 1";
    $bindings = [
        'type' => $type,
    ];

    return $db->getRead()->fetchOne($query, $bindings);
};

$getActivityByTypeId = function ($type, $typeId) use ($db) {
    $query = "
        SELECT *
        FROM `jpemeric_stream`.`activity`
        WHERE `type` = :type && `type_id` = :type_id
        LIMIT 1";
    $bindings = [
        'type'    => $type,
        'type_id' => $typeId,
    ];

    return $db->getRead()->fetchOne($query, $bindings);
};

$insertActivity = function (
    $message,
    $messageLong,
    DateTime $dateTime,
    array $metadata,
    $type,
    $typeId
) use ($db) {
    $query = "
        INSERT INTO `jpemeric_stream`.`activity`
            (`message`, `message_long`, `datetime`, `metadata`, `type`, `type_id`)
        VALUES
            (:message, :message_long, :datetime, :metadata, :type, :type_id)";
    $bindings = [
        'message' => $message,
        'message_long' => $messageLong,
        'datetime' => $datetime->format('Y-m-d H:i:s'),
        'metadata' => json_encode($metadata),
        'type' => $type,
        'type_id' => $typeId,
    ];

    return $db->getWrite()->perform($query, $bindings);
};

$updateActivityMetadata = function ($activityId, array $metadata) use ($db) {
    $query = "
        UPDATE `jpemeric_stream`.`activity`
        SET `metadata` = :metadata
        WHERE `id` = :id";
    $bindings = [
        'metadata' => json_encode($metadata),
        'id' => $activityId,
    ];

    return $db->getWrite()->perform($query, $bindings);
};


// blog
$lastBlogActivity = $getActivityLastUpdateByType('blog');
if ($lastBlogActivity === false) {
    $lastBlogActivityDateTime = new DateTime('2008-05-03');
} else {
    $lastBlogActivityDateTime = new DateTime($lastBlogActivity['updated_at']);
    $lastBlogActivityDateTime->modify('-5 days');
}

$query = "
    SELECT *
    FROM `jpemeric_stream`.`blog`
    WHERE `updated_at` >= :last_update";
$bindings = [
    'last_update' => $lastBlogActivityDateTime->format('Y-m-d H:i:s'),
];

$newBlogActivity = $db->getRead()->fetchAll($query, $bindings);

foreach ($newBlogActivity as $blog) {
    $uniqueBlogCheck = $getActivityByTypeId('blog', $blog['id']);
    if ($uniqueBlogCheck !== false) {
        continue;
    }

    $blogData = json_decode($blog['metadata'], true);
    $message = sprintf(
        'Blogged about %s | %s.',
        str_replace('-', ' ', $blogData['category']),
        $blogData['title']
    );

    if (isset($blogData['enclosure'])) {
        $messageLong = sprintf(
            "<img src=\"%s\" alt=\"Blog | %s\" />\n" .
            "<h4><a href=\"%s\" title=\"Jacob Emerick's Blog | %s\">%s</a></h4>\n" .
            "<p>%s [<a href=\"%s\">read more</a></a>]</p>",
            $blogData['enclosure']['@attributes']['url'],
            $blogData['title'],
            $blogData['link'],
            $blogData['title'],
            $blogData['title'],
            htmlentities($blogData['description']),
            $blogData['link']
        );
    } else {
        $messageLong = sprintf(
            "<h4><a href=\"%s\" title=\"Jacob Emerick's Blog | %s\">%s</a></h4>\n" .
            "<p>%s [<a href=\"%s\">read more</a></a>]</p>",
            $blogData['link'],
            $blogData['title'],
            $blogData['title'],
            htmlentities($blogData['description']),
            $blogData['link']
        );
    }

    $insertActivity(
        $message,
        $messageLong,
        (new DateTime($blog['datetime'])),
        [],
        'blog',
        $blog['id']
    );
}

$query = "
    SELECT `id`, `permalink`, `datetime`
    FROM `jpemeric_stream`.`blog_comment`
    ORDER BY `datetime` DESC";

$blogCommentActivity = $db->getRead()->fetchAll($query);

$blogCommentHolder = [];
foreach ($blogCommentActivity as $blogComment) {
    $blogPermalink = $blogComment['permalink'];
    $blogPermalink = explode('#', $blogPermalink);
    $blogPermalink = current($blogPermalink);

    $query = "
        SELECT *
        FROM `jpemeric_stream`.`blog`
        WHERE `permalink` = :permalink
        LIMIT 1";
    $bindings = [
        'permalink' => $blogPermalink,
    ];

    $blog = $db->getRead()->fetchOne($query, $bindings);

    if (!array_key_exists($blog['id'], $blogCommentHolder)) {
        $blogCommentHolder[$blog['id']] = 1;
    } else {
        $blogCommentHolder[$blog['id']]++;
    }
}

foreach ($blogCommentHolder as $blogId => $commentCount) {
    $blogActivity = $getActivityByTypeId('blog', $blogId);
    $blogActivityMetadata = json_decode($blogActivity['metadata'], true);
    if (
        !isset($blogActivityMetadata['comments']) ||
        $blogActivityMetadata['comments'] != $commentCount
    ) {
        $updateActivityMetadata(
            $blogActivity['id'],
            ['comments' => $commentCount]
        );
    }
}


// dailymile
$lastDailyMileActivity = $getActivityLastUpdateByType('distance');
if ($lastDailyMileActivity === false) {
    $lastDailyMileActivityDateTime = new DateTime('2008-05-03');
} else {
    $lastDailyMileActivityDateTime = new DateTime($lastDailyMileActivity['updated_at']);
    $lastDailyMileActivityDateTime->modify('-5 days');
}

$query = "
    SELECT *
    FROM `jpemeric_stream`.`dailymile`
    WHERE `updated_at` >= :last_update";
$bindings = [
    'last_update' => $lastDailyMileActivityDateTime->format('Y-m-d H:i:s'),
];

$newDailyMileActivity = $db->getRead()->fetchAll($query, $bindings);

foreach ($newDailyMileActivity as $dailyMile) {
    $uniqueDailyMileCheck = $getActivityByTypeId('distance', $dailyMile['id']);
    if ($uniqueDailyMileCheck !== false) {
        continue;
    }

    $dailyMileData = json_decode($dailyMile['metadata'], true);
    if ($dailyMile['type'] == 'Hiking') {
        $message = sprintf(
            '%s %.2f %s and felt %s.',
            'Hiked',
            $dailyMileData['workout']['distance']['value'],
            $dailyMileData['workout']['distance']['units'],
            $dailyMileData['workout']['felt']
        );
        $messageLong = "<p>{$message}</p>";
        if (isset($dailyMileData['workout']['title'])) {
            $messageLong .= "\n<p>I was hiking up around the {$dailyMileData['workout']['title']} area.</p>";
        }
    } else if ($dailyMile['type'] == 'Running') {
        $message = sprintf(
            '%s %.2f %s and felt %s.',
            'Ran',
            $dailyMileData['workout']['distance']['value'],
            $dailyMileData['workout']['distance']['units'],
            $dailyMileData['workout']['felt']
        );
        $messageLong = "<p>{$message}</p>";
        if (isset($dailyMileData['message'])) {
            $messageLong .= "\n<p>Afterwards, I was all like '{$dailyMileData['message']}'.</p>";
        }
    } else if ($dailyMile['type'] == 'Walking') {
        $message = sprintf(
            '%s %.2f %s and felt %s.',
            'Walked',
            $dailyMileData['workout']['distance']['value'],
            $dailyMileData['workout']['distance']['units'],
            $dailyMileData['workout']['felt']
        );
        $messageLong = "<p>{$message}</p>";
        if (isset($dailyMileData['message'])) {
            $messageLong .= "\n<p>{$dailyMileData['message']}</p>";
        }
    } else {
        continue;
    }

    $insertActivity(
        $message,
        $messageLong,
        (new DateTime($dailyMile['datetime'])),
        [],
        'distance',
        $dailyMile['id']
    );
}


// github
$lastGithubActivity = $getActivityLastUpdateByType('github');
if ($lastGithubActivity === false) {
    $lastGithubActivityDateTime = new DateTime('2015-10-01');
} else {
    $lastGithubActivityDateTime = new DateTime($lastGithubActivity['updated_at']);
    $lastGithubActivityDateTime->modify('-5 days');
}

$query = "
    SELECT *
    FROM `jpemeric_stream`.`github`
    WHERE `updated_at` >= :last_update";
$bindings = [
    'last_update' => $lastGithubActivityDateTime->format('Y-m-d H:i:s'),
];

$newGithubActivity = $db->getRead()->fetchAll($query, $bindings);

foreach ($newGithubActivity as $github) {
    $uniqueGithubCheck = $getActivityByTypeId('github', $github['id']);
    if ($uniqueGithubCheck !== false) {
        continue;
    }

    $githubData = json_decode($github['metadata'], true);

    if ($github['type'] == 'CreateEvent') {
        if (
            $githubData['payload']['ref_type'] == 'branch' ||
            $githubData['payload']['ref_type'] == 'tag'
        ) {
            $message = sprintf(
                'Created %s %s at %s.',
                $githubData['payload']['ref_type'],
                $githubData['payload']['ref'],
                $githubData['repo']['name']
            );
            $messageLong = sprintf(
                '<p>Created %s %s at <a href="%s" target="_blank" title="Github | %s">%s</a>.</p>',
                $githubData['payload']['ref_type'],
                $githubData['payload']['ref'],
                "https://github.com/{$githubData['repo']['name']}",
                $githubData['repo']['name'],
                $githubData['repo']['name']
            );
        } else if ($githubData['payload']['ref_type'] == 'repository') {
            $message = sprintf(
                'Created %s %s.',
                $githubData['payload']['ref_type'],
                $githubData['repo']['name']
            );
            $messageLong = sprintf(
                '<p>Created %s <a href="%s" target="_blank" title="Github | %s">%s</a>.</p>',
                $githubData['payload']['ref_type'],
                "https://github.com/{$githubData['repo']['name']}",
                $githubData['repo']['name'],
                $githubData['repo']['name']
            );
        } else {
            continue;
        }
    } else if ($github['type'] == 'ForkEvent') {
        $message = sprintf(
            'Forked %s to %s',
            $githubData['repo']['name'],
            $githubData['payload']['forkee']['full_name']
        );
        $messageLong = sprintf(
            '<p>Forked <a href="%s" target="_blank" title="Github | %s">%s</a> to <a href="%s" target="_blank" title="Github | %s">%s</a>.',
            "https://github.com/{$githubData['repo']['name']}",
            $githubData['repo']['name'],
            $githubData['repo']['name'],
            $githubData['payload']['forkee']['html_url'],
            $githubData['payload']['forkee']['full_name'],
            $githubData['payload']['forkee']['full_name']
        );
    } else if ($github['type'] == 'PullRequestEvent') {
        $message = sprintf(
            '%s a pull request at %s',
            ucwords($githubData['payload']['action']),
            $githubData['repo']['name']
        );
        $messageLong = sprintf(
            '<p>%s pull request <a href="%s" target="_blank" title="Github | %s PR %d">%d</a> at <a href="%s" target="_blank" title="Github | %s">%s</a>.</p>',
            ucwords($githubData['payload']['action']),
            $githubData['payload']['pull_request']['html_url'],
            $githubData['repo']['name'],
            $githubData['payload']['number'],
            $githubData['payload']['number'],
            "https://github.com/{$githubData['repo']['name']}",
            $githubData['repo']['name'],
            $githubData['repo']['name']
        );
    } else if ($github['type'] == 'PushEvent') {
        $message = sprintf(
            'Pushed some code at %s.',
            $githubData['repo']['name']
        );
        $messageLong = sprintf(
            "<p>Pushed some code at <a href=\"%s\" target=\"_blank\" title=\"Github | %s\">%s</a>.</p>\n",
            $githubData['payload']['ref'],
            "https://github.com/{$githubData['repo']['name']}",
            $githubData['repo']['name'],
            $githubData['repo']['name']
        );
        $messageLong .= "<ul>\n";
        foreach ($githubData['payload']['commits'] as $commit) {
            $messageShort = $commit['message'];
            $messageShort = strtok($messageShort, "\n");
            if (strlen($messageShort) > 72) {
                $messageShort = wordwrap($messageShort, 65);
                $messageShort = strtok($messageShort, "\n");
                $messageShort .= '...';
            }
            $messageLong .= sprintf(
                "<li><a href=\"%s\" target=\"_blank\" title=\"Github | %s\">%s</a> %s.</li>\n",
                "https://github.com/{$githubData['repo']['name']}/commit/{$commit['sha']}",
                substr($commit['sha'], 0, 7),
                substr($commit['sha'], 0, 7),
                $messageShort
            );
        }
        $messageLong .= "</ul>";
    }

    $insertActivity(
        $message,
        $messageLong,
        (new DateTime($github['datetime'])),
        [],
        'github',
        $github['id']
    );
}


// goodread
$lastGoodreadActivity = $getActivityLastUpdateByType('book');
if ($lastGoodreadActivity === false) {
    $lastGoodreadActivityDateTime = new DateTime('2010-08-28');
} else {
    $lastGoodreadActivityDateTime = new DateTime($lastGoodreadActivity['updated_at']);
    $lastGoodreadActivityDateTime->modify('-5 days');
}

$query = "
    SELECT *
    FROM `jpemeric_stream`.`goodread`
    WHERE `updated_at` >= :last_update";
$bindings = [
    'last_update' => $lastGoodreadActivityDateTime->format('Y-m-d H:i:s'),
];

$newGoodreadActivity = $db->getRead()->fetchAll($query, $bindings);

foreach ($newGoodreadActivity as $goodread) {
    $uniqueGoodreadCheck = $getActivityByTypeId('book', $goodread['id']);
    if ($uniqueGoodreadCheck !== false) {
        continue;
    }

    $goodreadData = json_decode($goodread['metadata'], true);

    if (empty($goodreadData['user_read_at'])) {
        continue;
    }

    $message = sprintf(
        'Read %s by %s.',
        $goodreadData['title'],
        $goodreadData['author_name']
    );
    if (isset($goodreadData['book_large_image_url'])) {
        $messageLong = sprintf(
            "<img alt=\"Goodreads | %s\" src=\"%s\" />\n",
            $goodreadData['title'],
            str_replace('http', 'https', $goodreadData['book_large_image_url'])
        );
    }
    $messageLong .= "<p>{$message}</p>";

    $insertActivity(
        $message,
        $messageLong,
        (new DateTime($goodread['datetime'])),
        [],
        'book',
        $goodread['id']
    );
}


// twitter
$lastTwitterActivity = $getActivityLastUpdateByType('twitter');
if ($lastTwitterActivity === false) {
    $lastTwitterActivityDateTime = new DateTime('2010-03-10');
} else {
    $lastTwitterActivityDateTime = new DateTime($lastTwitterActivity['updated_at']);
    $lastTwitterActivityDateTime->modify('-5 days');
}

$query = "
    SELECT *
    FROM `jpemeric_stream`.`twitter`
    WHERE `updated_at` >= :last_update";
$bindings = [
    'last_update' => $lastTwitterActivityDateTime->format('Y-m-d H:i:s'),
];

$newTwitterActivity = $db->getRead()->fetchAll($query, $bindings);

foreach ($newTwitterActivity as $twitter) {
    $twitterData = json_decode($twitter['metadata'], true);

    $uniqueTwitterCheck = $getActivityByTypeId('twitter', $twitter['id']);
    if ($uniqueTwitterCheck !== false) {
        $metadata = [];
        if ($twitterData['favorite_count'] > 0) {
            $metadata['favorites'] = $twitterData['favorite_count'];
        }
        if ($twitterData['retweet_count'] > 0) {
            $metadata['retweets'] = $twitterData['retweet_count'];
        }

        $updateActivityMetadata($uniqueTwitterCheck['id'], $metadata);
        continue;
    }

    if (
        ($twitterData['in_reply_to_user_id'] != null || substr($twitterData['text'], 0, 1) === '@') &&
        $twitterData['favorite_count'] == 0 &&
        $twitterData['retweet_count'] == 0
    ) {
        continue;
    }

    $entityHolder = [];
    foreach ($twitterData['entities'] as $entityType => $entities) {
        foreach ($entities as $entity) {
            if ($entityType == 'urls' || $entityType == 'media') {
                $entityHolder[$entity['indices'][0]] = [
                    'start' => $entity['indices'][0],
                    'end' => $entity['indices'][1],
                    'replace' => "[{$entity['display_url']}]"
                ];
            }
        }
    }

    $message = $twitterData['text'];
    krsort($entityHolder);
    foreach($entityHolder as $entity)
    {
        $message =
            mb_substr($message, 0, $entity['start']) .
            $entity['replace'] .
            mb_substr($message, $entity['end'], null, 'UTF-8');
    }
    $message = mb_convert_encoding($message, 'HTML-ENTITIES', 'UTF-8');
    $message = trim(preg_replace('/\s+/', ' ', $message));
    $message = "Tweeted | {$message}";

    $entityHolder = [];
    foreach ($twitterData['entities'] as $entityType => $entities) {
        foreach ($entities as $entity) {
            if ($entityType == 'hashtags') {
                $replace = sprintf(
                    '<a href="https://twitter.com/search?q=%%23%s&src=hash" rel="nofollow" target="_blank">#%s</a>',
                    $entity['text'],
                    $entity['text']
                );
            } else if ($entityType == 'urls') {
                $replace = sprintf(
                    '<a href="%s" rel="nofollow" target="_blank" title="%s">%s</a>',
                    $entity['url'],
                    $entity['expanded_url'],
                    $entity['display_url']
                );
            } else if ($entityType == 'user_mentions') {
                $replace = sprintf(
                    '<a href="https://twitter.com/%s" rel="nofollow" target="_blank" title="Twitter | %s">@%s</a>',
                    strtolower($entity['screen_name']),
                    $entity['name'],
                    $entity['screen_name']
                );
            } else if ($entityType == 'media') {
                $replace = sprintf(
                    "<img src=\"%s:%s\" alt=\"%s\" height=\"%s\" width=\"%s\" />",
                    $entity['media_url_https'],
                    'large',
                    $entity['display_url'],
                    $entity['sizes']['large']['h'],
                    $entity['sizes']['large']['w']
                );
            } else {
                continue 2;
            }

            $entityHolder[$entity['indices'][0]] = [
                'start' => $entity['indices'][0],
                'end' => $entity['indices'][1],
                'replace' => $replace,
            ];
        }
    }

    $messageLong = $twitterData['text'];
    krsort($entityHolder);
    foreach($entityHolder as $entity)
    {
        $messageLong =
            mb_substr($messageLong, 0, $entity['start']) .
            $entity['replace'] .
            mb_substr($messageLong, $entity['end'], null, 'UTF-8');
    }
    $messageLong = mb_convert_encoding($messageLong, 'HTML-ENTITIES', 'UTF-8');
    $messageLong = nl2br($messageLong, true);
    $messageLong = "<p>{$messageLong}</p>";

    $metadata = [];
    if ($twitterData['favorite_count'] > 0) {
        $metadata['favorites'] = $twitterData['favorite_count'];
    }
    if ($twitterData['retweet_count'] > 0) {
        $metadata['retweets'] = $twitterData['retweet_count'];
    }

    $insertActivity(
        $message,
        $messageLong,
        (new DateTime($twitter['datetime'])),
        $metadata,
        'twitter',
        $twitter['id']
    );
}


// youtube
$lastYouTubeActivity = $getActivityLastUpdateByType('youtube');
if ($lastYouTubeActivity === false) {
    $lastYouTubeActivityDateTime = new DateTime('2010-08-28');
} else {
    $lastYouTubeActivityDateTime = new DateTime($lastYouTubeActivity['updated_at']);
    $lastYouTubeActivityDateTime->modify('-5 days');
}

$query = "
    SELECT *
    FROM `jpemeric_stream`.`youtube`
    WHERE `updated_at` >= :last_update";
$bindings = [
    'last_update' => $lastYouTubeActivityDateTime->format('Y-m-d H:i:s'),
];

$newYouTubeActivity = $db->getRead()->fetchAll($query, $bindings);

foreach ($newYouTubeActivity as $youTube) {
    $uniqueYouTubeCheck = $getActivityByTypeId('youtube', $youTube['id']);
    if ($uniqueYouTubeCheck !== false) {
        continue;
    }

    $youTubeData = json_decode($youTube['metadata'], true);

    $message = sprintf(
        'Favorited %s on YouTube.',
        $youTubeData['snippet']['title']
    );
    $messageLong = sprintf(
        "<iframe src=\"%s\" frameborder=\"0\" allowfullscreen></iframe>\n" .
        "<p>Favorited <a href=\"%s\" target=\"_blank\" title=\"YouTube | %s\">%s</a> on YouTube.</p>",
        "https://www.youtube.com/embed/{$youTubeData['contentDetails']['videoId']}",
        "https://youtu.be/{$youTubeData['contentDetails']['videoId']}",
        $youTubeData['snippet']['title'],
        $youTubeData['snippet']['title']
    );

    $insertActivity(
        $message,
        $messageLong,
        (new DateTime($youTube['datetime'])),
        [],
        'youtube',
        $youTube['id']
    );
}
