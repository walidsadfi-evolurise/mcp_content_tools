<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_content_tools\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_content_tools\ContentExporter;
use Drupal\mcp_content_tools\HtmlToMarkdown;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;

/**
 * Search, rendering, access and export cursor against a real entity system.
 *
 * The exporter is built by hand so the test does not need mcp_server installed.
 *
 * @group mcp_content_tools
 */
final class ContentExporterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'path_alias',
    'path',
    'mcp_content_tools',
  ];

  /**
   * The exporter under test, built by hand so mcp_server is not needed.
   *
   * @var \Drupal\mcp_content_tools\ContentExporter
   */
  private ContentExporter $exporter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'user']);
    FilterFormat::create(['format' => 'raw', 'name' => 'Raw'])->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'secret', 'name' => 'Secret'])->save();
    node_add_body_field(NodeType::load('article'));
    node_add_body_field(NodeType::load('secret'));

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Tags',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_internal_note',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_internal_note',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Internal note',
    ])->save();

    $this->config('mcp_content_tools.settings')
      ->set('bundles', ['article'])
      ->set('excluded_fields', [])
      ->set('excluded_field_patterns', ['field_internal_*'])
      ->set('max_results', 20)
      ->set('max_chars', 20000)
      ->set('include_unpublished', FALSE)
      ->set('include_author', FALSE)
      ->set('export_page_size', 2)
      ->save();

    $c = $this->container;
    $this->exporter = new ContentExporter(
      $c->get('entity_type.manager'),
      $c->get('entity_type.bundle.info'),
      $c->get('entity_field.manager'),
      $c->get('config.factory'),
      $c->get('language_manager'),
      $c->get('path_alias.manager'),
      $c->get('renderer'),
      $c->get('current_user'),
      $c->get('module_handler'),
      $c->get('request_stack'),
      new HtmlToMarkdown(),
    );
  }

  /**
   * Anonymous with "access content".
   */
  private function viewer() {
    $anon = Role::load('anonymous');
    $anon->grantPermission('access content')->save();
    return $this->createUser(['access content']);
  }

  /**
   * Search, snippet, bundle allowlist, published only.
   */
  public function testSearchAndAllowlist(): void {
    $viewer = $this->viewer();
    $tag = Term::create(['vid' => 'tags', 'name' => 'Drupal']);
    $tag->save();
    Node::create([
      'type' => 'article',
      'title' => 'Hello Drupal',
      'status' => 1,
      'body' => [
        'value' => '<p>Drupal <strong>rocks</strong> for agents.</p>',
        'format' => 'raw',
      ],
      'field_tags' => [$tag->id()],
      'field_internal_note' => 'do not leak',
    ])->save();
    Node::create([
      'type' => 'article',
      'title' => 'Unrelated',
      'status' => 1,
      'body' => ['value' => 'Nothing to see.', 'format' => 'raw'],
    ])->save();
    Node::create([
      'type' => 'article',
      'title' => 'Draft Drupal',
      'status' => 0,
      'body' => ['value' => 'Drupal draft', 'format' => 'raw'],
    ])->save();
    Node::create([
      'type' => 'secret',
      'title' => 'Secret Drupal',
      'status' => 1,
      'body' => ['value' => 'Drupal secret', 'format' => 'raw'],
    ])->save();

    $r = $this->exporter->search('drupal', [], NULL, 10, 0, $viewer);
    $titles = array_column($r['results'], 'title');
    $this->assertSame(['Hello Drupal'], $titles, 'published + allowed bundle only, body matched');
    $this->assertSame(1, $r['total']);
    $this->assertStringContainsString('Drupal rocks', $r['results'][0]['snippet']);

    $byTerm = $this->exporter->search('', [], NULL, 10, 0, $viewer, (int) $tag->id());
    $this->assertCount(1, $byTerm['results']);

    $r = $this->exporter->search('drupal', ['secret'], NULL, 10, 0, $viewer);
    $this->assertSame([], $r['results'], 'requesting a non-allowed bundle yields nothing');
  }

  /**
   * Rendering: markdown, excluded field, citation, hash; alias/url/uuid lookup.
   */
  public function testDocumentAndResolve(): void {
    $viewer = $this->viewer();
    $tag = Term::create(['vid' => 'tags', 'name' => 'Drupal']);
    $tag->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Hello Drupal',
      'status' => 1,
      'body' => [
        'value' => '<h2>Intro</h2><p>Drupal <strong>rocks</strong>.</p><ul><li>a</li><li>b</li></ul>',
        'format' => 'raw',
      ],
      'field_tags' => [$tag->id()],
      'field_internal_note' => 'do not leak',
      'path' => ['alias' => '/hello-drupal'],
    ]);
    $node->save();

    $doc = $this->exporter->document($node, NULL, 'fields', $viewer);
    $this->assertNotNull($doc);
    $this->assertArrayNotHasKey('field_internal_note', $doc['fields'], 'pattern-excluded field hidden');
    $this->assertArrayHasKey('body', $doc['fields']);
    $this->assertArrayHasKey('field_tags', $doc['fields']);
    $this->assertSame('Drupal', $doc['fields']['field_tags']['value'][0]['label']);
    $this->assertStringContainsString('# Hello Drupal', $doc['content']);
    $this->assertStringNotContainsString('do not leak', $doc['content']);
    $this->assertSame(hash('sha256', $doc['content']), $doc['content_hash']);
    $this->assertStringEndsWith('/hello-drupal', $doc['url']);
    $this->assertSame('Hello Drupal — ' . $doc['url'], $doc['citation']);
    $this->assertFalse($doc['truncated']);

    $md = $this->exporter->document($node, NULL, 'markdown', $viewer);
    $this->assertArrayNotHasKey('fields', $md);
    $this->assertSame('text/markdown', $md['content_format']);
    $this->assertStringContainsString("## Intro\n\nDrupal **rocks**.\n\n- a\n- b", $md['content']);
    $this->assertStringContainsString('**Tags:** Drupal', $md['content']);

    $txt = $this->exporter->document($node, NULL, 'text', $viewer);
    $this->assertSame('text/plain', $txt['content_format']);
    $this->assertStringNotContainsString('**', $txt['content']);

    $this->assertSame($node->id(), $this->exporter->resolve((string) $node->id())?->id());
    $this->assertSame($node->id(), $this->exporter->resolve('/hello-drupal')?->id());
    $this->assertSame($node->id(), $this->exporter->resolve('https://example.com/hello-drupal')?->id());
    $this->assertSame($node->id(), $this->exporter->resolve('/node/' . $node->id())?->id());
    $this->assertSame($node->id(), $this->exporter->resolve($node->uuid())?->id());
    $this->assertNull($this->exporter->resolve('/nope'));

    // Unpublished: hidden from a plain viewer even when asked directly.
    $node->setUnpublished()->save();
    $this->assertNull($this->exporter->document($node, NULL, 'markdown', $viewer));

    // Non-allowed bundle: hidden.
    $secret = Node::create(['type' => 'secret', 'title' => 'S', 'status' => 1]);
    $secret->save();
    $this->assertNull($this->exporter->document($secret, NULL, 'markdown', $viewer));
  }

  /**
   * Export pages through all documents exactly once, ordered by (changed, nid).
   */
  public function testExportCursor(): void {
    $viewer = $this->viewer();
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
      $n = Node::create([
        'type' => 'article',
        'title' => "Doc $i",
        'status' => 1,
        'body' => ['value' => "Body $i", 'format' => 'raw'],
        'changed' => 1_700_000_000,
      ]);
      $n->save();
      $ids[] = (int) $n->id();
    }
    $seen = [];
    $cursor = NULL;
    $pages = 0;
    do {
      $page = $this->exporter->export([], NULL, NULL, $cursor, 2, $viewer);
      $pages++;
      foreach ($page['documents'] as $doc) {
        $seen[] = $doc['id'];
      }
      $cursor = $page['next_cursor'];
    } while ($page['has_more']);
    $this->assertSame($ids, $seen, 'every document once, in order');
    $this->assertSame(3, $pages);

    $none = $this->exporter->export([], NULL, time() + 10, NULL, 2, $viewer);
    $this->assertSame([], $none['documents']);
  }

  /**
   * Site map and taxonomy.
   */
  public function testSiteMapAndTaxonomy(): void {
    $viewer = $this->viewer();
    Node::create(['type' => 'article', 'title' => 'A', 'status' => 1])->save();
    $parent = Term::create(['vid' => 'tags', 'name' => 'Parent']);
    $parent->save();
    Term::create(['vid' => 'tags', 'name' => 'Child', 'parent' => [$parent->id()]])->save();

    $map = $this->exporter->siteMap($viewer);
    $this->assertSame(['article'], array_column($map['content_types'], 'id'));
    $this->assertSame(1, $map['content_types'][0]['count']);
    $fields = array_column($map['content_types'][0]['fields'], 'name');
    $this->assertContains('body', $fields);
    $this->assertNotContains('field_internal_note', $fields);
    $this->assertSame(['tags'], array_column($map['vocabularies'], 'id'));

    $tax = $this->exporter->taxonomy('tags', NULL, $viewer);
    $this->assertCount(2, $tax['terms']);
    $this->assertSame(1, $tax['terms'][1]['depth']);
    $this->assertSame((int) $parent->id(), $tax['terms'][1]['parent']);
    $this->assertNull($this->exporter->taxonomy('nope', NULL, $viewer));
  }

}
