<?php

declare(strict_types=1);

namespace S3\Tunnel\Http\Response;

use Laminas\Diactoros\Response\HtmlResponse;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

class ViewModel extends HtmlResponse
{
    /**
     * @param string $template
     * @param array<string, mixed> $context
     */
    public function __construct(string $template, array $context = [])
    {
        try {
            $loader = new FilesystemLoader(__DIR__ . '/../../../templates');
            $twig = new Environment($loader);
            $html = $twig->render("$template.html.twig", $context);
        } catch (LoaderError | RuntimeError | SyntaxError) {
            $html = '';
        }

        parent::__construct($html);
    }
}
