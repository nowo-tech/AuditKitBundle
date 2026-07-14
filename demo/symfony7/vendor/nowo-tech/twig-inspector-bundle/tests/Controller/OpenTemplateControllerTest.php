<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\Controller;

use Nowo\TwigInspectorBundle\Controller\OpenTemplateController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TemplateWrapper;

/**
 * Tests for OpenTemplateController.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class OpenTemplateControllerTest extends TestCase
{
    private Environment $twig;
    private FileLinkFormatter $fileLinkFormatter;
    private OpenTemplateController $controller;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->fileLinkFormatter = $this->createMock(FileLinkFormatter::class);
        $this->controller = new OpenTemplateController($this->twig, $this->fileLinkFormatter);
    }

    public function testInvokeWithDefaultLine(): void
    {
        $request = new Request();
        $template = 'test.html.twig';

        // Create a real Twig environment with ArrayLoader to create real TemplateWrapper
        $loader = new ArrayLoader([$template => 'test content']);
        $realTwig = new Environment($loader);
        $templateWrapper = $realTwig->load($template);

        $this->twig->expects($this->once())
          ->method('load')
          ->with($template)
          ->willReturn($templateWrapper);

        $this->fileLinkFormatter->expects($this->once())
          ->method('format')
          ->with($this->anything(), 1)
          ->willReturn('phpstorm://open?file=/path/to/test.html.twig&line=1');

        $response = ($this->controller)($request, $template);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('phpstorm://open?file=/path/to/test.html.twig&line=1', $response->getTargetUrl());
    }

    public function testInvokeWithCustomLine(): void
    {
        $request = new Request(['line' => 42]);
        $template = 'test.html.twig';

        // Create a real Twig environment with ArrayLoader to create real TemplateWrapper
        $loader = new ArrayLoader([$template => 'test content']);
        $realTwig = new Environment($loader);
        $templateWrapper = $realTwig->load($template);

        $this->twig->expects($this->once())
          ->method('load')
          ->with($template)
          ->willReturn($templateWrapper);

        $this->fileLinkFormatter->expects($this->once())
          ->method('format')
          ->with($this->anything(), 42)
          ->willReturn('phpstorm://open?file=/path/to/test.html.twig&line=42');

        $response = ($this->controller)($request, $template);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('phpstorm://open?file=/path/to/test.html.twig&line=42', $response->getTargetUrl());
    }

    public function testInvokeWithStringLine(): void
    {
        $request = new Request(['line' => '100']);
        $template = 'test.html.twig';

        // Create a real Twig environment with ArrayLoader to create real TemplateWrapper
        $loader = new ArrayLoader([$template => 'test content']);
        $realTwig = new Environment($loader);
        $templateWrapper = $realTwig->load($template);

        $this->twig->expects($this->once())
          ->method('load')
          ->with($template)
          ->willReturn($templateWrapper);

        $this->fileLinkFormatter->expects($this->once())
          ->method('format')
          ->with($this->anything(), 100)
          ->willReturn('phpstorm://open?file=/path/to/test.html.twig&line=100');

        $response = ($this->controller)($request, $template);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    // Security validation tests

    public function testInvokeRejectsPathTraversalWithDoubleDot(): void
    {
        $request = new Request();
        $template = '../etc/passwd';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('path traversal detected');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsPathTraversalWithMultipleDots(): void
    {
        $request = new Request();
        $template = '../../../../etc/passwd';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('path traversal detected');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsNullByte(): void
    {
        $request = new Request();
        $template = "test\x00.html.twig";

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('path traversal detected');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsAbsolutePathUnix(): void
    {
        $request = new Request();
        $template = '/etc/passwd';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('absolute paths are not allowed');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsAbsolutePathWindows(): void
    {
        $request = new Request();
        $template = 'C:\\Windows\\System32\\config\\sam';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('absolute paths are not allowed');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsEmptyTemplateName(): void
    {
        $request = new Request();
        $template = '';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Template name cannot be empty');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsWhitespaceOnlyTemplateName(): void
    {
        $request = new Request();
        $template = '   ';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Template name cannot be empty');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsNegativeLineNumber(): void
    {
        $request = new Request(['line' => -1]);
        $template = 'test.html.twig';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Line number must be a positive integer');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsZeroLineNumber(): void
    {
        $request = new Request(['line' => 0]);
        $template = 'test.html.twig';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Line number must be a positive integer');

        ($this->controller)($request, $template);
    }

    public function testInvokeRejectsNonNumericLineNumber(): void
    {
        $request = new Request(['line' => 'invalid']);
        $template = 'test.html.twig';

        // getInt() returns 0 for invalid input, which should be rejected
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Line number must be a positive integer');

        ($this->controller)($request, $template);
    }

    public function testInvokeThrowsNotFoundWhenTemplateDoesNotExist(): void
    {
        $request = new Request();
        $template = 'nonexistent.html.twig';

        $this->twig->expects($this->once())
          ->method('load')
          ->with($template)
          ->willThrowException(new LoaderError('Template not found'));

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Template "nonexistent.html.twig" not found');

        ($this->controller)($request, $template);
    }

    public function testInvokeValidatesFilePathWithFilesystemLoader(): void
    {
        // Create a temporary directory for templates
        $tempDir = sys_get_temp_dir() . '/twig_inspector_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        $templateFile = $tempDir . '/test.html.twig';
        file_put_contents($templateFile, 'test content');

        try {
            $loader = new FilesystemLoader([$tempDir]);
            $twig = new Environment($loader);
            $templateWrapper = $twig->load('test.html.twig');

            // Create a new controller with real Twig environment
            $realFileLinkFormatter = $this->createMock(FileLinkFormatter::class);
            $realFileLinkFormatter->expects($this->once())
              ->method('format')
              ->with($this->stringContains($tempDir), 1)
              ->willReturn('phpstorm://open?file=' . $templateFile . '&line=1');

            $realController = new OpenTemplateController($twig, $realFileLinkFormatter);

            $request = new Request();
            $response = ($realController)($request, 'test.html.twig');

            $this->assertInstanceOf(RedirectResponse::class, $response);
        } finally {
            // Cleanup
            if (file_exists($templateFile)) {
                unlink($templateFile);
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testInvokeRejectsFilePathOutsideAllowedDirectories(): void
    {
        // Create a temporary directory for templates
        $allowedDir = sys_get_temp_dir() . '/twig_inspector_allowed_' . uniqid();
        $outsideDir = sys_get_temp_dir() . '/twig_inspector_outside_' . uniqid();
        mkdir($allowedDir, 0777, true);
        mkdir($outsideDir, 0777, true);

        $outsideFile = $outsideDir . '/outside.html.twig';
        file_put_contents($outsideFile, 'outside content');

        try {
            $loader = new FilesystemLoader([$allowedDir]);
            $twig = new Environment($loader);

            // Create a mock template wrapper that returns a path outside allowed directories
            $templateWrapper = $this->createMock(TemplateWrapper::class);
            $sourceContext = $this->createMock(\Twig\Source::class);
            $sourceContext->expects($this->once())
              ->method('getPath')
              ->willReturn($outsideFile);

            $templateWrapper->expects($this->once())
              ->method('getSourceContext')
              ->willReturn($sourceContext);

            // Create a mock Twig environment that returns our loader and template wrapper
            $mockTwig = $this->createMock(Environment::class);
            $mockTwig->expects($this->once())
              ->method('load')
              ->with('test.html.twig')
              ->willReturn($templateWrapper);

            $mockTwig->expects($this->once())
              ->method('getLoader')
              ->willReturn($loader);

            $mockFileLinkFormatter = $this->createMock(FileLinkFormatter::class);
            $mockController = new OpenTemplateController($mockTwig, $mockFileLinkFormatter);

            $request = new Request();

            $this->expectException(BadRequestException::class);
            $this->expectExceptionMessage('Template file is outside allowed Twig template directories');

            ($mockController)($request, 'test.html.twig');
        } finally {
            // Cleanup
            if (file_exists($outsideFile)) {
                unlink($outsideFile);
            }
            if (is_dir($outsideDir)) {
                rmdir($outsideDir);
            }
            if (is_dir($allowedDir)) {
                rmdir($allowedDir);
            }
        }
    }

    public function testInvokeWorksWithArrayLoader(): void
    {
        // ArrayLoader doesn't have getPaths(), so validation should pass
        $request = new Request();
        $template = 'test.html.twig';

        $loader = new ArrayLoader([$template => 'test content']);
        $realTwig = new Environment($loader);
        $templateWrapper = $realTwig->load($template);

        $this->twig->expects($this->once())
          ->method('load')
          ->with($template)
          ->willReturn($templateWrapper);

        $this->twig->expects($this->once())
          ->method('getLoader')
          ->willReturn($loader);

        $this->fileLinkFormatter->expects($this->once())
          ->method('format')
          ->with($this->anything(), 1)
          ->willReturn('phpstorm://open?file=/path/to/test.html.twig&line=1');

        $response = ($this->controller)($request, $template);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }
}
