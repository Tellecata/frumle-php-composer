<?php

declare(strict_types=1);

namespace Frumle;

/**
 * Write .github/workflows/frumle.yml and one-time CI setup prompt.
 */
class GithubAction
{
    public const WORKFLOW_RELATIVE_PATH = '.github/workflows/frumle.yml';

    private static function workflowContents(?string $projectName = null): string
    {
        $hint = $projectName !== null && $projectName !== ''
            ? "# Local project name when generated: {$projectName}\n"
            : '';

        // ${{ }} must appear literally in the YAML; escape $ for PHP double-quoted strings via concat.
        $secret = '${{ secrets.FRUMLE_API_KEY }}';
        $repo = '${{ github.repository }}';

        return <<<YAML
{$hint}# Frumle — regenerate API docs on every push to main/master.
# Setup: gh secret set FRUMLE_API_KEY
# A green job means analysis was queued; docs appear in the dashboard shortly after.
# https://frumle.com/dashboard
name: Frumle Docs

on:
  push:
    branches: [main, master]
  workflow_dispatch:

jobs:
  docs:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"

      - name: Install Frumle
        run: composer global require frumle/frumle

      - name: Generate docs with Frumle
        env:
          FRUMLE_API_KEY: {$secret}
        run: |
          export PATH="$(composer global config bin-dir --absolute):\$PATH"
          frumle . --project-name "{$repo}" --skip-config-write --json

YAML;
    }

    public static function getWorkflowPath(string $projectDir): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::WORKFLOW_RELATIVE_PATH);
    }

    public static function workflowExists(string $projectDir): bool
    {
        return is_file(self::getWorkflowPath($projectDir));
    }

    /**
     * Write .github/workflows/frumle.yml. Does not overwrite unless $force is true.
     *
     * @return array{status: 'created'|'exists'|'overwritten', path: string}
     */
    public static function writeGithubWorkflow(
        string $projectDir,
        ?string $projectName = null,
        bool $force = false
    ): array {
        $filePath = self::getWorkflowPath($projectDir);
        $existed = is_file($filePath);

        if ($existed && !$force) {
            return ['status' => 'exists', 'path' => $filePath];
        }

        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        if (file_put_contents($filePath, self::workflowContents($projectName)) === false) {
            throw new \RuntimeException("Failed to write workflow: {$filePath}");
        }

        $config = FrumleConfig::load($projectDir);
        if (!empty($config['ciPromptDeclined'])) {
            unset($config['ciPromptDeclined']);
            FrumleConfig::save($projectDir, $config);
        }

        return [
            'status' => $existed ? 'overwritten' : 'created',
            'path' => $filePath,
        ];
    }

    public static function setCiPromptDeclined(string $projectDir): void
    {
        $config = FrumleConfig::load($projectDir);
        $config['ciPromptDeclined'] = true;
        FrumleConfig::save($projectDir, $config);
    }

    public static function printCiSetupInstructions(string $workflowPath): void
    {
        $cwd = getcwd() ?: '';
        $rel = self::WORKFLOW_RELATIVE_PATH;
        if ($cwd !== '' && str_starts_with($workflowPath, $cwd)) {
            $rel = ltrim(substr($workflowPath, strlen($cwd)), DIRECTORY_SEPARATOR) ?: self::WORKFLOW_RELATIVE_PATH;
        }

        echo "\n✅ GitHub Action added: {$rel}\n";
        echo "\n📋 One more step — add your Frumle API key as a GitHub secret:\n";
        echo "\n   gh secret set FRUMLE_API_KEY\n";
        echo "\n   Paste your API key when prompted, then commit and push the workflow file.\n";
        echo "   Docs will regenerate on every push to main.\n";
        echo "   Dashboard: https://frumle.com/dashboard\n\n";
    }

    /**
     * After a successful interactive analyze: offer to add the GitHub Action once.
     * Skips when non-interactive, CI, already installed, or previously declined.
     */
    public static function maybePrompt(string $projectDir, string $projectName, bool $interactive): void
    {
        if (!$interactive) {
            return;
        }
        if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            return;
        }
        $ci = getenv('CI');
        if ($ci === 'true' || $ci === '1') {
            return;
        }
        if (self::workflowExists($projectDir)) {
            return;
        }

        $config = FrumleConfig::load($projectDir);
        if (!empty($config['ciPromptDeclined'])) {
            return;
        }

        echo "\n" . str_repeat('─', 60) . "\n";
        echo "⚙️  Keep docs up to date automatically?\n";
        echo "   Frumle can add a GitHub Action so docs refresh on every push to main.\n";

        echo 'Add .github/workflows/frumle.yml now? (Y/n) [Y]: ';
        $line = fgets(STDIN);
        if ($line === false) {
            return;
        }
        $answer = strtolower(trim($line));
        $yes = $answer === '' || $answer === 'y' || $answer === 'yes';

        if ($yes) {
            $result = self::writeGithubWorkflow($projectDir, $projectName);
            if ($result['status'] === 'exists') {
                echo "\nℹ️  Workflow already exists at {$result['path']}\n";
                echo "\n   gh secret set FRUMLE_API_KEY\n\n";
            } else {
                self::printCiSetupInstructions($result['path']);
            }
            return;
        }

        self::setCiPromptDeclined($projectDir);
        echo "\n👍 OK — skipped for this project.\n";
        echo "   Without CI, you will need to run `frumle` yourself whenever docs should update.\n";
        echo "   Changed your mind later? Run: frumle ci\n\n";
    }

    /**
     * Backup command: add the workflow without analyzing.
     */
    public static function installCli(
        string $projectDir,
        ?string $projectName = null,
        bool $force = false
    ): void {
        $result = self::writeGithubWorkflow($projectDir, $projectName, $force);
        if ($result['status'] === 'exists') {
            echo "\nℹ️  Workflow already exists: {$result['path']}\n";
            echo "   Use --force to overwrite it.\n";
            echo "\n   gh secret set FRUMLE_API_KEY\n\n";
            return;
        }
        self::printCiSetupInstructions($result['path']);
    }
}
