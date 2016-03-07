<?php

require_once __DIR__ . '/../bootstrap.php';

use Thepixeldeveloper\Sitemap\Output;
use Thepixeldeveloper\Sitemap\Url;
use Thepixeldeveloper\Sitemap\Urlset;


/**
 * Helper function to build each sitemap
 *
 * @param array  $entries
 * @param string $domain
 * @return boolean
 */
$buildSitemap = function (array $entries, $domain, $folder) {
    $urlSet = new Urlset();
    foreach ($entries as $path => $entry) {
        $url = new Url("{$domain}{$path}"); // todo better detection of domain by env
        $url->setLastMod($entry['lastmod']); // todo check if exists
        $url->setChangeFreq($entry['changefreq']); // todo check if exists
        $url->setPriority($entry['priority']); // todo check if exists
        $urlSet->addUrl($url);
    }

    $output = new Output();
    $output->setIndentString('  '); // change indentation from 4 to 2 spaces

    $tempSitemap = __DIR__ . "/../../web/public/{$folder}/sitemap-new.xml";
    $finalSitemap = __DIR__ . "/../../web/public/{$folder}/sitemap.xml";

    $sitemapHandle = fopen($tempSitemap, 'w');
    fwrite($sitemapHandle, $output->getOutput($urlSet));
    fclose($sitemapHandle);

    rename($tempSitemap, $finalSitemap);
};


/*********************************************
 * blog.jacobemerick.com
 *********************************************/
$reduceToMostRecentBlogPost = function ($recentPost, $post) {
    if (is_null($recentPost)) {
        return $post;
    }
    $postDate = new DateTime($post['date']);
    $recentPostDate = new DateTime($recentPost['date']);
    return ($postDate > $recentPostDate) ? $post: $recentPost;
};

$blogPostsPerPage = 10;

$query = "
    SELECT `id`, `date`, `category`, `path`
    FROM `jpemeric_blog`.`post`
    WHERE `display` = :is_active
    ORDER BY `date` DESC";
$bindings = [
    'is_active' => 1,
];

$activeBlogPosts = $db->getRead()->fetchAll($query, $bindings);
$mostRecentBlogPost = array_reduce($activeBlogPosts, $reduceToMostRecentBlogPost);

// todo these post-level dates should be accurate to H:i:s
$entryArray = [
    '/' => [
        'lastmod' => (new DateTime($mostRecentBlogPost['date']))->format('Y-m-d'),
        'changefreq' => 'daily',
        'priority' => .9,
    ]
];
for ($i = 2; (($i - 1) * $blogPostsPerPage) < count($activeBlogPosts); $i++) {
    $entryKey = "/{$i}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($mostRecentBlogPost['date']))->format('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => .1,
        ]
    ];
}

$blogCategoryArray = [
    'hiking',
    'personal',
    'web-development',
];

foreach ($blogCategoryArray as $blogCategory) {
    $blogCategoryPosts = array_filter($activeBlogPosts, function ($post) use ($blogCategory) {
        return $post['category'] == $blogCategory;
    });
    $mostRecentBlogCategoryPost = array_reduce($blogCategoryPosts, $reduceToMostRecentBlogPost);

    $entryKey = "/{$blogCategory}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($mostRecentBlogCategoryPost['date']))->format('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => .3,
        ]
    ];

    for ($i = 2; (($i - 1) * $blogPostsPerPage) < count($blogCategoryPosts); $i++) {
        $entryKey = "/{$blogCategory}/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime($mostRecentBlogCategoryPost['date']))->format('Y-m-d'),
                'changefreq' => 'daily',
                'priority' => .1,
            ]
        ];
    }
}

$query = "
    SELECT *
    FROM `jpemeric_blog`.`tag`
    ORDER BY `tag`";

$blogTags = $db->getRead()->fetchAll($query);

