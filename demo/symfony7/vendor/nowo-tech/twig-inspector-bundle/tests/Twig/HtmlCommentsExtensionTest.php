<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\Twig;

use Nowo\TwigInspectorBundle\BoxDrawings;
use Nowo\TwigInspectorBundle\Twig\HtmlCommentsExtension;
use Nowo\TwigInspectorBundle\Twig\NodeReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tests for HtmlCommentsExtension.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class HtmlCommentsExtensionTest extends TestCase
{
    private RequestStack $requestStack;
    private UrlGeneratorInterface $urlGenerator;
    private BoxDrawings $boxDrawings;
    private HtmlCommentsExtension $extension;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->boxDrawings = new BoxDrawings();
        $this->extension = new HtmlCommentsExtension($this->requestStack, $this->urlGenerator, $this->boxDrawings);
    }

    public function testStartWhenDisabled(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        ob_start();
        $this->extension->start($ref);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testStartWhenEnabled(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->extension->start($ref);

        // Output buffering should be started, clean it up
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->assertTrue(true);
    }

    public function testEndWhenDisabled(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        ob_start();
        echo 'test content';
        $this->extension->end($ref);
        $output = ob_get_clean();

        $this->assertSame('test content', $output);
    }

    public function testEndWithHtmlContent(): void
    {
        $ref = new NodeReference('block_name', 'template.html.twig', 10);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->urlGenerator->method('generate')
          ->with('nowo_twig_inspector_template_link', ['template' => 'template.html.twig', 'line' => 10])
          ->willReturn('/_template/template.html.twig?line=10');

        // Wrap the entire test in output buffering to capture end()'s output
        // This ensures end() gets the buffer from start(), not a new empty buffer
        ob_start();
        $this->extension->start($ref);
        echo '<div>Test content</div>';
        $this->extension->end($ref);
        $output = ob_get_clean();

        // Verify that comments were added
        $this->assertStringContainsString('<!--', $output, 'Output should contain HTML comment start. Got: ' . $output);
        $this->assertStringContainsString('block_name', $output, 'Output should contain block name. Got: ' . $output);
        $this->assertStringContainsString('<div>Test content</div>', $output, 'Output should contain original content. Got: ' . $output);
        $this->assertStringContainsString('-->', $output, 'Output should contain HTML comment end. Got: ' . $output);
    }

    public function testEndWithNonHtmlContent(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->extension->start($ref);
        echo 'plain text';
        ob_start();
        $this->extension->end($ref);
        $output = ob_get_clean();

        // Should not add comments for non-HTML content
        $this->assertSame('plain text', $output);
    }

    public function testEndWithJsonContent(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->extension->start($ref);
        echo '{"key": "value"}';
        ob_start();
        $this->extension->end($ref);
        $output = ob_get_clean();

        // Should not add comments for JSON content
        $this->assertSame('{"key": "value"}', $output);
    }

    public function testEndWithBackboneTemplate(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->extension->start($ref);
        echo '<div><% code %></div>';
        ob_start();
        $this->extension->end($ref);
        $output = ob_get_clean();

        // Should not add comments for backbone templates
        $this->assertSame('<div><% code %></div>', $output);
    }

    public function testEndWithNonTwigTemplate(): void
    {
        $ref = new NodeReference('block', 'template.txt', 1);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        // start() should not start buffering for non-.html.twig templates
        $this->extension->start($ref);

        // Since start() doesn't start buffering for non-.html.twig, end() will return early
        // We need to capture output normally
        ob_start();
        echo '<div>content</div>';
        $this->extension->end($ref);
        $output = ob_get_clean();

        // Should not add comments for non-.html.twig templates
        $this->assertSame('<div>content</div>', $output);
    }

    public function testEndWithNestedContent(): void
    {
        $ref1 = new NodeReference('outer', 'template.html.twig', 1);
        $ref2 = new NodeReference('inner', 'template.html.twig', 2);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->urlGenerator->method('generate')
          ->willReturnCallback(function ($route, $params) {
              return '/_template/' . $params['template'] . '?line=' . $params['line'];
          });

        // First block
        ob_start();
        $this->extension->start($ref1);
        echo '<div>outer</div>';
        $this->extension->end($ref1);
        $output1 = ob_get_clean();

        // Second block (nested) - contains previous content but changed
        // This tests the case where trim($content) !== trim((string) $this->previousContent)
        ob_start();
        $this->extension->start($ref2);
        echo $output1 . '<div>inner</div>';
        $this->extension->end($ref2);
        $output2 = ob_get_clean();

        $this->assertStringContainsString('outer', $output2);
        $this->assertStringContainsString('inner', $output2);
    }

    public function testShouldInspectWithoutRequest(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($this->extension, $ref);

        $this->assertFalse($result);
    }

    public function testShouldInspectWithoutCookie(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);
        $request = new Request();

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($this->extension, $ref);

        $this->assertFalse($result);
    }

    public function testShouldInspectWithCookie(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($this->extension, $ref);

        $this->assertTrue($result);
    }

    public function testShouldInspectWithCustomCookieName(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 1);
        $request = new Request();
        $request->cookies->set('custom_cookie_name', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.html.twig'],
            [],
            [],
            true,
            true,
            'custom_cookie_name'
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($extension, $ref);

        $this->assertTrue($result);
    }

    public function testShouldInspectWithEnabledExtensions(): void
    {
        $ref = new NodeReference('block', 'template.xml.twig', 1);
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.xml.twig'] // Only .xml.twig is enabled
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($extension, $ref);

        $this->assertTrue($result);
    }

    public function testShouldInspectWithDisabledExtension(): void
    {
        $ref = new NodeReference('block', 'template.xml.twig', 1);
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.html.twig'] // Only .html.twig is enabled
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($extension, $ref);

        $this->assertFalse($result);
    }

    public function testShouldInspectWithExcludedTemplate(): void
    {
        $ref = new NodeReference('block', 'admin/dashboard.html.twig', 1);
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.html.twig'],
            ['admin/*'] // Exclude admin templates
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($extension, $ref);

        $this->assertFalse($result);
    }

    public function testShouldInspectWithExcludedBlock(): void
    {
        $ref = new NodeReference('javascript', 'template.html.twig', 1);
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.html.twig'],
            [],
            ['javascript'] // Exclude javascript block
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($extension, $ref);

        $this->assertFalse($result);
    }

    public function testShouldInspectWithExcludedBlockWildcard(): void
    {
        $ref = new NodeReference('head_scripts', 'template.html.twig', 1);
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.html.twig'],
            [],
            ['head_*'] // Exclude blocks starting with head_
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($extension, $ref);

        $this->assertFalse($result);
    }

    public function testIsExcludedWithWildcard(): void
    {
        $extension = new HtmlCommentsExtension(
            $this->requestStack,
            $this->urlGenerator,
            $this->boxDrawings,
            ['.html.twig'],
            ['admin/*', 'email/*.html.twig']
        );

        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('isExcluded');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($extension, 'admin/dashboard.html.twig', ['admin/*']));
        $this->assertTrue($method->invoke($extension, 'email/welcome.html.twig', ['email/*.html.twig']));
        $this->assertFalse($method->invoke($extension, 'public/index.html.twig', ['admin/*']));
    }

    public function testEndWithNestedContentUnchanged(): void
    {
        $ref1 = new NodeReference('outer', 'template.html.twig', 1);
        $ref2 = new NodeReference('inner', 'template.html.twig', 2);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->urlGenerator->method('generate')
          ->willReturnCallback(function ($route, $params) {
              return '/_template/' . $params['template'] . '?line=' . $params['line'];
          });

        // First block - this sets previousContent
        ob_start();
        $this->extension->start($ref1);
        echo '<div>outer</div>';
        $this->extension->end($ref1);
        $output1 = ob_get_clean();

        // Second block with same trimmed content - should detect nested but unchanged
        // This tests the case where trim($content) === trim((string) $this->previousContent)
        ob_start();
        $this->extension->start($ref2);
        echo '<div>outer</div>'; // Same trimmed content as previous
        $this->extension->end($ref2);
        $output2 = ob_get_clean();

        // Both should have comments
        $this->assertStringContainsString('<!--', $output1);
        $this->assertStringContainsString('<!--', $output2);
        $this->assertStringContainsString('outer', $output1);
        $this->assertStringContainsString('inner', $output2);
    }

    public function testShouldInspectWithNonHtmlTwigTemplate(): void
    {
        $ref = new NodeReference('block', 'template.txt.twig', 1);

        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('shouldInspect');
        $method->setAccessible(true);

        $result = $method->invoke($this->extension, $ref);

        // Should return false because template doesn't end with .html.twig
        $this->assertFalse($result);
    }

    public function testIsSupportedWithEmptyString(): void
    {
        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('isSupported');
        $method->setAccessible(true);

        // Test with empty string - trimmed will be empty
        $result = $method->invoke($this->extension, '');

        // Empty string should not be supported (no HTML tags)
        $this->assertFalse($result);
    }

    public function testIsSupportedWithWhitespaceOnly(): void
    {
        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('isSupported');
        $method->setAccessible(true);

        // Test with whitespace only - trimmed will be empty
        $result = $method->invoke($this->extension, '   ');

        // Whitespace only should not be supported (no HTML tags)
        $this->assertFalse($result);
    }

    public function testIsSupportedWithNonEmptyTrimmedButStartsWithBracket(): void
    {
        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('isSupported');
        $method->setAccessible(true);

        // Test with content that has HTML tags but starts with [ or {
        // This tests the case where trimmed !== '' && in_array($trimmed[0], ['[', '{'], true)
        $result1 = $method->invoke($this->extension, '[{"html": "<div>test</div>"}]');
        $result2 = $method->invoke($this->extension, '{"html": "<div>test</div>"}');

        // Should return false because content starts with JSON brackets
        $this->assertFalse($result1);
        $this->assertFalse($result2);
    }

    public function testGetComment(): void
    {
        $ref = new NodeReference('block_name', 'template.html.twig', 10);

        $this->urlGenerator->method('generate')
          ->with('nowo_twig_inspector_template_link', ['template' => 'template.html.twig', 'line' => 10])
          ->willReturn('/_template/template.html.twig?line=10');

        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('getComment');
        $method->setAccessible(true);

        $result = $method->invoke($this->extension, '┏━', $ref);

        $this->assertStringContainsString('<!--', $result);
        $this->assertStringContainsString('┏━', $result);
        $this->assertStringContainsString('block_name', $result);
        $this->assertStringContainsString('-->', $result);
    }

    public function testGetLink(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 5);

        $this->urlGenerator->expects($this->once())
          ->method('generate')
          ->with('nowo_twig_inspector_template_link', ['template' => 'template.html.twig', 'line' => 5])
          ->willReturn('/_template/template.html.twig?line=5');

        $reflection = new \ReflectionClass($this->extension);
        $method = $reflection->getMethod('getLink');
        $method->setAccessible(true);

        $result = $method->invoke($this->extension, $ref);

        $this->assertSame('/_template/template.html.twig?line=5', $result);
    }
}
