<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Twig;

use Nowo\TwigInspectorBundle\BoxDrawings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;

/**
 * Adds comments before and after every Twig block and template.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class HtmlCommentsExtension extends AbstractExtension
{
    private ?string $previousContent = null;

    private int $nestingLevel = 0;

    /**
     * Constructor.
     *
     * @param RequestStack          $requestStack      The request stack
     * @param UrlGeneratorInterface $urlGenerator      The URL generator
     * @param BoxDrawings           $boxDrawings      The box drawings helper
     * @param array<string>         $enabledExtensions List of template extensions to inspect
     * @param array<string>         $excludedTemplates  List of template names/patterns to exclude
     * @param array<string>         $excludedBlocks     List of block names/patterns to exclude
     * @param string                $cookieName        Name of the cookie to check
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly BoxDrawings $boxDrawings,
        private readonly array $enabledExtensions = ['.html.twig'],
        private readonly array $excludedTemplates = [],
        private readonly array $excludedBlocks = [],
        private readonly string $cookieName = 'twig_inspector_is_active'
    ) {
    }

    /**
     * Starts output buffering for a node.
     * Only starts buffering if the inspector is enabled and the node should be inspected.
     *
     * @param NodeReference $ref The node reference
     *
     * @return void
     */
    public function start(NodeReference $ref): void
    {
        if (!$this->shouldInspect($ref)) {
            return;
        }

        ob_start();
    }

    /**
     * Ends output buffering and adds comments.
     * Wraps the captured content with HTML comments containing template information.
     * Handles nested blocks by tracking nesting levels and updating box drawing styles.
     *
     * @param NodeReference $ref The node reference
     *
     * @return void
     */
    public function end(NodeReference $ref): void
    {
        if (!$this->shouldInspect($ref)) {
            // If buffering wasn't started in start(), content was already output normally
            // Just return without doing anything
            return;
        }

        // Check if output buffering is actually active
        if (ob_get_level() === 0) {
            return;
        }

        $content = ob_get_clean();

        if ($this->isSupported($content)) {
            // Check if this is a nested block (content contains previous content)
            if ((string) $this->previousContent !== '' && str_contains($content, (string) $this->previousContent)) {
                // If content changed, update box drawing style
                if (trim($content) !== trim((string) $this->previousContent)) {
                    $this->boxDrawings->blockChanged($this->nestingLevel);
                }

                ++$this->nestingLevel;
            } else {
                // Reset nesting level for new top-level block
                $this->nestingLevel = 0;
                $this->boxDrawings->blockChanged($this->nestingLevel);
            }

            // Wrap content with start and end comments
            $content = $this->getStartComment($ref) . $content . $this->getEndComment($ref);

            $this->previousContent = $content;
        }

        echo $content;
    }

    /**
     * Checks if the inspector should inspect the given node.
     * The inspector is enabled when:
     * - A request is available
     * - The cookie is set to true
     * - The template file extension is in the enabled extensions list
     * - The template is not in the excluded templates list
     * - The block is not in the excluded blocks list
     *
     * @param NodeReference $ref The node reference
     *
     * @return bool True if should inspect, false otherwise
     */
    protected function shouldInspect(NodeReference $ref): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request || !$request->cookies->getBoolean($this->cookieName)) {
            return false;
        }

        $template = $ref->getTemplate();

        // Check if template extension is enabled
        $extensionMatches = false;
        foreach ($this->enabledExtensions as $extension) {
            if (str_ends_with($template, $extension)) {
                $extensionMatches = true;
                break;
            }
        }

        if (!$extensionMatches) {
            return false;
        }

        // Check if template is excluded
        if ($this->isExcluded($template, $this->excludedTemplates)) {
            return false;
        }

        // Check if block is excluded
        $blockName = $ref->getName();
        if ($blockName !== $template && $this->isExcluded($blockName, $this->excludedBlocks)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if a name matches any exclusion pattern.
     *
     * @param string        $name     The name to check
     * @param array<string> $patterns  List of patterns (supports wildcards with *)
     *
     * @return bool True if excluded, false otherwise
     */
    private function isExcluded(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            // Support wildcard patterns
            $regex = '/^' . str_replace(['*', '.'], ['.*', '\.'], $pattern) . '$/';
            if (preg_match($regex, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the content is supported for inspection.
     *
     * @param string $string The content string
     *
     * @return bool True if supported, false otherwise
     */
    /**
     * Checks if the content is supported for inspection.
     * Only HTML content is supported, not plain text, JSON, or Backbone templates.
     *
     * @param string $string The content string
     *
     * @return bool True if supported, false otherwise
     */
    protected function isSupported(string $string): bool
    {
        // Check if content has HTML tags (if strip_tags returns the same string, it's plain text)
        if ($string === strip_tags($string)) {
            return false;
        }

        // Check if content starts with JSON brackets (faster than json_decode)
        $trimmed = trim($string);
        if ($trimmed !== '' && \in_array($trimmed[0], ['[', '{'], true)) {
            return false;
        }

        // Check if content is a Backbone template (contains <% %>)
        return !str_contains($string, '<%');
    }

    /**
     * Gets the start comment for a node.
     *
     * @param NodeReference $ref The node reference
     *
     * @return string The start comment
     */
    private function getStartComment(NodeReference $ref): string
    {
        $prefix = $this->boxDrawings->getStartCommentPrefix();

        return $this->getComment($prefix, $ref);
    }

    /**
     * Gets the end comment for a node.
     *
     * @param NodeReference $ref The node reference
     *
     * @return string The end comment
     */
    private function getEndComment(NodeReference $ref): string
    {
        $prefix = $this->boxDrawings->getEndCommentPrefix();

        return $this->getComment($prefix, $ref);
    }

    /**
     * Gets a comment string for a node.
     *
     * @param string        $prefix The comment prefix
     * @param NodeReference $ref    The node reference
     *
     * @return string The comment string
     */
    protected function getComment(string $prefix, NodeReference $ref): string
    {
        $link = $this->getLink($ref);

        return '<!-- ' . $prefix . ' ' . $ref->getName() . ' [' . $link . '] #' . $ref->getId() . '-->';
    }

    /**
     * Gets the link URL for a node.
     *
     * @param NodeReference $ref The node reference
     *
     * @return string The link URL
     */
    protected function getLink(NodeReference $ref): string
    {
        return $this->urlGenerator->generate(
            'nowo_twig_inspector_template_link',
            [
            'template' => $ref->getTemplate(),
            'line' => $ref->getLine(),
      ]
        );
    }
}
