<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\Command;

use Nowo\TwigInspectorBundle\Command\InstallCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests for InstallCommand.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class InstallCommandTest extends TestCase
{
    private string $testProjectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->testProjectDir = sys_get_temp_dir() . '/twig_inspector_test_' . uniqid();
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->testProjectDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->testProjectDir)) {
            $this->filesystem->remove($this->testProjectDir);
        }
    }

    public function testCommandNameAndDescription(): void
    {
        $command = new InstallCommand();
        $this->assertSame('nowo:twig-inspector:install', $command->getName());
        $this->assertSame('Creates the Twig Inspector Bundle configuration file', $command->getDescription());
    }

    public function testExecuteCreatesConfigFileInDevEnvironment(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
        $this->assertFileExists($configFile);
        $this->assertStringContainsString('nowo_twig_inspector:', file_get_contents($configFile));
        $this->assertStringContainsString('enabled_extensions:', file_get_contents($configFile));
        $this->assertSame(0, $commandTester->getStatusCode());
        $this->assertStringContainsString('Configuration file created successfully', $commandTester->getDisplay());
    }

    public function testExecuteCreatesConfigFileInTestEnvironment(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--env' => 'test']);

        $configFile = $this->testProjectDir . '/config/packages/test/nowo_twig_inspector.yaml';
        $this->assertFileExists($configFile);
        $this->assertStringContainsString('nowo_twig_inspector:', file_get_contents($configFile));
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteCreatesConfigFileInProdEnvironment(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--env' => 'prod']);

        $configFile = $this->testProjectDir . '/config/packages/prod/nowo_twig_inspector.yaml';
        $this->assertFileExists($configFile);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteCreatesDirectoryIfNotExists(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $configDir = $this->testProjectDir . '/config/packages/dev';
        $this->assertDirectoryDoesNotExist($configDir);

        $commandTester->execute([]);

        $this->assertDirectoryExists($configDir);
        $this->assertStringContainsString('Created directory:', $commandTester->getDisplay());
    }

    public function testExecuteDoesNotCreateDirectoryIfExists(): void
    {
        $configDir = $this->testProjectDir . '/config/packages/dev';
        $this->filesystem->mkdir($configDir);

        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertDirectoryExists($configDir);
        $this->assertStringNotContainsString('Created directory:', $commandTester->getDisplay());
    }

    public function testExecuteWithExistingFileAndNoForcePromptsForConfirmation(): void
    {
        $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
        $this->filesystem->dumpFile($configFile, 'existing content');

        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        // Simulate "no" answer
        $commandTester->setInputs(['no']);
        $commandTester->execute([]);

        $this->assertStringContainsString('Configuration file already exists', $commandTester->getDisplay());
        $this->assertStringContainsString('Installation cancelled', $commandTester->getDisplay());
        $this->assertSame('existing content', file_get_contents($configFile));
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithExistingFileAndForceOverwrites(): void
    {
        $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
        $this->filesystem->dumpFile($configFile, 'existing content');

        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--force' => true]);

        $this->assertStringContainsString('Configuration file created successfully', $commandTester->getDisplay());
        $this->assertStringContainsString('nowo_twig_inspector:', file_get_contents($configFile));
        $this->assertStringNotContainsString('existing content', file_get_contents($configFile));
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithExistingFileAndYesAnswerOverwrites(): void
    {
        $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
        $this->filesystem->dumpFile($configFile, 'existing content');

        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        // Simulate "yes" answer
        $commandTester->setInputs(['yes']);
        $commandTester->execute([]);

        $this->assertStringContainsString('Configuration file created successfully', $commandTester->getDisplay());
        $this->assertStringContainsString('nowo_twig_inspector:', file_get_contents($configFile));
        $this->assertStringNotContainsString('existing content', file_get_contents($configFile));
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithNullProjectDirUsesCurrentWorkingDirectory(): void
    {
        $originalCwd = getcwd();
        try {
            chdir($this->testProjectDir);

            $command = new InstallCommand(null);
            $application = new Application();
            $application->add($command);
            $commandTester = new CommandTester($command);

            $commandTester->execute([]);

            $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
            $this->assertFileExists($configFile);
            $this->assertSame(0, $commandTester->getStatusCode());
        } finally {
            chdir($originalCwd);
        }
    }

    public function testConfigFileContainsAllExpectedOptions(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
        $content = file_get_contents($configFile);

        $this->assertStringContainsString('enabled_extensions:', $content);
        $this->assertStringContainsString("'.html.twig'", $content);
        $this->assertStringContainsString('excluded_templates:', $content);
        $this->assertStringContainsString('excluded_blocks:', $content);
        $this->assertStringContainsString('enable_metrics:', $content);
        $this->assertStringContainsString('optimize_output_buffering:', $content);
        $this->assertStringContainsString('cookie_name:', $content);
        $this->assertStringContainsString("'twig_inspector_is_active'", $content);
    }

    public function testConfigFileContainsHelpfulComments(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $configFile = $this->testProjectDir . '/config/packages/dev/nowo_twig_inspector.yaml';
        $content = file_get_contents($configFile);

        $this->assertStringContainsString('# Twig Inspector Bundle Configuration', $content);
        $this->assertStringContainsString('# This file was automatically generated', $content);
        $this->assertStringContainsString('# Default:', $content);
    }

    public function testHelpTextIsDisplayed(): void
    {
        $command = new InstallCommand($this->testProjectDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--help']);

        $this->assertStringContainsString('Creates the Twig Inspector Bundle configuration file', $commandTester->getDisplay());
        $this->assertStringContainsString('--env', $commandTester->getDisplay());
        $this->assertStringContainsString('--force', $commandTester->getDisplay());
    }
}