foreach ($blogTags as $blogTag) {
    $query = "
        SELECT `id`, `title`, `path`, `date`, `body`, `category`
        FROM `jpemeric_blog`.`post`
        INNER JOIN `jpemeric_blog`.`ptlink` ON `ptlink`.`post_id` = `post`.`id` AND
                                               `ptlink`.`tag_id` = :tag_id
        WHERE `display` = :is_active";
    $bindings = [
        'tag_id'    => $blogTag['id'],
        'is_active' => 1,
    ];

    $blogPostsWithTag = $db->getRead()->fetchAll($query, $bindings);

    if (count($blogPostsWithTag) < 1) {
        continue;
    }

    $mostRecentBlogTagPost = array_reduce($blogPostsWithTag, $reduceToMostRecentBlogPost);

    $blogTagPath = str_replace(' ', '-', $blogTag['tag']);
    $entryKey = "/tag/{$blogTagPath}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($mostRecentBlogTagPost['date']))->format('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => .1,
        ]
    ];

    for ($i = 2; (($i - 1) * $blogPostsPerPage) < count($blogPostsWithTag); $i++) {
        $blogTagPath = str_replace(' ', '-', $blogTag['tag']);
        $entryKey = "/tag/{$blogTagPath}/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime($mostRecentBlogTagPost['date']))->format('Y-m-d'),
                'changefreq' => 'daily',
                'priority' => .1,
            ]
        ];
    }
}

$reversedBlogPosts = array_reverse($activeBlogPosts);
foreach ($reversedBlogPosts as $blogPost) {
    $entryKey = "/{$blogPost['category']}/{$blogPost['path']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($blogPost['date']))->format('Y-m-d'), // todo this should be based on comment
            'changefreq' => 'weekly',
            'priority' => .8,
        ],
    ];
}

$entryArray += [
    '/about/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'monthly',
        'priority' => .2,
    ]
];

$buildSitemap($entryArray, 'https://blog.jacobemerick.com', 'blog');


/*********************************************
 * home.jacobemerick.com
 *********************************************/
$query = "
    SELECT `date`
    FROM `jpemeric_blog`.`post`
    WHERE `display` = :is_active
    ORDER BY `date` DESC
    LIMIT 1";
$bindings = [
    'is_active' => 1,
];

$mostRecentPost = $db->getRead()->fetchOne($query, $bindings);

$entryArray = [
    '/' => [
        'lastmod' => (new DateTime($mostRecentPost['date']))->format('Y-m-d'),
        'changefreq' => 'daily',
        'priority' => 1,
    ],
    '/about/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'monthly',
        'priority' => .4,
    ],
    '/contact/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'monthly',
        'priority' => .3,
    ],
];

$buildSitemap($entryArray, 'https://home.jacobemerick.com', 'home');


/*********************************************
 * lifestream.jacobemerick.com
 *********************************************/
$reduceToMostRecentStreamActivity = function ($recentActivity, $activity) {
    if (is_null($recentActivity)) {
        return $activity;
    }
    $activityDate = new DateTime($activity['datetime']);
    $recentActivityDate = new DateTime($recentActivity['datetime']);
    return ($activityDate > $recentActivityDate) ? $activity: $recentActivity;
};

$streamActivitiesPerPage = 15;

$query = "
    SELECT `id`, `datetime`, `type`
    FROM `jpemeric_stream`.`activity`
    ORDER BY `datetime` DESC";

$streamActivities = $db->getRead()->fetchAll($query);
$mostRecentStreamActivity = array_reduce($streamActivities, $reduceToMostRecentStreamActivity);

$entryArray = [
    '/' => [
        'lastmod' => (new DateTime($mostRecentStreamActivity['datetime']))->format('c'),
        'changefreq' => 'hourly',
        'priority' => .9,
    ]
];
for ($i = 2; (($i - 1) * $streamActivitiesPerPage) < count($streamActivities); $i++) {
    $entryKey = "/page/{$i}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($mostRecentStreamActivity['datetime']))->format('c'),
            'changefreq' => 'hourly',
            'priority' => .1,
        ]
    ];
}

