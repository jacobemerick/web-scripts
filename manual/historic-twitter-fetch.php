<?php

require_once __DIR__ . '/../bootstrap.php';

$options = getopt('f:');
if (empty($options['f'])) {
    exit('Must pass in a file with the f parameter.');
}

$client = new Abraham\TwitterOAuth\TwitterOAuth(
    $config->twitter->consumer_key,
    $config->twitter->consumer_secret,
    $config->twitter->access_token,
    $config->twitter->access_token_secret
);
$client->setDecodeJsonAsArray(true);

$idList = [];

$handle = fopen(__DIR__ . '/' . $options['f'], 'r');
while ($row = fgets($handle)) {
    array_push($idList, trim($row));
    if (count($idList) == 100) {
        $tweetLookup = $client->get('statuses/lookup', [
            'id' => implode(',', $idList),
            'trim_user' => true,
        ]);

        foreach ($tweetLookup as $tweet) {
            $uniqueTweetCheck = $db->getRead()->fetchOne(
                "SELECT `metadata` FROM `jpemeric_stream`.`twitter` WHERE `tweet_id` = :tweet_id LIMIT 1",
                ['tweet_id' => $tweet['id_str']]
            );
            if ($uniqueTweetCheck !== false) {
                if ($uniqueTweetCheck['metadata'] != json_encode($tweet)) {
                    $db->getWrite()->perform(
                        "UPDATE `jpemeric_stream`.`twitter` SET `metadata` = :metadata WHERE `tweet_id` = :tweet_id",
                        [
                            'metadata' => json_encode($uniqueTweetCheck['metadata']),
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
                    'datetime' => $dateTime->format('y-m-d h:i:s'),
                    'metadata' => json_encode($uniqueTweetCheck['metadata']),
                ]
            );
        }
        $idList = [];
    }
}
fclose($handle);
