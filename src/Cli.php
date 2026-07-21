<?php

declare(strict_types=1);

namespace Frumle;

/**
 * CLI command handler for the Frumle PHP package.
 * Commands: add-key, login, status, ci, analyze (default).
 */
class Cli
{
    public const VERSION = '0.3.0';

    private const EXIT_OK = 0;
    private const EXIT_ERROR = 1;
    private const EXIT_AUTH = 2;
    private const EXIT_QUOTA = 3;

    public function run(array $argv): void
    {
        $args = array_slice($argv, 1);

        $knownCommands = ['add-key', 'login', 'status', 'ci', 'help', '--help', '-h', '--version', '-v'];

        if (empty($args) || $args[0] === 'analyze' || ($args[0][0] !== '-' && !in_array($args[0], $knownCommands, true))) {
            $this->cmdAnalyze($args);
            return;
        }

        $command = array_shift($args);

        switch ($command) {
            case 'add-key':
            case 'login':
                $this->cmdAddKey($args);
                break;
            case 'status':
                $this->cmdStatus();
                break;
            case 'ci':
                $this->cmdCi($args);
                break;
            case '--version':
            case '-v':
                echo 'frumle ' . self::VERSION . PHP_EOL;
                break;
            case 'help':
            case '--help':
            case '-h':
                $this->showHelp();
                break;
            default:
                $this->error("Unknown command: {$command}");
                $this->showHelp();
                exit(self::EXIT_ERROR);
        }
    }

    private function cmdAddKey(array $args): void
    {
        if (empty($args)) {
            $this->error('Usage: frumle add-key <api-key>');
            exit(self::EXIT_ERROR);
        }

        $apiKey = trim($args[0]);
        if (strlen($apiKey) < 10) {
            $this->error('Invalid API key format');
            echo "   API key must be at least 10 characters long\n";
            exit(self::EXIT_ERROR);
        }

        echo "🔐 Verifying API key with server...\n";

        try {
            $api = new ApiClient();
            $status = $api->verifyApiKey($apiKey);
        } catch (\Throwable $e) {
            $this->error("Failed to add API key: {$e->getMessage()}");
            echo "\n💡 Make sure:\n";
            echo "   1. Your API key is correct\n";
            echo "   2. You registered at the Frumle dashboard\n";
            echo "   3. You have an internet connection\n";
            exit(self::EXIT_AUTH);
        }

        Config::setApiKey($apiKey);

        echo "\n✅ API key added successfully!\n";

        $quota = $status['quota'] ?? [];
        echo "\n📊 Quota: " . ($quota['analysesPerMonth'] ?? '?') . " analyses per month\n";
        echo "📈 Used: " . ($quota['used'] ?? '?') . " / " . ($quota['analysesPerMonth'] ?? '?') . "\n";
        echo "📉 Remaining: " . ($quota['remaining'] ?? '?') . "\n";
        echo "\n💡 You can now run: frumle\n";
    }

    private function cmdStatus(): void
    {
        try {
            $api = new ApiClient();
            $status = $api->checkStatus();
        } catch (\Throwable $e) {
            $this->error("Status check failed: {$e->getMessage()}");
            exit($this->classifyError($e->getMessage()));
        }

        echo "\n📊 Account Status\n";
        echo str_repeat('━', 40) . "\n";
        echo "API Key: " . ($status['apiKey'] ?? '') . "...\n";

        $quota = $status['quota'] ?? [];
        echo "\nQuota:\n";
        echo "  Total: " . ($quota['analysesPerMonth'] ?? '?') . " analyses/month\n";
        echo "  Used: " . ($quota['used'] ?? '?') . "\n";
        echo "  Remaining: " . ($quota['remaining'] ?? '?') . "\n";

        $usage = $status['usage'] ?? [];
        echo "\nUsage:\n";
        echo "  Total Analyses: " . ($usage['totalAnalyses'] ?? '?') . "\n";
        if (!empty($usage['lastAnalysisAt'])) {
            echo "  Last Analysis: " . $usage['lastAnalysisAt'] . "\n";
        }
    }