$streamTypeArray = [
    'blog',
    'books',
    'distance',
    'github',
    'hulu',
    'twitter',
    'youtube',
];

foreach ($streamTypeArray as $streamType) {
    $streamTypeActivities = array_filter($streamActivities, function ($post) use ($streamType) {
        return $post['type'] == $streamType;
    });
    $mostRecentStreamTypeActivity = array_reduce($streamTypeActivities, $reduceToMostRecentStreamActivity);

    $entryKey = "/{$streamType}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($mostRecentStreamTypeActivity['datetime']))->format('c'),
            'changefreq' => 'hourly',
            'priority' => .3,
        ]
    ];

    for ($i = 2; (($i - 1) * $streamActivitiesPerPage) < count($streamTypeActivities); $i++) {
        $entryKey = "/{$streamType}/page/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime($mostRecentStreamTypeActivity['datetime']))->format('c'),
                'changefreq' => 'hourly',
                'priority' => .1,
            ]
        ];
    }
}

$reversedStreamActivities = array_reverse($streamActivities);
foreach ($reversedStreamActivities as $streamActivity) {
    $entryKey = "/{$streamActivity['type']}/{$streamActivity['id']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($streamActivity['datetime']))->format('c'),
            'changefreq' => 'never',
            'priority' => .5,
        ],
    ];
}

$entryArray += [
    '/about/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'monthly',
        'priority' => .7,
    ]
];

$buildSitemap($entryArray, 'https://lifestream.jacobemerick.com', 'lifestream');


/*********************************************
 * portfolio.jacobemerick.com
 *********************************************/
$query = "
    SELECT `title_url`, `category`
    FROM `jpemeric_portfolio`.`piece`
    WHERE `display` = :is_active
    ORDER BY `date` DESC";
$bindings = [
    'is_active' => 1,
];

$portfolioPieces = $db->getRead()->fetchAll($query, $bindings);

$entryArray = [
    '/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'weekly',
        'priority' => 1,
    ],
    '/print/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'never',
        'priority' => .1,
    ],
    '/web/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'never',
        'priority' => .1,
    ],
    '/contact/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'never',
        'priority' => .4,
    ],
    '/resume/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'yearly',
        'priority' => .9,
    ],
];

foreach ($portfolioPieces as $portfolioPiece) {
    $portfolioCategory = ($portfolioPiece['category'] == 1) ? 'web' : 'print';
    $entryKey = "/{$portfolioCategory}/{$portfolioPiece['title_url']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
            'changefreq' => 'never',
            'priority' => .7,
        ],
    ];
}

$buildSitemap($entryArray, 'https://portfolio.jacobemerick.com', 'portfolio');


/*********************************************
 * site.jacobemerick.com
 *********************************************/
$query = "
    SELECT `datetime`
    FROM `jpemeric_stream`.`changelog`
    ORDER BY `datetime` DESC
    LIMIT 1";

$mostRecentChange = $db->getRead()->fetchOne($query);

$entryArray = [
    '/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'weekly',
        'priority' => 1,
    ],
    '/terms/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'weekly',
        'priority' => .3,
    ],
    '/change-log/' => [
        'lastmod' => (new DateTime($mostRecentChange['datetime']))->format('c'),
        'changefreq' => 'daily',
        'priority' => .1,
    ],
    '/contact/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'weekly',
        'priority' => .6,
    ],
];

$buildSitemap($entryArray, 'https://site.jacobemerick.com', 'site');


/*********************************************
 * www.waterfallofthekeweenaw.com
 *********************************************/
$reduceToMostRecentJournalLog = function ($recentLog, $log) {
    if (is_null($recentLog)) {
        return $log;
    }
    $logDate = new DateTime($log['publish_date']);
    $recentLogDate = new DateTime($recentLog['publish_date']);
    return ($logDate > $recentLogDate) ? log: $recentLog;
};

