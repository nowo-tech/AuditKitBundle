<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Controller;

use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\TemplateWrapper;

/**
 * Open Twig template in an IDE by template name at the line.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class OpenTemplateController
{
    /**
     * Constructor.
     *
     * @param Environment       $twig              The Twig environment
     * @param FileLinkFormatter $fileLinkFormatter The file link formatter
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly FileLinkFormatter $fileLinkFormatter
    ) {
    }

    /**
     * Opens a Twig template in the IDE.
     * Loads the template, gets its file path, and generates an IDE link using the file link formatter.
     *
     * @param Request $request  The request object containing the optional 'line' query parameter
     * @param string  $template The template name to open
     *
     * @throws BadRequestException  When the template name is invalid or contains path traversal
     * @throws NotFoundHttpException When the template cannot be found
     * @throws LoaderError           When the template cannot be loaded
     * @throws RuntimeError          When a runtime error occurs
     * @throws SyntaxError           When a syntax error is found in the template
     *
     * @return RedirectResponse Redirect response to the IDE with the file path and line number
     */
    public function __invoke(Request $request, string $template): RedirectResponse
    {
        // Security: Validate template name to prevent path traversal attacks
        $this->validateTemplateName($template);

        // Validate and sanitize line number
        $line = $request->query->getInt('line', 1);
        if ($line < 1) {
            throw new BadRequestException('Line number must be a positive integer.');
        }

        try {
            /** @var TemplateWrapper $templateWrapper */
            $templateWrapper = $this->twig->load($template);
            $file = $templateWrapper->getSourceContext()->getPath();

            // Additional security: Verify the resolved file path is within allowed directories
            $this->validateFilePath($file);
        } catch (LoaderError $e) {
            throw new NotFoundHttpException(sprintf('Template "%s" not found.', $template), $e);
        }

        $url = $this->fileLinkFormatter->format($file, $line);

        return new RedirectResponse($url);
    }

    /**
     * Validates the template name to prevent path traversal attacks.
     *
     * @param string $template The template name to validate
     *
     * @throws BadRequestException When the template name is invalid
     *
     * @return void
     */
    private function validateTemplateName(string $template): void
    {
        // Reject empty template names
        if ('' === trim($template)) {
            throw new BadRequestException('Template name cannot be empty.');
        }

        // Reject path traversal attempts
        if (str_contains($template, '..') || str_contains($template, "\0")) {
            throw new BadRequestException('Invalid template name: path traversal detected.');
        }

        // Reject absolute paths
        if (str_starts_with($template, '/') || preg_match('/^[a-zA-Z]:\\\\/', $template)) {
            throw new BadRequestException('Invalid template name: absolute paths are not allowed.');
        }
    }

    /**
     * Validates that the resolved file path is within allowed Twig template directories.
     *
     * @param string $filePath The resolved file path
     *
     * @throws BadRequestException When the file path is outside allowed directories
     *
     * @return void
     */
    private function validateFilePath(string $filePath): void
    {
        // Normalize the file path first
        $realFilePath = realpath($filePath);
        if (false === $realFilePath) {
            throw new BadRequestException('Template file path could not be resolved.');
        }

        // Get all Twig template paths from the loader
        $loader = $this->twig->getLoader();
        $paths = [];

        // FilesystemLoader has getPaths() method
        if ($loader instanceof FilesystemLoader) {
            $paths = $loader->getPaths();
        } else {
            // For other loaders (ArrayLoader, etc.), we rely on Twig's own security
            // Twig will only load templates that are registered in the loader
            // The validateTemplateName() method already prevents path traversal
            // So if we got here, the template was successfully loaded by Twig
            return;
        }

        // Check if the file is within any of the allowed Twig paths
        $isValid = false;
        foreach ($paths as $path) {
            $realPath = realpath($path);
            if (false !== $realPath && str_starts_with($realFilePath, $realPath)) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            throw new BadRequestException('Template file is outside allowed Twig template directories.');
        }
    }
}