    private function cmdCi(array $args): void
    {
        $directory = null;
        $projectName = null;
        $force = false;

        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === '--project-name' && isset($args[$i + 1])) {
                $projectName = $args[++$i];
            } elseif ($args[$i] === '--force') {
                $force = true;
            } elseif ($args[$i][0] !== '-' && $directory === null) {
                $directory = $args[$i];
            }
        }

        $directory = $directory ?? getcwd();
        $targetDir = realpath($directory);

        if ($targetDir === false || !is_dir($targetDir)) {
            $this->error("Directory \"{$directory}\" does not exist");
            exit(self::EXIT_ERROR);
        }

        try {
            GithubAction::installCli($targetDir, $projectName, $force);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            exit(self::EXIT_ERROR);
        }
    }

    private function cmdAnalyze(array $args): void
    {
        // Strip 'analyze' if it's the first arg
        if (!empty($args) && $args[0] === 'analyze') {
            array_shift($args);
        }

        $directory = null;
        $projectName = null;
        $ignore = null;
        $skipConfigWrite = false;
        $asJson = false;
        $quiet = false;

        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === '--project-name' && isset($args[$i + 1])) {
                $projectName = $args[++$i];
            } elseif ($args[$i] === '--ignore' && isset($args[$i + 1])) {
                $ignore = $args[++$i];
            } elseif ($args[$i] === '--skip-config-write') {
                $skipConfigWrite = true;
            } elseif ($args[$i] === '--json') {
                $asJson = true;
            } elseif ($args[$i] === '--quiet') {
                $quiet = true;
            } elseif ($args[$i][0] !== '-' && $directory === null) {
                $directory = $args[$i];
            }
        }

        $quiet = $quiet || $asJson;

        $log = static function (string ...$parts) use ($quiet): void {
            if (!$quiet) {
                echo implode('', $parts) . "\n";
            }
        };

        $directory = $directory ?? getcwd();
        $targetDir = realpath($directory);

        if ($targetDir === false || !is_dir($targetDir)) {
            $this->finish(
                self::EXIT_ERROR,
                ['error' => "Directory does not exist: {$directory}", 'code' => self::EXIT_ERROR],
                $asJson,
                ["❌ Error: Directory \"{$directory}\" does not exist"]
            );
        }

        $apiKey = Config::getApiKey();
        if ($apiKey === null) {
            $this->finish(
                self::EXIT_AUTH,
                [
                    'error' => 'No API key found',
                    'code' => self::EXIT_AUTH,
                    'hint' => 'Set FRUMLE_API_KEY or run: frumle add-key <your-api-key>',
                ],
                $asJson,
                [
                    "\n❌ No API key found!",
                    "\n💡 To get started:",
                    '   1. Register at the Frumle dashboard',
                    '   2. Set FRUMLE_API_KEY, or',
                    '   3. Add your API key: frumle add-key <your-api-key>',
                    '   4. Or use: frumle login <your-api-key>',
                ]
            );
        }

        $log('🚀 Starting codebase analysis...');
        $log("📁 Directory: {$targetDir}");
        $log('');

        $log('📂 Scanning files...');

        $ignoreDirs = $ignore !== null
            ? array_map('trim', explode(',', $ignore))
            : ['vendor', 'node_modules', '.git', 'storage', 'cache', 'var', 'tmp', 'temp', 'logs', 'dist', 'build', 'runtime', 'assets'];

        $fileExtensions = ['php', 'json', 'yaml', 'yml', 'xml', 'md', 'env', 'neon', 'ini', 'twig'];

        $files = Scanner::readCodebase($targetDir, $ignoreDirs, $fileExtensions);

        if (empty($files)) {
            $this->finish(
                self::EXIT_ERROR,
                ['error' => 'No files found to analyze', 'code' => self::EXIT_ERROR, 'directory' => $targetDir],
                $asJson,
                ["\n❌ No files found to analyze"]
            );
        }

        $log('✅ Found ' . count($files) . ' files');
        $log('');

        if ($projectName === null) {
            $composerPath = $targetDir . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($composerPath)) {
                $composer = json_decode(file_get_contents($composerPath) ?: '', true);
                if (is_array($composer) && !empty($composer['name'])) {
                    $projectName = $composer['name'];
                }
            }
            if ($projectName === null) {
                $projectName = basename($targetDir) ?: 'unknown-project';
            }
        }
        $projectName = trim($projectName) ?: 'unknown-project';

        $ci = getenv('CI');
        $writeConfig = !$skipConfigWrite && $ci !== 'true' && $ci !== '1';
        $log('🔍 Detecting base URLs...');
        $baseUrls = FrumleConfig::initialize($targetDir, $writeConfig);
        if (!$writeConfig) {
            $log('ℹ️  Skipping frumle.config.json write (CI or --skip-config-write)');
        }

        if (!empty($baseUrls) && !$quiet) {
            foreach ($baseUrls as $entry) {
                if (($entry['environment'] ?? '') === 'local' && !empty($entry['url'])) {
                    $log("✅ Local URL detected: {$entry['url']}");
                }
            }
            $hasProd = false;
            foreach ($baseUrls as $entry) {
                if (($entry['environment'] ?? '') === 'production' && !empty($entry['url'])) {
                    $hasProd = true;
                }
            }
            if (!$hasProd) {
                $log('💡 Tip: Add production URL in frumle.config.json to test APIs in production');
            }
        }

        $log("📦 Project: {$projectName}");
        $log('🤖 Analyzing with AI...');

        try {
            $api = new ApiClient();
            $response = $api->analyzeCodebase([
                'files'          => array_map(fn(array $f) => [
                    'path'         => $f['path'],
                    'relativePath' => $f['relativePath'],
                    'content'      => $f['content'],
                ], $files),
                'directory'      => $targetDir,
                'projectName'    => $projectName,
                'ignoreDirs'     => $ignoreDirs,
                'fileExtensions' => $fileExtensions,
                'baseUrls'       => !empty($baseUrls) ? $baseUrls : null,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $code = $this->classifyError($msg);
            $human = ["\n❌ Analysis error: {$msg}"];
            if ($code === self::EXIT_AUTH) {
                $human[] = "\n💡 Authentication issue. Set FRUMLE_API_KEY or run: frumle add-key <your-api-key>";
            } elseif ($code === self::EXIT_QUOTA) {
                $human[] = "\n💡 Quota or billing issue. Check status: frumle status";
            }
            $this->finish($code, ['error' => $msg, 'code' => $code], $asJson, $human);
        }

        $status = $response['status'] ?? null;
        $result = $response['result'] ?? null;

        $offerCi = static function () use ($asJson, $quiet, $targetDir, $projectName): void {
            if ($asJson || $quiet) {
                return;
            }
            GithubAction::maybePrompt($targetDir, $projectName, true);
        };

        if ($status === 'processing' || $result === null) {
            $fileCount = $response['fileCount'] ?? count($files);
            if ($asJson) {
                $this->finish(self::EXIT_OK, [
                    'status' => 'processing',
                    'directory' => $targetDir,
                    'projectName' => $projectName,
                    'fileCount' => $fileCount,
                    'quotaRemaining' => $response['quota']['remaining'] ?? null,
                    'message' => 'Analysis queued. Docs will appear in the dashboard shortly.',
                    'code' => self::EXIT_OK,
                ], true);
            }

            $log("\n" . str_repeat('=', 60));
            $log('✅ ANALYSIS STARTED');
            $log(str_repeat('=', 60));
            $log("\n📁 Directory: {$targetDir}");
            $log("📄 Files queued: {$fileCount}");

            if (isset($response['quota'])) {
                $log("\n📊 Quota:");
                $log('   - Remaining: ' . ($response['quota']['remaining'] ?? '?') . ' analyses');
            }

            $log("\n🔄 Analysis in progress...");
            $log('📝 Your documentation will be available in your dashboard shortly.');
            $log('🌐 Check your dashboard at: https://frumle.com');
            $log(str_repeat('─', 60));
            $offerCi();
            return;
        }

        if ($asJson) {
            $this->finish(self::EXIT_OK, [
                'status' => 'complete',
                'directory' => $targetDir,
                'projectName' => $projectName,
                'framework' => $result['framework'] ?? null,
                'stats' => $result['stats'] ?? null,
                'quotaRemaining' => $response['quota']['remaining'] ?? null,
                'code' => self::EXIT_OK,
            ], true);
        }

        $log("\n" . str_repeat('=', 60));
        $log('📊 ANALYSIS RESULTS');
        $log(str_repeat('=', 60));

        if (!empty($result['framework'])) {
            $log("\n🎯 Framework: {$result['framework']}");
        }

        $stats = $result['stats'] ?? [];
        $log("\n📈 Statistics:");
        $log('   - Total Files: ' . ($stats['totalFiles'] ?? '?'));
        $log('   - Analyzed: ' . ($stats['analyzedFiles'] ?? '?'));
        $log('   - Total Chunks: ' . ($stats['totalChunks'] ?? '?'));

        if (isset($response['quota'])) {
            $log("\n📊 Quota:");
            $log('   - Remaining: ' . ($response['quota']['remaining'] ?? '?') . ' analyses');
        }

        $log("\n📝 Summary:");
        $log(str_repeat('-', 60));
        $log($result['summary'] ?? '');
        $log(str_repeat('-', 60));
        $log("\n✅ Analysis complete! Results saved to the dashboard.");
        $offerCi();
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $humanLines
     */
    private function finish(int $code, array $payload, bool $asJson, ?array $humanLines = null): void
    {
        if ($asJson) {
            $payload['ok'] = $code === self::EXIT_OK;
            echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        } elseif ($humanLines !== null) {
            foreach ($humanLines as $line) {
                echo $line . (str_ends_with($line, "\n") ? '' : "\n");
            }
        }
        exit($code);
    }

    private function classifyError(string $message): int
    {
        $m = strtolower($message);
        if (str_contains($m, '401') || str_contains($m, 'unauthorized') || str_contains($m, 'invalid api key')) {
            return self::EXIT_AUTH;
        }
        if (
            str_contains($m, '429')
            || str_contains($m, 'quota')
            || str_contains($m, '402')
            || str_contains($m, 'payment')
            || str_contains($m, 'subscription')
        ) {
            return self::EXIT_QUOTA;
        }
        return self::EXIT_ERROR;
    }

    private function showHelp(): void
    {
        echo <<<HELP

frumle - AI-powered codebase analyzer for PHP

Usage:
  frumle [directory]                  Analyze a codebase (default: current directory)
  frumle analyze [directory]          Same as above
  frumle add-key <api-key>            Add your API key
  frumle login <api-key>              Login with API key (alias for add-key)
  frumle status                       Check API key status and quota
  frumle ci [--force] [directory]     Add GitHub Action for auto-docs on push

Options:
  --project-name <name>               Project name (defaults to composer.json name or directory)
  --ignore <dirs>                     Comma-separated directories to ignore
  --skip-config-write                 Do not write/update frumle.config.json (also default when CI=true)
  --json                              Print a single JSON result (implies quiet)
  --quiet                             Suppress human-readable progress logs
  --version, -v                       Show version
  --help, -h                          Show this help

Supported PHP Frameworks:
  Laravel, Symfony, CodeIgniter 4+, CakePHP, Yii, Laminas, Slim, Phalcon

Examples:
  frumle                              Analyze current directory
  frumle ./src                        Analyze src directory
  frumle --project-name my-api        Analyze with custom project name
  frumle --ignore tests,storage       Ignore tests and storage directories
  frumle . --skip-config-write --json CI / unattended mode
  frumle ci                           Add .github/workflows/frumle.yml

HELP;
    }

    private function error(string $message): void
    {
        echo "\n❌ {$message}\n";
    }
}
