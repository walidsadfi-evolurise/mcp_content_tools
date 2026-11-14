<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools;

/**
 * Small, dependency-free HTML → Markdown converter for editorial content.
 *
 * Covers what CKEditor produces (headings, paragraphs, lists, links, emphasis,
 * code, blockquotes, tables, images) and drops everything else to plain text.
 * Not a general-purpose converter; it is meant to give an LLM clean, compact
 * text while keeping the structure that carries meaning.
 */
final class HtmlToMarkdown {

  /**
   * Convert an HTML fragment to Markdown.
   */
  public function convert(string $html): string {
    $html = trim($html);
    if ($html === '') {
      return '';
    }
    if (!str_contains($html, '<')) {
      return $this->collapse(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $doc = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="mct-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $doc->getElementById('mct-root');
    if (!$root) {
      return $this->collapse(strip_tags($html));
    }
    $out = $this->walk($root);
    return $this->collapse($out);
  }

  /**
   * Recursive node walker.
   */
  private function walk(\DOMNode $node, int $listDepth = 0, string $listType = ''): string {
    if ($node instanceof \DOMText) {
      return preg_replace('/\s+/u', ' ', $node->wholeText) ?? '';
    }
    if (!$node instanceof \DOMElement) {
      if ($node->hasChildNodes()) {
        return $this->children($node, $listDepth, $listType);
      }
      return '';
    }
    $tag = strtolower($node->tagName);
    switch ($tag) {
      case 'script':
      case 'style':
      case 'noscript':
      case 'iframe':
      case 'svg':
      case 'template':
        return '';

      case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
        $level = (int) $tag[1];
        return "\n\n" . str_repeat('#', $level) . ' ' . trim($this->children($node)) . "\n\n";

      case 'p':
      case 'div':
      case 'section':
      case 'article':
      case 'header':
      case 'footer':
      case 'main':
      case 'aside':
      case 'figure':
      case 'figcaption':
      case 'address':
        return "\n\n" . trim($this->children($node, $listDepth, $listType)) . "\n\n";

      case 'br':
        return "  \n";

      case 'hr':
        return "\n\n---\n\n";

      case 'strong':
      case 'b':
        $t = trim($this->children($node));
        return $t === '' ? '' : '**' . $t . '**';

      case 'em':
      case 'i':
        $t = trim($this->children($node));
        return $t === '' ? '' : '*' . $t . '*';

      case 'del':
      case 's':
        $t = trim($this->children($node));
        return $t === '' ? '' : '~~' . $t . '~~';

      case 'code':
        if ($node->parentNode instanceof \DOMElement && strtolower($node->parentNode->tagName) === 'pre') {
          return $node->textContent;
        }
        return '`' . trim($node->textContent) . '`';

      case 'pre':
        return "\n\n```\n" . rtrim($node->textContent) . "\n```\n\n";

      case 'blockquote':
        $inner = trim($this->children($node));
        $lines = array_map(static fn($l) => '> ' . $l, explode("\n", $inner));
        return "\n\n" . implode("\n", $lines) . "\n\n";

      case 'a':
        $href = trim($node->getAttribute('href'));
        $text = trim($this->children($node));
        if ($href === '' || str_starts_with($href, 'javascript:')) {
          return $text;
        }
        if ($text === '') {
          $text = $href;
        }
        return '[' . $text . '](' . $href . ')';

      case 'img':
        $alt = trim($node->getAttribute('alt'));
        $src = trim($node->getAttribute('src'));
        return $src === '' ? '' : '![' . $alt . '](' . $src . ')';

      case 'ul':
      case 'ol':
        $items = [];
        $i = 0;
        foreach ($node->childNodes as $child) {
          if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
            $i++;
            $marker = $tag === 'ol' ? $i . '. ' : '- ';
            $content = rtrim(ltrim($this->children($child, $listDepth + 1, $tag), " \t"));
            $items[] = str_repeat('  ', $listDepth) . $marker . $content;
          }
        }
        return "\n" . implode("\n", $items) . "\n" . ($listDepth === 0 ? "\n" : '');

      case 'li':
        return trim($this->children($node, $listDepth, $listType));

      case 'table':
        return $this->table($node);

      case 'dt':
        return "\n**" . trim($this->children($node)) . "**\n";

      case 'dd':
        return ': ' . trim($this->children($node)) . "\n";

      default:
        return $this->children($node, $listDepth, $listType);
    }
  }

  /**
   * Concatenate children.
   */
  private function children(\DOMNode $node, int $listDepth = 0, string $listType = ''): string {
    $out = '';
    foreach ($node->childNodes as $child) {
      $out .= $this->walk($child, $listDepth, $listType);
    }
    return $out;
  }

  /**
   * Tables → GitHub-flavoured Markdown tables.
   */
  private function table(\DOMElement $table): string {
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $tr) {
      $cells = [];
      foreach ($tr->childNodes as $cell) {
        if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], TRUE)) {
          $cells[] = str_replace(['|', "\n"], ['\|', ' '], trim($this->children($cell)));
        }
      }
      if ($cells) {
        $rows[] = $cells;
      }
    }
    if (!$rows) {
      return '';
    }
    $width = max(array_map('count', $rows));
    $lines = [];
    foreach ($rows as $i => $cells) {
      $cells = array_pad($cells, $width, '');
      $lines[] = '| ' . implode(' | ', $cells) . ' |';
      if ($i === 0) {
        $lines[] = '|' . str_repeat(' --- |', $width);
      }
    }
    return "\n\n" . implode("\n", $lines) . "\n\n";
  }

  /**
   * Collapse runs of blank lines and trim.
   */
  private function collapse(string $s): string {
    $s = str_replace("\xC2\xA0", ' ', $s);
    $s = preg_replace('/[ \t]+\n/', "\n", $s) ?? $s;
    $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;
    // Trim line edges, but keep the indentation of nested list items.
    $s = preg_replace('/^ +(?!\s)(?![-*] |\d+\. )/m', '', $s) ?? $s;
    $s = preg_replace('/ +$/m', '', $s) ?? $s;
    return trim($s);
  }

}
