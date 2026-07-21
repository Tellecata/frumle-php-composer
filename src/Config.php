<?php

declare(strict_types=1);

namespace Frumle;

/**
 * Manages user-level configuration at ~/.frumle/config.json.
 * Backward-compatible with legacy ~/.dev-doc/config.json.
 */
class Config
{
    private static function getConfigDir(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        if ($home === '') {
            $home = posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
        }
        return $home . DIRECTORY_SEPARATOR . '.frumle';
    }

    private static function getConfigFile(): string
    {
        return self::getConfigDir() . DIRECTORY_SEPARATOR . 'config.json';
    }

    private static function getLegacyConfigFile(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        return $home . DIRECTORY_SEPARATOR . '.dev-doc' . DIRECTORY_SEPARATOR . 'config.json';
    }

    public static function load(): array
    {
        $configFile = self::getConfigFile();
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        $legacyFile = self::getLegacyConfigFile();
        if (file_exists($legacyFile)) {
            $content = file_get_contents($legacyFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        return [];
    }

    public static function save(array $config): void
    {
        $dir = self::getConfigDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $existing = self::load();
        $merged = array_merge($existing, $config);

        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode config as JSON');
        }

        if (file_put_contents(self::getConfigFile(), $json) === false) {
            throw new \RuntimeException('Failed to save config to ' . self::getConfigFile());
        }
    }

    /**
     * Get API key from FRUMLE_API_KEY env (preferred) or ~/.frumle/config.json
     */
    public static function getApiKey(): ?string
    {
        $envKey = getenv('FRUMLE_API_KEY');
        if (is_string($envKey)) {
            $envKey = trim($envKey);
            if ($envKey !== '') {
                return $envKey;
            }
        }

        $config = self::load();
        return $config['apiKey'] ?? null;
    }

    public static function setApiKey(string $apiKey): void
    {
        self::save(['apiKey' => $apiKey]);
    }
}
