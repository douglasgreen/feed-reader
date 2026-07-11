<?php

declare(strict_types=1);

namespace DouglasGreen\FeedReader\Controller;

use DateInterval;
use DateTime;
use DateTimeZone;
use DouglasGreen\FeedReader\AppContainer;
use Exception;
use PDO;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

final readonly class ImportController
{
    private PDO $pdo;

    private Request $request;

    private Session $session;

    public function __construct(private AppContainer $app)
    {
        $this->pdo = $this->app->getPdo();
        $this->request = $this->app->getRequest();
        $this->session = $this->app->getSession();
    }

    public function execute(): RedirectResponse
    {
        $force = $this->request->request->getBoolean('force');
        $result = $this->process($force);

        if (!empty($result['errors'])) {
            $this->session->getFlashBag()->add('error', implode("\n", $result['errors']));
        }

        $this->session->getFlashBag()->add('success', sprintf('Import completed. Added %s new items.', $result['new']));

        return new RedirectResponse($this->request->getRequestUri());
    }

    /**
     * @return array{new: int, errors: list<string>}
     */
    public function process(bool $force = false): array
    {
        // Delete items older than one week
        $deleteStmt = $this->pdo->prepare('DELETE FROM items WHERE publish_date < UTC_TIMESTAMP() - INTERVAL 1 WEEK');
        $deleteStmt->execute();

        $feedsStmt = $this->pdo->query('SELECT id, name, url, next_read FROM feeds ORDER BY next_read ASC');
        if ($feedsStmt === false) {
            throw new Exception('Failed to load feeds for import');
        }

        $feeds = $feedsStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalNew = 0;
        $errors = [];

        foreach ($feeds as $row) {
            $feed_id = (int) $row['id'];
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
                $errors[] = sprintf("Feed '%s': %s", $name, $error);
            }

            $newCount = 0;
            if (!empty($items)) {
                $insertStmt = $this->pdo->prepare(
                    'INSERT IGNORE INTO items (feed_id, title, link, content, publish_date, created_at) ' .
                    'VALUES (?, ?, ?, ?, ?, NOW())',
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

                $interval = $ages === [] ? 3 * 3600 : max(3600, min(86400, (int) round(array_sum($ages) / count($ages))));
            }

            $next = clone $now;
            $next->add(new DateInterval(sprintf('PT%dS', $interval)));
            $updateStmt = $this->pdo->prepare('UPDATE feeds SET next_read = ? WHERE id = ?');
            $updateStmt->execute([$next->format('Y-m-d H:i:s'), $feed_id]);

            $totalNew += $newCount;
        }

        return ['new' => $totalNew, 'errors' => $errors];
    }

    /**
     * @return array{
     *     items: list<array{publish_date: DateTime, title: string, link: string, content: string}>,
     *     error: string
     * }
     */
    private function getItems(string $url): array
    {
        $items = [];
        if ($url === '') {
            return ['items' => $items, 'error' => 'Failed to fetch feed: Empty URL'];
        }

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
                'Accept: application/rss+xml, application/xml, text/xml, text/html;q=0.9,*/*;q=0.8',
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

        if ($httpCode !== 200 || !is_string($xmlString) || $xmlString === '') {
            return ['items' => $items, 'error' => 'Failed to fetch feed: ' . ($httpCode !== 200 ? 'HTTP ' . $httpCode : 'Empty response')];
        }

        $xml = @simplexml_load_string($xmlString);
        if (!$xml) {
            return ['items' => $items, 'error' => 'Invalid XML response'];
        }

        $rootName = $xml->getName();

        if ($rootName === 'rss' && (property_exists($xml->channel, 'item') && $xml->channel->item !== null)) {
            $fallbackDate = null;
            if (property_exists($xml->channel, 'lastBuildDate') && $xml->channel->lastBuildDate !== null) {
                try {
                    $fallbackDate = new DateTime((string) $xml->channel->lastBuildDate);
                    $fallbackDate->setTimezone(new DateTimeZone('UTC'));
                } catch (Exception) {
                    // ignore
                }
            }

            foreach ($xml->channel->item as $item) {
                if (!property_exists($item, 'title')) {
                    continue;
                }
                if ($item->title === null) {
                    continue;
                }

                if (!property_exists($item, 'link')) {
                    continue;
                }
                if ($item->link === null) {
                    continue;
                }

                $pub = null;
                if (property_exists($item, 'pubDate') && $item->pubDate !== null) {
                    try {
                        $pub = new DateTime((string) $item->pubDate);
                        $pub->setTimezone(new DateTimeZone('UTC'));
                    } catch (Exception) {
                    }
                } elseif ($fallbackDate instanceof DateTime) {
                    $pub = clone $fallbackDate;
                }

                if (!$pub instanceof DateTime) {
                    continue;
                }

                $items[] = [
                    'publish_date' => $pub,
                    'title' => $this->extractContent((string) $item->title),
                    'link' => (string) $item->link,
                    'content' => $this->extractContent((string) ($item->description ?? '')),
                ];
            }
        } elseif ($rootName === 'feed' && (property_exists($xml, 'entry') && $xml->entry !== null)) {
            foreach ($xml->entry as $entry) {
                $title = $this->extractContent((string) $entry->title);
                if ($title === '') {
                    continue;
                }

                $link = '';
                if (property_exists($entry, 'link') && $entry->link !== null) {
                    foreach ($entry->link as $l) {
                        $rel = (string) ($l['rel'] ?? '');
                        if ($rel === 'alternate' || empty($rel)) {
                            $link = (string) ($l['href'] ?? '');
                            break;
                        }
                    }
                }

                if ($link === '') {
                    continue;
                }

                $dateStr = '';
                if (property_exists($entry, 'updated') && $entry->updated !== null) {
                    $dateStr = (string) $entry->updated;
                } elseif (property_exists($entry, 'published') && $entry->published !== null) {
                    $dateStr = (string) $entry->published;
                }

                if ($dateStr === '') {
                    continue;
                }

                try {
                    $pub = new DateTime($dateStr);
                    $pub->setTimezone(new DateTimeZone('UTC'));
                } catch (Exception) {
                    continue;
                }

                $summary = (string) ($entry->summary ?? '');
                $contentNode = (string) ($entry->content ?? '');
                $content = $this->extractContent($summary) ?: $this->extractContent($contentNode);

                $items[] = [
                    'publish_date' => $pub,
                    'title' => $title,
                    'link' => $link,
                    'content' => $content,
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
        $rawContent = str_replace('&#8217;', "'", $rawContent);
        return trim(html_entity_decode($rawContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
