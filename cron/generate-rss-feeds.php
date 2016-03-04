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

$query = "
    SELECT `comment_meta`.`id`, `comment_meta`.`date`, `comment`.`body`, `commenter`.`name`,
           `post`.`title`, `post`.`category`, `post`.`path`
    FROM `jpemeric_comment`.`comment_meta`
    INNER JOIN `jpemeric_comment`.`comment` ON `comment`.`id` = `comment_meta`.`comment`
    INNER JOIN `jpemeric_comment`.`commenter` ON `commenter`.`id` = `comment_meta`.`commenter` AND
                                                 `commenter`.`trusted` = :trusted_commenter
    INNER JOIN `jpemeric_comment`.`comment_page` ON `comment_page`.`id` = `comment_meta`.`comment_page` AND
                                                    `comment_page`.`site` = :comment_site
    INNER JOIN `jpemeric_blog`.`post` ON `post`.`path` = `comment_page`.`path` AND
                                         `post`.`display` = :display_post
    WHERE `comment_meta`.`display` = :active_comment
    ORDER BY `comment_meta`.`date` DESC";
$bindings = [
    'trusted_commenter' => 1,
    'comment_site' => 2,
    'display_post' => 1,
    'active_comment' => 1,
];

$activeBlogComments = $db->getRead()->fetchAll($query, $bindings);

foreach ($activeBlogComments as $blogComment) {
    $blogCommentItem = new Item();

    $blogCommentItem->title("Comment on '{$blogComment['title']}' from {$blogComment['name']}");

    $url = "https://blog.jacobemerick.com/{$blogComment['category']}/{$blogComment['path']}/";
    $url .= "#comment-{$blogComment['id']}";
    $blogCommentItem->url($url);
    $blogCommentItem->guid($url, true);

    $description = $blogComment['body'];
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
    $description = trim($description);
    $blogCommentItem->description($description);

    $pubDate = new DateTime($blogComment['date']);
    $blogCommentItem->pubDate($pubDate->getTimestamp());

    $blogCommentItem->appendTo($blogCommentChannel);
}

$buildFeed($blogCommentFeed, 'blog', 'rss-comments');
