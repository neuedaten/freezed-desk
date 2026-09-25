<?php

namespace Neuedaten\FreezedDesk\Export;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown to HTML for the "markdown" field type: CommonMark plus tables,
 * autolinks and strikethrough. Raw HTML in the source is stripped and
 * unsafe links are dropped, so a record can never inject markup into a page.
 */
final class Markdown
{
    private ?MarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        return trim((string) $this->converter()->convert($markdown));
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());

        return $this->converter = new MarkdownConverter($environment);
    }
}
