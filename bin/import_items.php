#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// Load configuration
$configFile = __DIR__ . '/../config/parameters.yml';
if (!file_exists($configFile)) {
    error_log("Configuration file not found: $configFile");
    exit(1);
}

try {
    $config = Yaml::parseFile($configFile);
} catch (Exception $e) {
    error_log("Failed to parse configuration: " . $e->getMessage());
    exit(1);
}

$dbConfig = $config['parameters']['database'] ?? null;
if (!$dbConfig || empty($dbConfig['dsn']) || !isset($dbConfig['username']) || !array_key_exists('password', $dbConfig)) {
    error_log("Invalid database configuration in parameters.yml");
    exit(1);
}

$options = getopt('q');
$quiet = isset($options['q']);

try {
    $pdo = new PDO($dbConfig['dsn'], $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    exit(1);
}

// Set timezone from config (fallback to UTC)
$timezone = $config['parameters']['timezone'] ?? 'UTC';
date_default_timezone_set($timezone);

// Delete items older than one month based on publish_date
$deleteStmt = $pdo->prepare("DELETE FROM items WHERE publish_date < UTC_TIMESTAMP() - INTERVAL 1 WEEK");
$deleteStmt->execute();
$deleted = $deleteStmt->rowCount();
if (!$quiet) {
    error_log("Deleted old items: $deleted records");
}

// Fetch all feeds that are due
$feedsStmt = $pdo->query("SELECT id, name, url, next_read FROM feeds ORDER BY next_read ASC");
$feeds = $feedsStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($feeds as $row) {
    $feed_id = (int)$row['id'];
    $name = $row['name'];
    $url = $row['url'];
    $nextReadStr = $row['next_read'];

    $now = new DateTime();
    $nextRead = new DateTime($nextReadStr);

    if ($nextRead > $now) {
        continue; // Not due yet
    }

    sleep(1);
    $result = getItems($url);
    $items = $result['items'];
    $error = $result['error'];

    if ($error) {
        error_log("Feed '$name' ($url): Fetch error - $error");
    }

    $newCount = 0;
    if (!empty($items)) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO items (feed_id, title, link, content, publish_date, created_at) " .
            "VALUES (?, ?, ?, ?, ?, NOW()) " .
            "ON DUPLICATE KEY UPDATE " .
            "title = VALUES(title), " .
            "content = VALUES(content), " .
            "publish_date = VALUES(publish_date)"
        );

        foreach ($items as $item) {
            $pubStr = $item['publish_date']->format('Y-m-d H:i:s');
            if (strtotime($pubStr) > time() + 86400) { // Flag obvious future dates (>1 day ahead)
                error_log("Potential future date detected for feed $feed_id: $pubStr (title: '{$item['title']}')");
            }
            $insertStmt->execute([$feed_id, $item['title'], $item['link'], $item['content'], $pubStr]);
            // MySQL: rowCount() == 1 for INSERT, == 2 for UPDATE
            if ($insertStmt->rowCount() == 1) {
                $newCount++;
            }
        }
    }

    // Calculate next_read based on average age of fetched items
    $now_ts = time();
    if ($error || empty($items)) {
        $interval = 3 * 3600; // 3 hours if failed or no items
    } else {
        $ages = [];
        foreach ($items as $item) {
            $age = $now_ts - $item['publish_date']->getTimestamp();
            if ($age > 0) {
                $ages[] = $age;
            }
        }
        if (empty($ages)) {
            $interval = 3 * 3600; // Fallback if no valid ages
        } else {
            $avg_age = array_sum($ages) / count($ages);
            $interval = (int) round($avg_age);
            $interval = max(3600, min(86400, $interval)); // Clamp: 1h to 1 day
        }
    }

    // Set next_read
    $next = clone $now;
    $next->add(new DateInterval("PT${interval}S"));
    $nextStr = $next->format('Y-m-d H:i:s');

    $updateStmt = $pdo->prepare("UPDATE feeds SET next_read = ? WHERE id = ?");
    $updateStmt->execute([$nextStr, $feed_id]);

    $activity = $newCount > 0 ? "new=$newCount" : 'no new';
    if (!$quiet) {
        error_log("Feed '$name': Fetched $activity, interval=${interval}s, next=$nextStr");
    }
}

