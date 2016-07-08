<?php

require_once __DIR__ . '/../bootstrap.php';

$client = new Abraham\TwitterOAuth\TwitterOAuth(
    $config->twitter->consumer_key,
    $config->twitter->consumer_secret,
    $config->twitter->access_token,
    $config->twitter->access_token_secret
);
$client->setDecodeJsonAsArray(true);

try {
    $recentTweets = $client->get('statuses/user_timeline', [
        'screen_name' => 'jpemeric',
        'count' => 50,
        'trim_user' => true,
    ]);
} catch (Exception $e) {
    $logger->addError($e->getMessage());
    exit();
}

if (isset($recentTweets['errors'])) {
    $logger->addError($recentTweets['errors'][0]['message']);
    exit();
}

foreach ($recentTweets as $tweet) {
    $uniqueTweetCheck = $db->getRead()->fetchOne(
        "SELECT `metadata` FROM `jpemeric_stream`.`twitter` WHERE `tweet_id` = :tweet_id LIMIT 1",
        ['tweet_id' => $tweet['id_str']]
    );
    if ($uniqueTweetCheck !== false) {
        if ($uniqueTweetCheck['metadata'] != json_encode($tweet)) {
            $db->getWrite()->perform(
                "UPDATE `jpemeric_stream`.`twitter` SET `metadata` = :metadata WHERE `tweet_id` = :tweet_id",
                [
                    'metadata' => json_encode($tweet),
                    'tweet_id' => $tweet['id_str'],
                ]
            );
        }
        continue;
    }

    $dateTime = new DateTime($tweet['created_at']);
    $dateTime->setTimezone(new DateTimeZone('America/Phoenix'));

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`twitter` (`tweet_id`, `datetime`, `metadata`) " .
        "VALUES (:tweet_id, :datetime, :metadata)",
        [
            'tweet_id' => $tweet['id_str'],
            'datetime' => $dateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($tweet),
        ]
    );
}