$query = "
    SELECT `waterfall`.`alias`, `watercourse`.`alias` AS `watercourse_alias`
    FROM `jpemeric_waterfall`.`waterfall`
    INNER JOIN `jpemeric_waterfall`.`watercourse` ON `waterfall`.`watercourse` = `watercourse`.`id`
    WHERE `is_public` = :public
    ORDER BY `alias`, `watercourse_alias`";
$bindings = [
    'public' => 1,
];

$waterfallList = $db->getRead()->fetchAll($query, $bindings);

$entryArray = [
    '/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'daily',
        'priority' => 1,
    ],
    '/falls/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'weekly',
        'priority' => .3,
    ],
];

for ($i = 2; (($i - 1) * 24) < count($waterfallList); $i++) {
    $entryKey = "/falls/{$i}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => .1,
        ]
    ];
}

$query = "
    SELECT `county`.`name`, `county`.`alias`, COUNT(1) AS `count`
    FROM `jpemeric_waterfall`.`county`
    INNER JOIN `jpemeric_waterfall`.`waterfall` ON `waterfall`.`county` = `county`.`id` AND
                                                   `waterfall`.`is_public` = :public
    GROUP BY `alias`
    ORDER BY `name`";
$bindings = [
    'public' => 1,
];

$waterfallCountyList = $db->getRead()->fetchAll($query, $bindings);

foreach ($waterfallCountyList as $waterfallCounty) {
    $entryKey = "/{$waterfallCounty['alias']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
            'changefreq' => 'monthly',
            'priority' => .6
        ]
    ];

    for ($i = 2; (($i - 1) * 12) < $waterfallCounty['count']; $i++) {
        $entryKey = "/{$waterfallCounty['alias']}/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => .1
            ]
        ];
    }
}
$query = "
    SELECT `sum_table`.`name`, `sum_table`.`alias`, SUM(`count`) AS `count`
    FROM ((
        SELECT `watercourse`.`name`, `watercourse`.`alias`, `parent_count`.`count`
        FROM (
            SELECT COUNT(1) AS `count`, `parent` AS `id`
            FROM `jpemeric_waterfall`.`watercourse`
            INNER JOIN `jpemeric_waterfall`.`waterfall` ON `waterfall`.`watercourse` = `watercourse`.`id` AND
                                                           `waterfall`.`is_public` = :public
            WHERE `watercourse`.`parent` <> :no_parent
            GROUP BY `watercourse`.`id`
        ) AS `parent_count`
        INNER JOIN `jpemeric_waterfall`.`watercourse` ON `watercourse`.`id` = `parent_count`.`id` AND
                                                         `watercourse`.`has_page` = :has_page
    ) UNION ALL (
        SELECT `watercourse`.`name`, `watercourse`.`alias`, COUNT(1) AS `count`
        FROM `jpemeric_waterfall`.`watercourse`
        INNER JOIN `jpemeric_waterfall`.`waterfall` ON `waterfall`.`watercourse` = `watercourse`.`id` AND
                                                       `waterfall`.`is_public` = :public
        WHERE `watercourse`.`parent` = :no_parent AND `watercourse`.`has_page` = :has_page
        GROUP BY `watercourse`.`id`
    )) AS `sum_table`
    GROUP BY `alias`
    ORDER BY `name`";
$bindings = [
    'public' => 1,
    'no_parent' => 0,
    'has_page' => 1,
];

$waterfallWatercourseList = $db->getRead()->fetchAll($query, $bindings);

foreach ($waterfallWatercourseList as $waterfallWatercourse) {
    $entryKey = "/{$waterfallWatercourse['alias']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
            'changefreq' => 'monthly',
            'priority' => .6
        ]
    ];

    for ($i = 2; (($i - 1) * 12) < $waterfallWatercourse['count']; $i++) {
        $entryKey = "/{$waterfallWatercourse['alias']}/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => .1
            ]
        ];
    }
}

