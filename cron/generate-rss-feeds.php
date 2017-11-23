<?php

require_once __DIR__ . '/../bootstrap.php';

use Suin\RSSWriter\Channel;
use Suin\RSSWriter\Feed;
use Suin\RSSWriter\Item;

/**
 * Helper function to output each feed
 *
 * @param Feed   $feed
 * @param string $folder
 *
 * @return boolean
 */
$buildFeed = function (Feed $feed, $folder, $name = 'rss') {
    $tempFeed = __DIR__ . "/../../web/public/{$folder}/{$name}-new.xml";
    $finalFeed = __DIR__ . "/../../web/public/{$folder}/{$name}.xml";

    $feedHandle = fopen($tempFeed, 'w');
    fwrite($feedHandle, $feed->render());
    fclose($feedHandle);

    rename($tempFeed, $finalFeed);
};


/*********************************************
 * First the blog posts
 *********************************************/
$blogPostFeed = new Feed();

$blogPostChannel = new Channel();
$blogPostChannel->title('Jacob Emerick | Blog Feed');
$blogPostChannel->description('Most recent blog entries of Jacob Emerick, a software engineer that hikes.');
$blogPostChannel->url('https://blog.jacobemerick.com'); // todo depends on env
$blogPostChannel->appendTo($blogPostFeed);

$query = "
    SELECT *
    FROM `jpemeric_blog`.`post`
    WHERE `display` = :is_active
    ORDER BY `date` DESC";
$bindings = [
    'is_active' => 1,
];

$activeBlogPosts = $db->getRead()->fetchAll($query, $bindings);

foreach ($activeBlogPosts as $blogPost) {
    $blogPostItem = new Item();

    $blogPostItem->title($blogPost['title']);

    $url = "https://blog.jacobemerick.com/{$blogPost['category']}/{$blogPost['path']}/";
    $blogPostItem->url($url);
    $blogPostItem->guid($url, true);

    $description = $blogPost['body'];
    $description = strip_tags($description);
    $description = strtok($description, "\n");
    if (strlen($description) > 250) {
        $description = wordwrap($description, 250);
        $description = strtok($description, "\n");
        if (substr($description, -1) != '.') {
            $description .= '&hellip;';
        }
    }
    $description = html_entity_decode($description);
    $blogPostItem->description($description);

    $categoryUrl = "https://blog.jacobemerick.com/{$blogPost['category']}/";
    $blogPostItem->category($blogPost['category'], $categoryUrl);

    $pubDate = new DateTime($blogPost['date']);
    $blogPostItem->pubDate($pubDate->getTimestamp());

    if (preg_match('@{{photo="([^\/]+)/([^\.]+).([^"]+)"}}@', $blogPost['body'], $match) == 1) {
        $photoPath = "/photo/{$match[1]}/{$match[2]}-size-large.{$match[3]}";

        $photoInternalPath = __DIR__ . '/../../web/public' . $photoPath;
        $photoSize = filesize($photoInternalPath);

        /**
         * ugh, remote host does not have pecl fileinfo
         *
         * $fInfo = new finfo(FILEINFO_MIME_TYPE);
         * $photoType = $fInfo->file($photoInternalPath);
         * unset($fInfo);
         **/
        $photoType = 'image/jpeg';

        $blogPostItem->enclosure("https://blog.jacobemerick.com{$photoPath}", $photoSize, $photoType);
    }

    $blogPostItem->appendTo($blogPostChannel);
}

$buildFeed($blogPostFeed, 'blog');

/*********************************************
 * Then the blog comments
 *********************************************/
$blogCommentFeed = new Feed();

$blogCommentChannel = new Channel();
$blogCommentChannel->title('Jacob Emerick | Blog Comment Feed');
$blogCommentChannel->description('Most recent comments on blog posts of Jacob Emerick');
$blogCommentChannel->url('https://blog.jacobemerick.com'); // todo depends on env
$blogCommentChannel->appendTo($blogCommentFeed);

$client = new GuzzleHttp\Client([
    'base_uri' => $config->comments->host,
    'timeout' => $config->comments->timeout,
    'auth' => [
        $config->comments->user,
        $config->comments->password,
    ],
]);

$page = 1;
$activeBlogComments = [];

while (true) {
    try {
        $response = $client->get('/comments', [
            'query' => [
                'domain' => 'blog.jacobemerick.com',
                'page' => $page,
                'per_page' => 50,
                'order' => '-date',
            ],
        ]);
    } catch (Exception $e) {
        $logger->addError($e->getMessage());
        exit();
    }

    $comments = (string) $response->getBody();
    $comments = json_decode($comments);

    if (empty($comments)) {
        break;
    }

    $activeBlogComments = array_merge($activeBlogComments, $comments);
    $page++;
}

$titles = [];

$query = "
    SELECT `path`, `category`, `title`
    FROM `jpemeric_blog`.`post`
    WHERE `display` = :is_active";
$bindings = [
    'is_active' => 1,
];
$posts = $db->getRead()->fetchAll($query, $bindings);

foreach ($posts as $post) {
    $titles["/{$post['category']}/{$post['path']}/"] = $post['title'];
}
 
foreach ($activeBlogComments as $blogComment) {
    $blogCommentItem = new Item();

    $slug = parse_url($blogComment->url);
    $title = $titles[$slug['path']];
    if (empty($title)) {
        $logger->addError("No post could be found for {$blogComment->url}");
        exit();
    }

    $blogCommentItem->title("Comment on '{$title}' from {$blogComment->commenter->name}");
    $blogCommentItem->url($blogComment->url);
    $blogCommentItem->guid($blogComment->url, true);

    $description = $blogComment->body;
    $description = strip_tags($description);
    $description = strtok($description, "\r\n");
    if (strlen($description) > 250) {
        $description = wordwrap($description, 250);
        $description = strtok($description, "\n");
        if (substr($description, -1) != '.') {
            $description .= '&hellip;';
        }
    }
    $description = html_entity_decode($description);
    $description = trim($description);
    $blogCommentItem->description($description);

    $pubDate = new DateTime($blogComment->date);
    $blogCommentItem->pubDate($pubDate->getTimestamp());

    $blogCommentItem->appendTo($blogCommentChannel);
}

$buildFeed($blogCommentFeed, 'blog', 'rss-comments');
