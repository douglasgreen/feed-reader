<?php

declare(strict_types=1);

namespace DouglasGreen\FeedReader;

use Exception;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Application dependency injection container (singleton)
 */
final class AppContainer
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $config;

    private bool $cli = false;

    private ?PDO $pdo = null;

    private ?Request $request = null;

    private ?Session $session = null;

    private ?Environment $twig = null;

    private readonly float $startTime;

    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->loadConfig();
        $this->setupTimezone();
    }

    public function __wakeup()
    {
        throw new Exception('Cannot unserialize singleton');
    }

    public static function getInstance(?bool $cli = null): self
    {
        if (!self::$instance instanceof \DouglasGreen\FeedReader\AppContainer) {
            self::$instance = new self();
        }

        if ($cli !== null) {
            self::$instance->cli = $cli;
        }

        return self::$instance;
    }

    public function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config['parameters'];
        }

        $keys = explode('.', $key);
        $value = $this->config['parameters'];

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }

            $value = $value[$k];
        }

        return $value;
    }

    public function getPdo(): PDO
    {
        if (!$this->pdo instanceof PDO) {
            $dbConfig = $this->config['parameters']['database'];

            try {
                $this->pdo = new PDO(
                    $dbConfig['dsn'],
                    $dbConfig['username'],
                    $dbConfig['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ],
                );
            } catch (PDOException $e) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), $e->getCode(), $e);
            }
        }

        return $this->pdo;
    }

    public function getRequest(): Request
    {
        if (!$this->request instanceof Request) {
            $this->request = Request::createFromGlobals();
        }

        return $this->request;
    }

    public function getSession(): Session
    {
        if (!$this->session instanceof Session) {
            if ($this->cli || PHP_SAPI === 'cli') {
                // Use Mock storage for CLI (cron jobs, terminal, CGI cron invocations)
                $this->session = new Session(new MockArraySessionStorage());
            } else {
                // Use Native storage for Web requests
                $this->session = new Session(new NativeSessionStorage());
            }

            if (!$this->session->isStarted()) {
                $this->session->start();
            }
        }

        return $this->session;
    }

    public function getTwig(): Environment
    {
        if (!$this->twig instanceof Environment) {
            $loader = new ArrayLoader();
            $cacheDir = __DIR__ . '/../' . $this->config['parameters']['cache']['twig_cache_dir'];

            // Ensure cache directory exists
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            $this->twig = new Environment($loader, [
                'cache' => $cacheDir,
                'auto_reload' => true,
                'autoescape' => 'html',
            ]);
        }

        return $this->twig;
    }

    public function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function getMemoryUsage(): string
    {
        $bytes = memory_get_peak_usage(true);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../config/parameters.yml';
        if (!file_exists($configFile)) {
            throw new RuntimeException('Configuration file not found: ' . $configFile);
        }

        $this->config = Yaml::parseFile($configFile);
    }

    private function setupTimezone(): void
    {
        $timezone = $this->config['parameters']['timezone'] ?? 'UTC';
        date_default_timezone_set($timezone);
    }
}
