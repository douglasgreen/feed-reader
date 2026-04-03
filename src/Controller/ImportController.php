<?php

namespace DouglasGreen\FeedReader\Controller;

use DateTime;
use DateTimeZone;
use DateInterval;
use DouglasGreen\FeedReader\AppContainer;
use PDO;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

final class ImportController
{
    private AppContainer $app;
    private PDO $pdo;
    private Request $request;
    private Session $session;

    public function __construct(AppContainer $app)
    {
        $this->app = $app;
        $this->pdo = $app->getPdo();
        $this->request = $app->getRequest();
        $this->session = $app->getSession();
    }

    public function execute(): RedirectResponse
    {
        $force = $this->request->request->get('force', false);
        $result = $this->process($force);

        if (!empty($result['errors'])) {
            $this->session->getFlashBag()->add('error', implode("\n", $result['errors']));
        }
        $this->session->getFlashBag()->add('success', "Import completed. Added {$result['new']} new items.");

        return new RedirectResponse($this->request->getRequestUri());
    }

    public function process(bool $force = false): array
    {
        // Delete items older than one week
        $deleteStmt = $this->pdo->prepare("DELETE FROM items WHERE publish_date < UTC_TIMESTAMP() - INTERVAL 1 WEEK");
        $deleteStmt->execute();

        $feedsStmt = $this->pdo->query("SELECT id, name, url, next_read FROM feeds ORDER BY next_read ASC");
        $feeds = $feedsStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalNew = 0;
        $errors = [];

        foreach ($feeds as $row) {
            $feed_id = (int)$row['id'];
            $name = $row['name'];
            $url = $row['url'];
            $nextReadStr = $row['next_read'];

            $now = new DateTime();
            $nextRead = new DateTime($nextReadStr);

            if ($nextRead > $now && !$force) {
                continue;
            }

            sleep(1);
            $result = $this->getItems($url);
            $items = $result['items'];
            $error = $result['error'];

            if ($error) {
                $errors[] = "Feed '$name': $error";
            }

            $newCount = 0;
            if (!empty($items)) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT IGNORE INTO items (feed_id, title, link, content, publish_date, created_at) " .
                    "VALUES (?, ?, ?, ?, ?, NOW())"
                );

                foreach ($items as $item) {
                    $pubStr = $item['publish_date']->format('Y-m-d H:i:s');
                    $insertStmt->execute([$feed_id, $item['title'], $item['link'], $item['content'], $pubStr]);
                    if ($insertStmt->rowCount() == 1) {
                        $newCount++;
                    }
                }
            }

            $now_ts = time();
            if ($error || empty($items)) {
                $interval = 3 * 3600;
            } else {
                $ages = [];
                foreach ($items as $item) {
                    $age = $now_ts - $item['publish_date']->getTimestamp();
                    if ($age > 0) {
                        $ages[] = $age;
                    }
                }
                $interval = empty($ages) ? 3 * 3600 : max(3600, min(86400, (int) round(array_sum($ages) / count($ages))));
            }

            $next = clone $now;
            $next->add(new DateInterval("PT{$interval}S"));
            $updateStmt = $this->pdo->prepare("UPDATE feeds SET next_read = ? WHERE id = ?");
            $updateStmt->execute([$next->format('Y-m-d H:i:s'), $feed_id]);

            $totalNew += $newCount;
        }

        return ['new' => $totalNew, 'errors' => $errors];
    }

    private function getItems(string $url): array
    {
        $items = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'sec-ch-ua: "Chromium";v="130", "Not;A=Brand";v="24", "Google Chrome";v="130"',
                'sec-ch-ua-mobile: ?0',
                'sec-ch-ua-platform: "Windows"',
                'sec-fetch-dest: empty',
                'sec-fetch-mode: navigate',
                'sec-fetch-site: none',
                'sec-fetch-user: ?1',
                'Accept: application/rss+xml, application/xml, text/xml, text/html;q=0.9,*/*;q=0.8'
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $xmlString = curl_exec($ch);
        if ($xmlString === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['items' => $items, 'error' => 'Failed to fetch feed: ' . $error];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($xmlString)) {
            return ['items' => $items, 'error' => 'Failed to fetch feed: ' . ($httpCode !== 200 ? "HTTP $httpCode" : "Empty response")];
        }

        $xml = @simplexml_load_string($xmlString);
        if (!$xml) {
            return ['items' => $items, 'error' => 'Invalid XML response'];
        }

        $rootName = $xml->getName();

        if ($rootName === 'rss' && isset($xml->channel->item)) {
            $fallbackDate = null;
            if (isset($xml->channel->lastBuildDate)) {
                try {
                    $fallbackDate = new DateTime((string)$xml->channel->lastBuildDate);
                    $fallbackDate->setTimezone(new DateTimeZone('UTC'));
                } catch (\Exception $e) {
                    // ignore
                }
            }

            foreach ($xml->channel->item as $item) {
                if (!isset($item->title) || !isset($item->link)) continue;

                $pub = null;
                if (isset($item->pubDate)) {
                    try {
                        $pub = new DateTime((string)$item->pubDate);
                        $pub->setTimezone(new DateTimeZone('UTC'));
                    } catch (\Exception $e) {}
                } elseif ($fallbackDate) {
                    $pub = clone $fallbackDate;
                }
                if (!$pub) continue;

                $items[] = [
                    'publish_date' => $pub,
                    'title' => $this->extractContent((string)$item->title),
                    'link' => (string)$item->link,
                    'content' => $this->extractContent((string)($item->description ?? ''))
                ];
            }
        } elseif ($rootName === 'feed' && isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $title = $this->extractContent((string)$entry->title);
                if (!$title) continue;

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
                if (!$link) continue;

                $dateStr = '';
                if (isset($entry->updated)) $dateStr = (string)$entry->updated;
                elseif (isset($entry->published)) $dateStr = (string)$entry->published;
                if (!$dateStr) continue;

                try {
                    $pub = new DateTime($dateStr);
                    $pub->setTimezone(new DateTimeZone('UTC'));
                } catch (\Exception $e) { continue; }

                $summary = (string)($entry->summary ?? '');
                $contentNode = (string)($entry->content ?? '');
                $content = $this->extractContent($summary) ?: $this->extractContent($contentNode);

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

    private function extractContent(string $encodedString): string
    {
        $startPos = mb_strpos($encodedString, '<![CDATA[');
        if ($startPos !== false) {
            $startPos += mb_strlen('<![CDATA[');
            $endPos = mb_strpos($encodedString, ']]>', $startPos);
            if ($endPos !== false) {
                $encodedString = mb_substr($encodedString, $startPos, $endPos - $startPos);
            }
        }

        $rawContent = str_replace('&nbsp;', ' ', $encodedString);
        $rawContent = str_replace('&#8217;', '\'', $rawContent);
        return trim(html_entity_decode($rawContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
