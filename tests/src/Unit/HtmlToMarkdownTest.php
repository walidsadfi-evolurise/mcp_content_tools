<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_content_tools\Unit;

use Drupal\mcp_content_tools\HtmlToMarkdown;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_content_tools\HtmlToMarkdown
 * @group mcp_content_tools
 */
final class HtmlToMarkdownTest extends UnitTestCase {

  /**
   * The converter under test.
   *
   * @var \Drupal\mcp_content_tools\HtmlToMarkdown
   */
  private HtmlToMarkdown $converter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->converter = new HtmlToMarkdown();
  }

  /**
   * @covers ::convert
   * @dataProvider cases
   */
  public function testConvert(string $html, string $expected): void {
    $this->assertSame($expected, $this->converter->convert($html));
  }

  /**
   * Cases.
   */
  public static function cases(): array {
    return [
      'empty' => ['', ''],
      'plain' => ['plain &eacute; text', 'plain é text'],
      'heading and paragraph' => [
        '<h2>Title &amp; co</h2><p>Hello <strong>world</strong>.</p>',
        "## Title & co\n\nHello **world**.",
      ],
      'links' => [
        '<p>See <a href="/about">about</a> and <a href="javascript:x">bad</a>.</p>',
        'See [about](/about) and bad.',
      ],
      'lists' => ['<ul><li>one</li><li>two<ul><li>nested</li></ul></li></ul>', "- one\n- two\n  - nested"],
      'ordered' => ['<ol><li>a</li><li>b</li></ol>', "1. a\n2. b"],
      'blockquote' => ['<blockquote><p>quote</p></blockquote>', '> quote'],
      'table' => [
        '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>',
        "| A | B |\n| --- | --- |\n| 1 | 2 |",
      ],
      'code' => ['<pre><code>x = 1;</code></pre><p>use <code>y</code></p>', "```\nx = 1;\n```\n\nuse `y`"],
      'image' => ['<p><img src="/a.png" alt="An image"></p>', '![An image](/a.png)'],
      'script dropped' => ['<p>safe</p><script>alert(1)</script>', 'safe'],
      'unicode' => ['<p>Café — été</p>', 'Café — été'],
      'nbsp and whitespace' => ["<p>a&nbsp;b</p>\n\n\n<p>c</p>", "a b\n\nc"],
      'br' => ['<p>line1<br>line2</p>', "line1\nline2"],
    ];
  }

}
