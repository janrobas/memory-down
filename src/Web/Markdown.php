<?php

declare(strict_types=1);

namespace MemoryDown\Web;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Safe Markdown -> HTML for the admin preview.
 *
 * Raw HTML in the source is escaped (never executed) and unsafe links are
 * disabled, so rendering a memory can not inject scripts into the UI.
 */
final class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(string $markdown): string
    {
        return (string) self::converter()->convert($markdown);
    }

    private static function converter(): MarkdownConverter
    {
        if (null === self::$converter) {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 20,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());

            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter;
    }
}