// Helper function: Fetch and parse items from a single feed (adapted from index.php)
function getItems(string $url): array {
    $items = [];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'sec-ch-ua: "Chromium";v="130", "Not;A=Brand";v="24", "Google Chrome";v="130"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: navigate',
        'sec-fetch-site: none',
        'sec-fetch-user: ?1',
        'Accept: application/rss+xml, application/xml, text/xml, text/html;q=0.9,*/*;q=0.8'
    ));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // As in example; enable in production if possible
    $xmlString = curl_exec($ch);
    if ($xmlString === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['items' => $items, 'error' => 'Failed to fetch feed: ' . $error];
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($xmlString)) {
        $error = $httpCode !== 200 ? "HTTP $httpCode" : "Empty response";
        return ['items' => $items, 'error' => 'Failed to fetch feed: ' . $error];
    }

    $xml = @simplexml_load_string($xmlString);
    if (!$xml) {
        return ['items' => $items, 'error' => 'Invalid XML response'];
    }

    $rootName = $xml->getName();

    if ($rootName === 'rss' && isset($xml->channel->item)) {
        // Fallback date from channel, normalized to UTC
        $fallbackDate = null;
        if (isset($xml->channel->lastBuildDate)) {
            try {
                $fallbackDate = new DateTime((string)$xml->channel->lastBuildDate);
                $fallbackDate->setTimezone(new DateTimeZone('UTC'));
            } catch (Exception $e) {
                error_log("Invalid fallback lastBuildDate: " . $e->getMessage());
            }
        }

        // RSS parsing (no date cutoff, no filters)
        foreach ($xml->channel->item as $item) {
            if (!isset($item->title) || !isset($item->link)) {
                continue;
            }

            // Handle pubDate with fallback, normalized to UTC
            $pub = null;
            if (isset($item->pubDate)) {
                try {
                    $pub = new DateTime((string)$item->pubDate); // Parses TZ if present
                    $pub->setTimezone(new DateTimeZone('UTC')); // Force to UTC
                } catch (Exception $e) {
                    error_log("Invalid pubDate for item '{$item->title}': " . $e->getMessage());
                }
            } elseif ($fallbackDate) {
                $pub = clone $fallbackDate;
                $pub->setTimezone(new DateTimeZone('UTC'));
            }
            if (!$pub) {
                continue;
            }

            $title = extractContent((string)$item->title);
            $content = extractContent((string)($item->description ?? ''));

            $items[] = [
                'publish_date' => $pub,
                'title' => $title,
                'link' => (string)$item->link,
                'content' => $content
            ];
        }
    } elseif ($rootName === 'feed' && isset($xml->entry)) {
        // Atom parsing (no date cutoff, no filters)
        foreach ($xml->entry as $entry) {
            $title = extractContent((string)$entry->title);
            if (!$title) {
                continue;
            }

            // Get link
            $link = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $l) {
                    $rel = (string)($l['rel'] ?? '');
                    if ($rel === 'alternate' || empty($rel)) {
                        $link = (string)($l['href'] ?? '');
                        break;
                    }
                }
            }
            if (!$link) {
                continue;
            }

            // Get date: prefer updated, fallback to published, normalized to UTC
            $dateStr = '';
            if (isset($entry->updated)) {
                $dateStr = (string)$entry->updated;
            } elseif (isset($entry->published)) {
                $dateStr = (string)$entry->published;
            }
            if (!$dateStr) {
                continue;
            }

            try {
                $pub = new DateTime($dateStr); // Parses TZ if present
                $pub->setTimezone(new DateTimeZone('UTC')); // Force to UTC
            } catch (Exception $e) {
                error_log("Invalid date for entry '{$entry->title}': " . $e->getMessage());
                continue;
            }

            // Get content: prefer summary, fallback to content
            $summary = (string)($entry->summary ?? '');
            $contentNode = (string)($entry->content ?? '');
            $content = extractContent($summary) ?: extractContent($contentNode);

            $items[] = [
                'publish_date' => $pub,
                'title' => $title,
                'link' => $link,
                'content' => $content
            ];
        }
    } else {
        return ['items' => $items, 'error' => 'Unsupported feed format'];
    }

    return ['items' => $items, 'error' => ''];
}

// Helper: Extract and clean content (from index.php)
function extractContent(string $encodedString): string {
    // Handle CDATA
    $startPos = mb_strpos($encodedString, '<![CDATA[');
    if ($startPos !== false) {
        $startPos += mb_strlen('<!' . '[CDATA[');
        $endPos = mb_strpos($encodedString, ']]>', $startPos);
        if ($endPos !== false) {
            $encodedString = mb_substr($encodedString, $startPos, $endPos - $startPos);
        }
    }

    $rawContent = str_replace('&nbsp;', ' ', $encodedString);
    $rawContent = str_replace('&#8217;', '\'', $rawContent);
    $decodedContent = html_entity_decode($rawContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim($decodedContent);
}

if (!$quiet) {
    error_log("Cron job completed at " . date('Y-m-d H:i:s'));
}
?>
