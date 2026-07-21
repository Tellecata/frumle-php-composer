<?php

declare(strict_types=1);

namespace Frumle;

/**
 * Manages project-level frumle.config.json and detects local base URLs
 * for PHP frameworks (Laravel, Symfony, CodeIgniter, CakePHP, Slim, Yii, Laminas, Phalcon).
 */
class FrumleConfig
{
    private const CONFIG_NAME = 'frumle.config.json';

    public static function getConfigPath(string $projectDir): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CONFIG_NAME;
    }

    public static function load(string $projectDir): array
    {
        $path = self::getConfigPath($projectDir);
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path) ?: '', true);
            if (is_array($data)) {
                return $data;
            }
        }

        $legacy = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'devdoc.config.json';
        if (file_exists($legacy)) {
            $data = json_decode(file_get_contents($legacy) ?: '', true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    public static function save(string $projectDir, array $config): void
    {
        $path = self::getConfigPath($projectDir);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Failed to save " . self::CONFIG_NAME);
        }
    }

    /**
     * Detect the local development server URL by inspecting common PHP framework config patterns.
     */
    public static function detectLocalUrl(string $projectDir): string
    {
        $root = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $port = null;

        $isLaravel = file_exists($root . '/artisan');

        // 1. Check .env files (highest priority — used by Laravel, Symfony, etc.)
        foreach (['.env', '.env.local', '.env.development', '.env.dev'] as $envFile) {
            $envPath = $root . DIRECTORY_SEPARATOR . $envFile;
            if (file_exists($envPath)) {
                $content = file_get_contents($envPath) ?: '';
                // APP_PORT or SERVER_PORT or PORT
                if (preg_match('/(?:APP_PORT|SERVER_PORT|PORT)\s*=\s*(\d+)/i', $content, $m)) {
                    $port = (int) $m[1];
                    break;
                }
                // APP_URL with an explicit port (e.g. http://localhost:8080)
                if (preg_match('/APP_URL\s*=\s*(https?:\/\/[^:\s]+):(\d+)/i', $content, $m)) {
                    $port = (int) $m[2];
                    break;
                }
                // APP_URL without a port (e.g. http://localhost)
                // Laravel's artisan serve defaults to 8000, so don't return bare localhost
                if (preg_match('/APP_URL\s*=\s*(https?:\/\/[^\s]+)/i', $content, $m)) {
                    $appUrl = rtrim($m[1], '/');
                    if ($isLaravel && preg_match('/^https?:\/\/localhost\/?$/', $appUrl)) {
                        $port = 8000;
                        break;
                    }
                    return $appUrl;
                }
            }
        }

        if ($port !== null) {
            return "http://localhost:{$port}";
        }

        // 2. Check composer.json scripts for serve commands with port
        $composerPath = $root . DIRECTORY_SEPARATOR . 'composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath) ?: '', true);
            if (is_array($composer)) {
                $scripts = $composer['scripts'] ?? [];
                foreach ($scripts as $script) {
                    $scriptStr = is_array($script) ? implode(' ', $script) : (string) $script;
                    if (preg_match('/--port[=\s]+(\d+)|:(\d+)/', $scriptStr, $m)) {
                        $port = (int) ($m[1] ?: $m[2]);
                        break;
                    }
                }
            }
        }

        if ($port !== null) {
            return "http://localhost:{$port}";
        }

        // 3. Framework-specific config files
        // Symfony: check config/packages/framework.yaml or .env
        $symfonyConfig = $root . '/config/packages/framework.yaml';
        if (file_exists($symfonyConfig)) {
            // Symfony defaults to 8000 with symfony serve
            return 'http://localhost:8000';
        }

        // CodeIgniter 4: app/Config/App.php
        $ci4Config = $root . '/app/Config/App.php';
        if (file_exists($ci4Config)) {
            $content = file_get_contents($ci4Config) ?: '';
            if (preg_match("/baseURL\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                return $m[1];
            }
        }

        // CakePHP: config/app.php or config/app_local.php
        $cakeConfig = $root . '/config/app.php';
        if (file_exists($cakeConfig) && file_exists($root . '/src/Application.php')) {
            return 'http://localhost:8765';
        }

        // Yii: config/web.php or web/index.php
        if (file_exists($root . '/config/web.php') || file_exists($root . '/web/index.php')) {
            return 'http://localhost:8080';
        }

        // Laminas/Mezzio: config/autoload/
        if (is_dir($root . '/config/autoload') && file_exists($root . '/config/config.php')) {
            return 'http://localhost:8080';
        }

        // Phalcon: check for .htrouter.php or app/config/config.php
        if (file_exists($root . '/.htrouter.php') || file_exists($root . '/app/config/config.php')) {
            return 'http://localhost:8000';
        }

        // Laravel artisan serve defaults to port 8000
        if (file_exists($root . '/artisan')) {
            return 'http://localhost:8000';
        }

        // Slim: check for public/index.php with Slim references
        $publicIndex = $root . '/public/index.php';
        if (file_exists($publicIndex)) {
            $content = file_get_contents($publicIndex) ?: '';
            if (stripos($content, 'Slim') !== false || stripos($content, 'slim') !== false) {
                return 'http://localhost:8080';
            }
        }

        // Default PHP built-in server port
        return 'http://localhost:8000';
    }

    /**
     * Ensure frumle.config.json exists with baseUrls; return baseUrls list.
     * Pass $write = false for CI / --skip-config-write so the repo stays clean.
     * When writing, merges into existing config (preserves ciPromptDeclined and other fields).
     *
     * @return list<array{environment: string, url: string}>
     */
    public static function initialize(string $projectDir, bool $write = true): array
    {
        $existing = self::load($projectDir);
        $baseUrls = $existing['baseUrls'] ?? [];

        $localUrl = self::detectLocalUrl($projectDir);

        $byEnv = [];
        foreach ($baseUrls as $i => $entry) {
            if (isset($entry['environment'])) {
                $byEnv[$entry['environment']] = $i;
            }
        }

        if (isset($byEnv['local'])) {
            $baseUrls[$byEnv['local']]['url'] = $localUrl;
        } else {
            $baseUrls[] = ['environment' => 'local', 'url' => $localUrl];
        }

        if (!isset($byEnv['production'])) {
            $baseUrls[] = ['environment' => 'production', 'url' => ''];
        }

        if ($write) {
            $merged = $existing;
            $merged['baseUrls'] = $baseUrls;
            self::save($projectDir, $merged);
        }

        return $baseUrls;
    }
}
