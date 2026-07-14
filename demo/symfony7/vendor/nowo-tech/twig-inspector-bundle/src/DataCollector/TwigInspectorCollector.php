<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;
use Throwable;

/**
 * Data collector for Twig Inspector Bundle.
 * Collects template usage statistics and provides them to the Web Profiler.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class TwigInspectorCollector implements DataCollectorInterface
{
    private array $data = [
        'templates' => [],
        'blocks' => [],
        'total_templates' => 0,
        'total_blocks' => 0,
        'enabled' => false,
    ];

    /**
     * Constructor.
     *
     * @param RequestStack $requestStack The request stack
     */
    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * Collects data for the given request and response.
     * Analyzes the response content to extract template usage statistics.
     *
     * @param Request        $request   The request object
     * @param Response       $response  The response object
     * @param Throwable|null $exception The exception if any
     *
     * @return void
     */
    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $cookieName = 'twig_inspector_is_active';
        $this->data['enabled'] = $request->cookies->getBoolean($cookieName, false);

        if (!$this->data['enabled']) {
            return;
        }

        $content = $response->getContent();
        if (false === $content) {
            return;
        }

        // Extract template information from HTML comments
        $pattern = '/<!--\s+([┏━╭─╔═┎─┗━╰─╚═┖─]+)\s+([^\s]+)\s+\[([^\]]+)\]\s+#(\w+)-->/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $templates = [];
        $blocks = [];

        foreach ($matches as $match) {
            $name = $match[2];
            $link = $match[3];
            $id = $match[4];

            // Extract template name from link
            if (preg_match('/\/_template\/([^?]+)/', $link, $linkMatch)) {
                $templateName = urldecode($linkMatch[1]);

                // Determine if it's a template or a block
                // Blocks have the same name as the template, templates have different names
                if ($name === $templateName) {
                    // It's a template
                    if (!isset($templates[$templateName])) {
                        $templates[$templateName] = [
                            'name' => $templateName,
                            'count' => 0,
                            'ids' => [],
                        ];
                    }
                    $templates[$templateName]['count']++;
                    $templates[$templateName]['ids'][] = $id;
                } else {
                    // It's a block
                    $blockKey = $templateName . '::' . $name;
                    if (!isset($blocks[$blockKey])) {
                        $blocks[$blockKey] = [
                            'template' => $templateName,
                            'name' => $name,
                            'count' => 0,
                            'ids' => [],
                        ];
                    }
                    $blocks[$blockKey]['count']++;
                    $blocks[$blockKey]['ids'][] = $id;
                }
            }
        }

        $this->data['templates'] = array_values($templates);
        $this->data['blocks'] = array_values($blocks);
        $this->data['total_templates'] = count($templates);
        $this->data['total_blocks'] = count($blocks);
    }

    /**
     * Resets the data collector.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->data = [
            'templates' => [],
            'blocks' => [],
            'total_templates' => 0,
            'total_blocks' => 0,
            'enabled' => false,
        ];
    }

    /**
     * Returns the name of the collector.
     *
     * @return string The collector name
     */
    public function getName(): string
    {
        return 'twig_inspector';
    }

    /**
     * Gets the collected data.
     *
     * @return array<string, mixed> The collected data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Gets template usage statistics.
     *
     * @return array<string, mixed> Template statistics
     */
    public function getTemplates(): array
    {
        return $this->data['templates'] ?? [];
    }

    /**
     * Gets block usage statistics.
     *
     * @return array<string, mixed> Block statistics
     */
    public function getBlocks(): array
    {
        return $this->data['blocks'] ?? [];
    }

    /**
     * Gets total number of unique templates.
     *
     * @return int Total templates
     */
    public function getTotalTemplates(): int
    {
        return $this->data['total_templates'] ?? 0;
    }

    /**
     * Gets total number of unique blocks.
     *
     * @return int Total blocks
     */
    public function getTotalBlocks(): int
    {
        return $this->data['total_blocks'] ?? 0;
    }

    /**
     * Checks if inspector is enabled for this request.
     *
     * @return bool True if enabled
     */
    public function isEnabled(): bool
    {
        return $this->data['enabled'] ?? false;
    }
}
