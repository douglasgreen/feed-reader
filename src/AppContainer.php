<?php

namespace DouglasGreen\FeedReader;

use PDO;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Application dependency injection container (singleton)
 */
final class AppContainer
{
    private static ?self $instance = null;

    private array $config;
    private ?PDO $pdo = null;
    private ?Request $request = null;
    private ?Session $session = null;
    private ?Environment $twig = null;
    private float $startTime;

    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->loadConfig();
        $this->setupTimezone();
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConfig(string $key = null): mixed
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
        if ($this->pdo === null) {
            $dbConfig = $this->config['parameters']['database'];

            try {
                $this->pdo = new PDO(
                    $dbConfig['dsn'],
                    $dbConfig['username'],
                    $dbConfig['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            } catch (\PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return $this->pdo;
    }

    public function getRequest(): Request
    {
        if ($this->request === null) {
            $this->request = Request::createFromGlobals();
        }
        return $this->request;
    }

    public function getSession(): Session
    {
        if ($this->session === null) {
            $this->session = new Session();
            if (!$this->session->isStarted()) {
                $this->session->start();
            }
        }
        return $this->session;
    }

    public function getTwig(): Environment
    {
        if ($this->twig === null) {
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

    public function getStartTime(): float
    {
        return $this->startTime;
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
            throw new \RuntimeException('Configuration file not found: ' . $configFile);
        }
        $this->config = Yaml::parseFile($configFile);
    }

    private function setupTimezone(): void
    {
        $timezone = $this->config['parameters']['timezone'] ?? 'UTC';
        date_default_timezone_set($timezone);
    }
}