foreach ($waterfallList as $waterfall) {
    $entryKey = "/{$waterfall['watercourse_alias']}/{$waterfall['alias']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => .8,
        ],
    ];
}

$entryArray += [
    '/map/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'monthly',
        'priority' => .6,
    ]
];

$query = "
    SELECT `alias`, `publish_date` 
    FROM `jpemeric_waterfall`.`log`
    WHERE `is_public` = :public
    ORDER BY `date` DESC";
$bindings = [
    'public' => 1,
];

$activeWaterfallLogs = $db->getRead()->fetchAll($query, $bindings);
$mostRecentWaterfallLog = array_reduce($activeWaterfallLogs, $reduceToMostRecentJournalLog);

$entryArray += [
    '/journal/' => [
        'lastmod' => (new DateTime($mostRecentWaterfallLog['publish_date']))->format('c'),
        'changefreq' => 'weekly',
        'priority' => .3,
    ],
];

for ($i = 2; (($i - 1) * 10) < count($activeWaterfallLogs); $i++) {
    $entryKey = "/journal/{$i}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($mostRecentWaterfallLog['publish_date']))->format('c'),
            'changefreq' => 'weekly',
            'priority' => .1,
        ]
    ];
}

$query = "
    SELECT `companion`.`name`, `companion`.`alias`, COUNT(1) AS `count`
    FROM `jpemeric_waterfall`.`companion`
    INNER JOIN `jpemeric_waterfall`.`log_companion_map` ON `log_companion_map`.`companion` = `companion`.`id`
    INNER JOIN `jpemeric_waterfall`.`log` ON `log`.`id` = `log_companion_map`.`log` AND
                                             `log`.`is_public` = :public
    GROUP BY `alias`
    ORDER BY `name`";
$bindings = [
    'public' => 1,
];

$waterfallCompanionList = $db->getRead()->fetchAll($query, $bindings);

foreach ($waterfallCompanionList as $waterfallCompanion) {
    $entryKey = "/companion/{$waterfallCompanion['alias']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'), // todo based on log
            'changefreq' => 'monthly',
            'priority' => .2
        ]
    ];

    for ($i = 2; (($i - 1) * 10) < $waterfallCompanion['count']; $i++) {
        $entryKey = "/companion/{$waterfallCompanion['alias']}/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => .1
            ]
        ];
    }
}

$query = "
    SELECT `period`.`name`, `period`.`alias`, COUNT(1) AS `count`
    FROM `jpemeric_waterfall`.`period`
    INNER JOIN `jpemeric_waterfall`.`log` ON `log`.`period` = `log`.`id` AND
                                             `log`.`is_public` = :public
    GROUP BY `alias`
    ORDER BY `name`";
$bindings = [
    'public' => 1,
];

$waterfallPeriodList = $db->getRead()->fetchAll($query, $bindings);

foreach ($waterfallPeriodList as $waterfallPeriod) {
    $entryKey = "/period/{$waterfallPeriod['alias']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'), // todo based on log
            'changefreq' => 'monthly',
            'priority' => .2
        ]
    ];

    for ($i = 2; (($i - 1) * 10) < $waterfallPeriod['count']; $i++) {
        $entryKey = "/period/{$waterfallPeriod['alias']}/{$i}/";
        $entryArray += [
            $entryKey => [
                'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => .1
            ]
        ];
    }
}

foreach ($activeWaterfallLogs as $waterfallLog) {
    $entryKey = "/journal/{$waterfallLog['alias']}/";
    $entryArray += [
        $entryKey => [
            'lastmod' => (new DateTime($waterfallLog['publish_date']))->format('c'),
            'changefreq' => 'weekly',
            'priority' => .4,
        ],
    ];
}

$entryArray += [
    '/about/' => [
        'lastmod' => (new DateTime('December 20, 2015'))->format('Y-m-d'),
        'changefreq' => 'monthly',
        'priority' => .6,
    ]
];

$buildSitemap($entryArray, 'https://www.waterfallsofthekeweenaw.com', 'waterfalls');
