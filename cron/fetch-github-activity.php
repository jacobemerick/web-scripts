<?php

require_once __DIR__ . '/../bootstrap.php';

$client = new Github\Client();
$client->authenticate(
    $config->github->client_id,
    $config->github->client_secret,
    Github\Client::AUTH_URL_CLIENT_ID
);

/*********************************************
 * get changelog for jacobemerick/web
 *********************************************/
$mostRecentChangeDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`changelog` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentChangeDateTime = new DateTime($mostRecentChangeDateTime);

$parameters = [
    'sha' => 'master',
    'since' => $mostRecentChangeDateTime->format('c'),
];
$commits = $client->api('repo')->commits()->all('jacobemerick', 'web', $parameters);

foreach ($commits as $commit) {
    $uniqueChangeCheck = $db->getRead()->fetchValue(
        "SELECT 1 FROM `jpemeric_stream`.`changelog` WHERE `hash` = :hash LIMIT 1",
        ['hash' => $commit['sha']]
    );
    if ($uniqueChangeCheck !== false) {
        continue;
    }

    $messageShort = $commit['commit']['message'];
    $messageShort = strtok($messageShort, "\n");
    if (strlen($messageShort) > 72) {
        $messageShort = wordwrap($messageShort, 65);
        $messageShort = strtok($messageShort, "\n");
        $messageShort .= '...';
    }

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`changelog` " .
        "(`hash`, `message`, `messageShort`, `datetime`, `author`, `commit_link`) " .
        "VALUES (:hash, :message, :messageShort, :datetime, :author, :commit_link)",
        [
            'hash' => $commit['sha'],
            'message' => $commit['commit']['message'],
            'messageShort' => $messageShort,
            'datetime' => $mostRecentChangeDateTime->format('Y-m-d H:i:s'),
            'author' => $commit['commit']['author']['name'],
            'commit_link' => $commit['html_url'],
        ]
    );
}

/*********************************************
 * get activity for jacobemerick
 *********************************************/
$supportedEventTypes = [
    'CreateEvent',
    'ForkEvent',
    'PullRequestEvent',
    'PushEvent',
];

$mostRecentEventDateTime = $db->getRead()->fetchValue(
    "SELECT `datetime` FROM `jpemeric_stream`.`github` ORDER BY `datetime` DESC LIMIT 1"
);
$mostRecentEventDateTime = new DateTime($mostRecentEventDateTime);

$events = $client->api('user')->publicEvents('jacobemerick');

foreach ($events as $event) {
    $eventDateTime = new DateTime($event['created_at']);
    if ($eventDateTime <= $mostRecentEventDateTime) {
        break;
    }

    if (!in_array($event['type'], $supportedEventTypes)) {
        continue;
    }

    $uniqueEventCheck = $db->getRead()->fetchValue(
        "SELECT 1 FROM `jpemeric_stream`.`github` WHERE `event_id` = :event_id LIMIT 1",
        ['event_id' => $event['id']]
    );
    if ($uniqueEventCheck !== false) {
        continue;
    }

    $eventDateTime->setTimezone($container['default_timezone']);

    $db->getWrite()->perform(
        "INSERT INTO `jpemeric_stream`.`github` (`event_id`, `type`, `datetime`, `metadata`) " .
        "VALUES (:event_id, :event_type, :datetime, :metadata)",
        [
            'event_id' => $event['id'],
            'event_type' => $event['type'],
            'datetime' => $eventDateTime->format('Y-m-d H:i:s'),
            'metadata' => json_encode($event),
        ]
    );
}
