<?php

namespace DouglasGreen\FeedReader\Controller;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DouglasGreen\FeedReader\AppContainer;
use DouglasGreen\FeedReader\Controller\ImportController;
use PDO;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Main feed reader controller
 */
final class FeedController
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

    public function execute(): Response
    {
        // Handle POST requests
        if ($this->request->isMethod('POST')) {
            return $this->handlePostRequest();
        }

        // Handle GET requests (display page)
        return $this->displayPage();
    }

    private function handlePostRequest(): Response
    {
        $action = $this->request->request->get('action', '');

        try {
            switch ($action) {
                case 'add_feed':
                    return $this->addFeed();
                case 'edit_feed':
                    return $this->editFeed();
                case 'delete_feed':
                    return $this->deleteFeed();
                case 'add_filter':
                    return $this->addFilter();
                case 'edit_filter':
                    return $this->editFilter();
                case 'delete_filter':
                    return $this->deleteFilter();
                case 'add_group':
                    return $this->addGroup();
                case 'edit_group':
                    return $this->editGroup();
                case 'delete_group':
                    return $this->deleteGroup();
                case 'import_feeds':
                    $importController = new ImportController($this->app);
                    return $importController->execute();
                default:
                    throw new \Exception('Unknown action: ' . $action);
            }
        } catch (\Exception $e) {
            $this->session->getFlashBag()->add('error', $e->getMessage());
            return new RedirectResponse($this->request->getRequestUri());
        }
    }

    private function addFeed(): RedirectResponse
    {
        $name = trim($this->request->request->get('feed_name', ''));
        $url = trim($this->request->request->get('feed_url', ''));
        $groupId = (int) $this->request->request->get('group_id', 0);

        $this->validateFeedInput($name, $url, $groupId);

        // Check if group exists
        $stmt = $this->pdo->prepare("SELECT id FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        if (!$stmt->fetch()) {
            throw new \Exception('Selected group does not exist');
        }

        // Check for duplicate URL
        $stmt = $this->pdo->prepare("SELECT id FROM feeds WHERE url = ?");
        $stmt->execute([$url]);
        if ($stmt->fetch()) {
            throw new \Exception('A feed with this URL already exists');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO feeds (group_id, name, url, next_read, created_at, updated_at)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $stmt->execute([$groupId, $name, $url]);
        $newFeedId = $this->pdo->lastInsertId();

        $this->session->getFlashBag()->add('success', 'Feed added successfully');
        return new RedirectResponse('?feed=' . $newFeedId);
    }

    private function editFeed(): RedirectResponse
    {
        $feedId = (int) $this->request->request->get('feed_id', 0);
        $name = trim($this->request->request->get('feed_name', ''));
        $url = trim($this->request->request->get('feed_url', ''));
        $groupId = (int) $this->request->request->get('group_id', 0);

        if ($feedId <= 0) {
            throw new \Exception('Invalid feed ID');
        }

        $this->validateFeedInput($name, $url, $groupId);

        // Get current feed
        $stmt = $this->pdo->prepare("SELECT url FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);
        $feed = $stmt->fetch();
        if (!$feed) {
            throw new \Exception('Feed not found');
        }

        // Check if group exists
        $stmt = $this->pdo->prepare("SELECT id FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        if (!$stmt->fetch()) {
            throw new \Exception('Selected group does not exist');
        }

        // Check for duplicate URL (if changed)
        if ($url !== $feed['url']) {
            $stmt = $this->pdo->prepare("SELECT id FROM feeds WHERE url = ?");
            $stmt->execute([$url]);
            if ($stmt->fetch()) {
                throw new \Exception('A feed with this URL already exists');
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE feeds
            SET name = ?, url = ?, group_id = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->execute([$name, $url, $groupId, $feedId]);

        $this->session->getFlashBag()->add('success', 'Feed updated successfully');
        return new RedirectResponse('?feed=' . $feedId);
    }

    private function deleteFeed(): RedirectResponse
    {
        $feedId = (int) $this->request->request->get('feed_id', 0);
        $confirm = $this->request->request->get('confirm', '');

        if ($confirm !== 'yes') {
            throw new \Exception('Deletion not confirmed');
        }

        if ($feedId <= 0) {
            throw new \Exception('Invalid feed ID');
        }

        $stmt = $this->pdo->prepare("SELECT group_id FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);
        $feed = $stmt->fetch();
        if (!$feed) {
            throw new \Exception('Feed not found');
        }

        $groupId = $feed['group_id'];

        $stmt = $this->pdo->prepare("DELETE FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);

        // Delete group if empty
        if ($this->isGroupEmpty($groupId)) {
            $stmt = $this->pdo->prepare("DELETE FROM groups WHERE id = ?");
            $stmt->execute([$groupId]);
        }

        $this->session->getFlashBag()->add('success', 'Feed deleted successfully');
        return new RedirectResponse('/');
    }

    private function addFilter(): RedirectResponse
    {
        $filterString = trim($this->request->request->get('filter_string', ''));

        if (empty($filterString)) {
            throw new \Exception('Filter string is required');
        }

        // Check for duplicate
        $stmt = $this->pdo->prepare("SELECT id FROM filters WHERE filter_string = ?");
        $stmt->execute([$filterString]);
        if ($stmt->fetch()) {
            throw new \Exception('This filter already exists');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO filters (filter_string, created_at, updated_at)
            VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $stmt->execute([$filterString]);

        $this->session->getFlashBag()->add('success', 'Filter added successfully');
        return new RedirectResponse($this->request->getRequestUri());
    }

    private function editFilter(): RedirectResponse
    {
        $filterId = (int) $this->request->request->get('filter_id', 0);
        $filterString = trim($this->request->request->get('filter_string', ''));

        if ($filterId <= 0) {
            throw new \Exception('Invalid filter ID');
        }

        if (empty($filterString)) {
            throw new \Exception('Filter string is required');
        }

        $stmt = $this->pdo->prepare("SELECT filter_string FROM filters WHERE id = ?");
        $stmt->execute([$filterId]);
        $filter = $stmt->fetch();
        if (!$filter) {
            throw new \Exception('Filter not found');
        }

        // Check for duplicate (if changed)
        if ($filterString !== $filter['filter_string']) {
            $stmt = $this->pdo->prepare("SELECT id FROM filters WHERE filter_string = ?");
            $stmt->execute([$filterString]);
            if ($stmt->fetch()) {
                throw new \Exception('This filter already exists');
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE filters
            SET filter_string = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->execute([$filterString, $filterId]);

        $this->session->getFlashBag()->add('success', 'Filter updated successfully');
        return new RedirectResponse($this->request->getRequestUri());
    }

    private function deleteFilter(): RedirectResponse
    {
        $filterId = (int) $this->request->request->get('filter_id', 0);
        $confirm = $this->request->request->get('confirm', '');

        if ($confirm !== 'yes') {
            throw new \Exception('Deletion not confirmed');
        }

        if ($filterId <= 0) {
            throw new \Exception('Invalid filter ID');
        }

        $stmt = $this->pdo->prepare("SELECT id FROM filters WHERE id = ?");
        $stmt->execute([$filterId]);
        if (!$stmt->fetch()) {
            throw new \Exception('Filter not found');
        }

        $stmt = $this->pdo->prepare("DELETE FROM filters WHERE id = ?");
        $stmt->execute([$filterId]);

        $this->session->getFlashBag()->add('success', 'Filter deleted successfully');
        return new RedirectResponse($this->request->getRequestUri());
    }

    private function addGroup(): RedirectResponse
    {
        $groupName = trim($this->request->request->get('group_name', ''));

        if (empty($groupName)) {
            throw new \Exception('Group name is required');
        }

        // Check for duplicate
        $stmt = $this->pdo->prepare("SELECT id FROM groups WHERE name = ?");
        $stmt->execute([$groupName]);
        if ($stmt->fetch()) {
            throw new \Exception('A group with this name already exists');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO groups (name, created_at, updated_at)
            VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $stmt->execute([$groupName]);

        $this->session->getFlashBag()->add('success', 'Group added successfully');
        return new RedirectResponse($this->request->getRequestUri());
    }

    private function editGroup(): RedirectResponse
    {
        $groupId = (int) $this->request->request->get('group_id', 0);
        $groupName = trim($this->request->request->get('group_name', ''));

        if ($groupId <= 0) {
            throw new \Exception('Invalid group ID');
        }

        if (empty($groupName)) {
            throw new \Exception('Group name is required');
        }

        $stmt = $this->pdo->prepare("SELECT name FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();
        if (!$group) {
            throw new \Exception('Group not found');
        }

        // Check for duplicate (if changed)
        if ($groupName !== $group['name']) {
            $stmt = $this->pdo->prepare("SELECT id FROM groups WHERE name = ?");
            $stmt->execute([$groupName]);
            if ($stmt->fetch()) {
                throw new \Exception('A group with this name already exists');
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE groups
            SET name = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt->execute([$groupName, $groupId]);

        $this->session->getFlashBag()->add('success', 'Group updated successfully');
        return new RedirectResponse($this->request->getRequestUri());
    }

    private function deleteGroup(): RedirectResponse
    {
        $groupId = (int) $this->request->request->get('group_id', 0);
        $confirm = $this->request->request->get('confirm', '');

        if ($confirm !== 'yes') {
            throw new \Exception('Deletion not confirmed');
        }

        if ($groupId <= 0) {
            throw new \Exception('Invalid group ID');
        }

        $stmt = $this->pdo->prepare("SELECT id FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        if (!$stmt->fetch()) {
            throw new \Exception('Group not found');
        }

        if (!$this->isGroupEmpty($groupId)) {
            throw new \Exception('Group is not empty. Delete all feeds in this group first.');
        }

        $stmt = $this->pdo->prepare("DELETE FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);

        $this->session->getFlashBag()->add('success', 'Group deleted successfully');
        return new RedirectResponse($this->request->getRequestUri());
    }

    private function displayPage(): Response
    {
        $currentFeed = $this->request->query->get('feed', '');
        $searchQuery = $this->request->query->get('search', '');

        // Load all data
        $groupedFeeds = $this->loadGroupedFeeds();
        $allGroups = $this->loadAllGroups();
        $allFilters = $this->loadAllFilters();
        $feedCounts = $this->calculateFeedCounts($groupedFeeds);

        // Load items based on search or feed
        if (!empty($searchQuery)) {
            $items = $this->searchItems(trim($searchQuery), $allFilters);
            $pageTitle = 'Search Results: ' . htmlspecialchars($searchQuery);
            $currentFeedData = null;
        } elseif ($currentFeed !== '' && is_numeric($currentFeed)) {
            $result = $this->loadFeedItems((int) $currentFeed, $allFilters);
            $items = $result['items'];
            $pageTitle = $result['title'];
            $currentFeedData = $result['feedData'];
        } else {
            $items = [];
            $pageTitle = 'RSS Feed Reader';
            $currentFeedData = null;
        }

        $html = $this->buildPage([
            'pageTitle' => $pageTitle,
            'currentFeed' => $currentFeed,
            'searchQuery' => $searchQuery,
            'groupedFeeds' => $groupedFeeds,
            'feedCounts' => $feedCounts,
            'allGroups' => $allGroups,
            'allFilters' => $allFilters,
            'items' => $items,
            'currentFeedData' => $currentFeedData,
            'flashMessages' => $this->session->getFlashBag()->all(),
        ]);

        return new Response($html);
    }

    private function loadGroupedFeeds(): array
    {
        $stmt = $this->pdo->query("
            SELECT g.id as group_id, g.name as group_name,
                   f.id as feed_id, f.name as feed_name,
                   f.url, f.last_viewed
            FROM groups g
            JOIN feeds f ON g.id = f.group_id
            ORDER BY g.name, f.name
        ");
        $feeds = $stmt->fetchAll();

        $grouped = [];
        foreach ($feeds as $feed) {
            $g = $feed['group_name'];
            if (!isset($grouped[$g])) {
                $grouped[$g] = ['group_id' => $feed['group_id'], 'feeds' => []];
            }
            $grouped[$g]['feeds'][] = $feed;
        }

        return $grouped;
    }

    private function loadAllGroups(): array
    {
        $stmt = $this->pdo->query("SELECT id, name FROM groups ORDER BY name");
        return $stmt->fetchAll();
    }

    private function loadAllFilters(): array
    {
        $stmt = $this->pdo->query("SELECT id, filter_string FROM filters ORDER BY filter_string");
        return $stmt->fetchAll();
    }

    private function calculateFeedCounts(array $groupedFeeds): array
    {
        $counts = [];

        foreach ($groupedFeeds as $groupData) {
            foreach ($groupData['feeds'] as $f) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*)
                    FROM items i
                    WHERE i.feed_id = ?
                      AND i.publish_date > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                      AND (? IS NULL OR i.publish_date > ?)
                ");
                $stmt->execute([$f['feed_id'], $f['last_viewed'], $f['last_viewed']]);
                $counts[$f['url']] = (int) $stmt->fetchColumn();
            }
        }

        return $counts;
    }

    private function loadFeedItems(int $feedId, array $filters): array
    {
        $stmt = $this->pdo->prepare("SELECT id, name, last_viewed FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);
        $feedInfo = $stmt->fetch();

        if (!$feedInfo) {
            return [
                'items' => [],
                'title' => 'Feed not found',
                'feedData' => null,
            ];
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime('-30 days'));

        $stmt = $this->pdo->prepare("
            SELECT i.title, i.link, i.content, i.publish_date,
                   f.last_viewed as feed_last_viewed
            FROM items i
            JOIN feeds f ON i.feed_id = f.id
            WHERE i.feed_id = ?
              AND i.publish_date > ?
            ORDER BY i.publish_date DESC
            LIMIT 100
        ");
        $stmt->execute([$feedId, $cutoffDate]);
        $rawItems = $stmt->fetchAll();

        $items = $this->processItems($rawItems, $filters, $feedInfo['last_viewed']);

        // Update last viewed
        $stmt = $this->pdo->prepare("UPDATE feeds SET last_viewed = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$feedId]);

        // Get feed data for editing
        $stmt = $this->pdo->prepare("SELECT id, name, url, group_id FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);
        $feedData = $stmt->fetch();

        return [
            'items' => $items,
            'title' => 'Feed: ' . $feedInfo['name'],
            'feedData' => $feedData,
        ];
    }

    private function searchItems(string $query, array $filters): array
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime('-30 days'));

        $stmt = $this->pdo->prepare("
            SELECT i.title, i.link, i.content, i.publish_date,
                   f.last_viewed as feed_last_viewed, f.name as feed_name
            FROM items i
            JOIN feeds f ON i.feed_id = f.id
            WHERE (i.title LIKE ? OR i.content LIKE ?)
              AND i.publish_date > ?
            ORDER BY i.publish_date DESC
            LIMIT 100
        ");

        $searchPattern = '%' . $query . '%';
        $stmt->execute([$searchPattern, $searchPattern, $cutoffDate]);
        $rawItems = $stmt->fetchAll();

        return $this->processItems($rawItems, $filters, null);
    }

    private function processItems(array $rawItems, array $filters, ?string $lastViewed): array
    {
        $filterStrings = array_column($filters, 'filter_string');
        $allowedTags = [
            '<a>', '<audio>', '<b>', '<blockquote>', '<br>', '<caption>', '<code>',
            '<col>', '<colgroup>', '<dd>', '<del>', '<dl>', '<dt>', '<em>',
            '<h1>', '<h2>', '<h3>', '<h4>', '<i>', '<img>', '<ins>', '<li>',
            '<ol>', '<p>', '<source>', '<strong>', '<sub>', '<sup>', '<table>',
            '<tbody>', '<td>', '<tfoot>', '<th>', '<thead>', '<tr>', '<ul>', '<video>',
        ];

        $displayTz = new DateTimeZone($this->app->getConfig('display_timezone'));
        $items = [];

        foreach ($rawItems as $raw) {
            if ($this->shouldSkipItem($raw, $filterStrings)) {
                continue;
            }

            $pubDate = new DateTime($raw['publish_date'], new DateTimeZone('UTC'));
            $pubDate->setTimezone($displayTz);

            $cleanContent = $this->closeUnclosedTags(strip_tags($raw['content'] ?? '', implode('', $allowedTags)));

            $lastViewedDate = null;
            if ($lastViewed) {
                $lastViewedDate = new DateTime($lastViewed, new DateTimeZone('UTC'));
                $lastViewedDate->setTimezone($displayTz);
            }

            $isNew = ($lastViewedDate === null || $pubDate > $lastViewedDate);

            $items[] = [
                'pubDate' => $pubDate,
                'title' => $raw['title'],
                'link' => $raw['link'],
                'excerpt' => !empty($cleanContent) ? $cleanContent : '',
                'feed' => $raw['feed_name'] ?? '',
                'isNew' => $isNew,
                'relativeTime' => $this->relativeTime($pubDate),
            ];
        }

        return $items;
    }

    private function shouldSkipItem(array $item, array $filters): bool
    {
        $lowTitle = strtolower($item['title'] ?? '');
        $lowContent = strtolower($item['content'] ?? '');

        foreach ($filters as $f) {
            $lowF = strtolower(trim($f));
            if (empty($lowF)) {
                continue;
            }
            $pattern = '/\b' . preg_quote($lowF, '/') . '\b/';
            if (preg_match($pattern, $lowTitle) || preg_match($pattern, $lowContent)) {
                return true;
            }
        }

        return false;
    }

    private function relativeTime(DateTime $date): string
    {
        $now = new DateTime('now', $date->getTimezone());
        $diff = $now->diff($date);
        $days = (int) $diff->days;
        $hours = $diff->h + ($days * 24);
        $minutes = $diff->i + ($hours * 60);

        if ($days >= 1) {
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($hours >= 1) {
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($minutes >= 1) {
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } else {
            return 'just now';
        }
    }

    private function closeUnclosedTags(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $fixedHtml = $doc->saveHTML($doc->getElementsByTagName('div')->item(0));

        libxml_clear_errors();

        return substr($fixedHtml, 5, -6);
    }

    private function validateFeedInput(string $name, string $url, int $groupId): void
    {
        if (empty($name)) {
            throw new \Exception('Feed name is required');
        }

        if (empty($url)) {
            throw new \Exception('Feed URL is required');
        }

        if (!$this->isValidUrl($url)) {
            throw new \Exception('Feed URL must be a valid HTTP/HTTPS URL');
        }

        if ($groupId <= 0) {
            throw new \Exception('A group must be selected');
        }
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false &&
               (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    private function isGroupEmpty(int $groupId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM feeds WHERE group_id = ?");
        $stmt->execute([$groupId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    private function buildPage(array $data): string
    {
        // Register inline templates in Twig
        $this->registerTemplates();

        $twig = $this->app->getTwig();

        // Add layout data
        $data['memory'] = $this->app->getMemoryUsage();
        $data['time'] = number_format($this->app->getElapsedTime(), 3);

        // Render the full page using layout template
        return $twig->render('layout', $data);
    }

    private function registerTemplates(): void
    {
        $twig = $this->app->getTwig();
        $loader = $twig->getLoader();

        // Header with search bar
        $loader->setTemplate(
            'header',
            <<<'TWIG'
<header class="bg-primary text-white py-3 mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-4">
                <h1 class="h3 mb-0">📰 RSS Feed Reader</h1>
            </div>
            <div class="col-md-8">
                <form method="GET" action="" class="d-flex">
                    <input
                        type="search"
                        name="search"
                        class="form-control me-2"
                        placeholder="Search feed items..."
                        value="{{ searchQuery }}"
                        aria-label="Search"
                    >
                    <button type="submit" class="btn btn-light">
                        <i class="bi bi-search"></i> Search
                    </button>
                    {% if searchQuery %}
                    <a href="index.php" class="btn btn-outline-light ms-2">Clear</a>
                    {% endif %}
                </form>
            </div>
        </div>
    </div>
</header>
TWIG
        );

        // Left sidebar
        $loader->setTemplate(
            'left_sidebar',
            <<<'TWIG'
<nav class="bg-white border-end p-3" style="min-height: calc(100vh - 200px);">
    <h3 class="mb-4 text-primary fw-semibold fs-5">Feeds</h3>

    {% for groupName, groupData in groupedFeeds %}
        <div class="group-header d-flex justify-content-between align-items-center text-secondary text-uppercase small fw-semibold ps-3 py-2 my-1">
            <span>{{ groupName }}</span>
            <div class="btn-group-action">
                <button type="button" class="btn btn-sm btn-outline-secondary p-1 lh-1"
                    data-bs-toggle="modal" data-bs-target="#editGroupModal"
                    data-group-id="{{ groupData.group_id }}"
                    data-group-name="{{ groupName }}"
                    aria-label="Edit group">
                    <i class="bi bi-pencil"></i>
                </button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this group? This can only be done if the group is empty.');">
                    <input type="hidden" name="action" value="delete_group">
                    <input type="hidden" name="group_id" value="{{ groupData.group_id }}">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" class="btn btn-sm btn-outline-danger p-1 lh-1" aria-label="Delete group">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        </div>
        {% for feed in groupData.feeds %}
            {% set count = feedCounts[feed.url]|default(0) %}
            <div class="feed-item list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ currentFeed == feed.feed_id ? 'active' : '' }}">
                <a href="?feed={{ feed.feed_id }}" class="text-decoration-none text-body stretched-link">
                    {{ feed.feed_name }}
                    {% if count > 0 %}<span class="badge bg-primary rounded-pill ms-2">{{ count }}</span>{% endif %}
                </a>
                <div class="btn-feed-action ms-2" style="z-index: 2;">
                    <button type="button" class="btn btn-sm btn-outline-secondary p-1 lh-1"
                        data-bs-toggle="modal" data-bs-target="#editFeedModal"
                        data-feed-id="{{ feed.feed_id }}"
                        data-feed-name="{{ feed.feed_name }}"
                        data-feed-url="{{ feed.url }}"
                        data-feed-group="{{ feed.group_id }}"
                        aria-label="Edit feed">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger p-1 lh-1"
                        data-bs-toggle="modal" data-bs-target="#deleteFeedModal"
                        data-feed-id="{{ feed.feed_id }}"
                        data-feed-name="{{ feed.feed_name }}"
                        aria-label="Delete feed">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        {% endfor %}
    {% endfor %}

    <div class="mt-4 pt-3 border-top">
        <h6 class="text-secondary text-uppercase small fw-semibold mb-3">Management</h6>
        <button type="button" class="btn btn-sm btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addFeedModal">
            <i class="bi bi-plus-circle"></i> Add Feed
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addGroupModal">
            <i class="bi bi-folder-plus"></i> Add Group
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#manageFiltersModal">
            <i class="bi bi-sliders"></i> Manage Filters
        </button>
        <form method="POST" class="mt-2">
            <input type="hidden" name="action" value="import_feeds">
            <input type="hidden" name="force" value="1">
            <button type="submit" class="btn btn-sm btn-success w-100" onclick="return confirm('This will fetch all feeds immediately. Continue?');">
                <i class="bi bi-cloud-download"></i> Import All Feeds
            </button>
        </form>
    </div>
</nav>
TWIG
        );

        // Main content
        $loader->setTemplate(
            'main_content',
            <<<'TWIG'
<main class="p-4">
    {% for type, messages in flashMessages %}
        {% for message in messages %}
            <div class="alert alert-{{ type == 'error' ? 'danger' : 'success' }} alert-dismissible fade show" role="alert">
                {{ message }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        {% endfor %}
    {% endfor %}

    <h2 class="text-dark mb-4 fw-semibold fs-1">{{ pageTitle }}</h2>

    {% if items is empty %}
        {% if searchQuery %}
            <div class="card shadow-sm">
                <div class="card-body text-center text-muted">
                    No results found for "{{ searchQuery }}".
                </div>
            </div>
        {% elseif currentFeed %}
            <div class="card shadow-sm">
                <div class="card-body text-center text-muted">
                    No recent articles found.
                </div>
            </div>
        {% else %}
            <div class="card shadow-sm">
                <div class="card-body text-center text-muted">
                    Select a feed from the sidebar or use the search bar to find articles.
                </div>
            </div>
        {% endif %}
    {% else %}
        {% for item in items %}
            <div class="card mb-3 shadow-sm border-0 border-start border-primary border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2 flex-column flex-sm-row">
                        <h5 class="card-title mb-1 mb-sm-0 me-sm-3">
                            <a href="{{ item.link }}" target="_blank" class="text-dark text-decoration-none item-link fs-5 fw-semibold">
                                {{ item.title|raw }}
                            </a>
                        </h5>
                        <span class="small {{ item.isNew ? 'text-primary fw-bold' : 'text-muted' }} text-nowrap">
                            {{ item.relativeTime }}
                        </span>
                    </div>
                    {% if item.excerpt %}
                        <div class="card-text text-secondary" style="line-height: 1.6;">
                            <div class="excerpt">{{ item.excerpt|raw }}</div>
                        </div>
                    {% endif %}
                    {% if item.feed %}
                        <div class="text-muted small mt-2">
                            <i class="bi bi-rss"></i> {{ item.feed }}
                        </div>
                    {% endif %}
                </div>
            </div>
        {% endfor %}
    {% endif %}
</main>
TWIG
        );

        // Footer
        $loader->setTemplate(
            'footer',
            <<<'TWIG'
<footer class="bg-light border-top py-3 mt-4">
    <div class="container-fluid">
        <div class="row">
            <div class="col text-center text-muted small">
                Memory: {{ memory }} | Time: {{ time }}s
            </div>
        </div>
    </div>
</footer>
TWIG
        );

        // Layout template (full HTML page)
        $loader->setTemplate(
            'layout',
            <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ pageTitle }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
.group-header .btn-group-action,
.feed-item .btn-feed-action,
.filter-item .btn-filter-action {
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
}
.feed-item.active,
.list-group-item.active {
    font-weight: bold;
}
.group-header:hover .btn-group-action,
.feed-item:hover .btn-feed-action,
.filter-item:hover .btn-filter-action {
    opacity: 1;
}
.item-link:hover {
    color: var(--bs-primary) !important;
}
#right {
    display: none;
}
    </style>
</head>
<body>
    {% include 'header' %}

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 p-0">
                {% include 'left_sidebar' %}
            </div>
            <div class="col-md-10">
                {% include 'main_content' %}
            </div>
        </div>
    </div>

    {% include 'footer' %}
    {% include 'modals' %}

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/app.js"></script>
</body>
</html>
TWIG
        );

        // Modals
        $loader->setTemplate(
            'modals',
            <<<'TWIG'
<!-- Add Feed Modal -->
<div class="modal fade" id="addFeedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add Feed</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_feed">
                    <div class="mb-3">
                        <label for="addFeedName" class="form-label">Feed Name *</label>
                        <input type="text" class="form-control" id="addFeedName" name="feed_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="addFeedUrl" class="form-label">Feed URL *</label>
                        <input type="url" class="form-control" id="addFeedUrl" name="feed_url" placeholder="https://" required>
                    </div>
                    <div class="mb-3">
                        <label for="addFeedGroup" class="form-label">Group *</label>
                        <select class="form-select" id="addFeedGroup" name="group_id" required>
                            <option value="">-- Select a group --</option>
                            {% for group in allGroups %}
                                <option value="{{ group.id }}">{{ group.name }}</option>
                            {% endfor %}
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Feed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Feed Modal -->
<div class="modal fade" id="editFeedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Edit Feed</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_feed">
                    <input type="hidden" id="editFeedId" name="feed_id">
                    <div class="mb-3">
                        <label for="editFeedName" class="form-label">Feed Name *</label>
                        <input type="text" class="form-control" id="editFeedName" name="feed_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editFeedUrl" class="form-label">Feed URL *</label>
                        <input type="url" class="form-control" id="editFeedUrl" name="feed_url" required>
                    </div>
                    <div class="mb-3">
                        <label for="editFeedGroup" class="form-label">Group *</label>
                        <select class="form-select" id="editFeedGroup" name="group_id" required>
                            {% for group in allGroups %}
                                <option value="{{ group.id }}">{{ group.name }}</option>
                            {% endfor %}
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Feed Modal -->
<div class="modal fade" id="deleteFeedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Delete Feed</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the feed <strong id="deleteFeedName"></strong>?</p>
                <p class="text-muted small">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete_feed">
                    <input type="hidden" id="deleteFeedId" name="feed_id">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" class="btn btn-danger">Delete Feed</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add Group</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_group">
                    <div class="mb-3">
                        <label for="addGroupName" class="form-label">Group Name *</label>
                        <input type="text" class="form-control" id="addGroupName" name="group_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Group</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Group Modal -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_group">
                    <input type="hidden" id="editGroupId" name="group_id">
                    <div class="mb-3">
                        <label for="editGroupName" class="form-label">Group Name *</label>
                        <input type="text" class="form-control" id="editGroupName" name="group_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Filters Modal -->
<div class="modal fade" id="manageFiltersModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Filters</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                {% if allFilters is empty %}
                    <p class="text-muted">No filters added yet.</p>
                {% else %}
                    <div class="list-group">
                        {% for filter in allFilters %}
                            <div class="filter-item list-group-item d-flex justify-content-between align-items-center">
                                <span>{{ filter.filter_string }}</span>
                                <div class="btn-filter-action">
                                    <button type="button" class="btn btn-sm btn-outline-secondary me-2"
                                        data-bs-toggle="modal" data-bs-target="#editFilterModal"
                                        data-filter-id="{{ filter.id }}"
                                        data-filter-string="{{ filter.filter_string }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal" data-bs-target="#deleteFilterModal"
                                        data-filter-id="{{ filter.id }}"
                                        data-filter-string="{{ filter.filter_string }}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        {% endfor %}
                    </div>
                {% endif %}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFilterModal">
                    <i class="bi bi-plus-circle"></i> Add New Filter
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Filter Modal -->
<div class="modal fade" id="addFilterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add Filter</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_filter">
                    <div class="mb-3">
                        <label for="addFilterString" class="form-label">Filter String *</label>
                        <input type="text" class="form-control" id="addFilterString" name="filter_string" required>
                        <small class="form-text text-muted">Matches whole words in titles and content (case-insensitive).</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Filter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Filter Modal -->
<div class="modal fade" id="editFilterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Filter</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_filter">
                    <input type="hidden" id="editFilterId" name="filter_id">
                    <div class="mb-3">
                        <label for="editFilterString" class="form-label">Filter String *</label>
                        <input type="text" class="form-control" id="editFilterString" name="filter_string" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Filter Modal -->
<div class="modal fade" id="deleteFilterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Delete Filter</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the filter <strong id="deleteFilterName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete_filter">
                    <input type="hidden" id="deleteFilterId" name="filter_id">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" class="btn btn-danger">Delete Filter</button>
                </form>
            </div>
        </div>
    </div>
</div>
TWIG
        );
    }
}
