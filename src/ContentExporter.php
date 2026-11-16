<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns nodes into LLM-friendly documents.
 *
 * Honours node access and the field allowlist.
 *
 * Every public method takes the account the MCP session runs as; nothing is
 * returned that this account could not see on the website.
 */
class ContentExporter {

  /**
   * Base fields that are never content.
   */
  private const ALWAYS_EXCLUDED = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp', 'revision_uid',
    'revision_log', 'status', 'uid', 'created', 'changed', 'promote', 'sticky',
    'default_langcode', 'revision_default', 'revision_translation_affected',
    'path', 'menu_link', 'metatag', 'content_translation_source',
    'content_translation_outdated', 'moderation_state', 'publish_on', 'unpublish_on',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $fieldManager,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected AliasManagerInterface $aliasManager,
    protected RendererInterface $renderer,
    protected AccountInterface $currentUser,
    protected ModuleHandlerInterface $moduleHandler,
    protected RequestStack $requestStack,
    protected ?HtmlToMarkdown $markdown = NULL,
  ) {
    $this->markdown ??= new HtmlToMarkdown();
  }

  /**
   * Settings.
   */
  public function settings(): array {
    $c = $this->configFactory->get('mcp_content_tools.settings');
    return [
      'bundles' => (array) ($c->get('bundles') ?? []),
      'excluded_fields' => (array) ($c->get('excluded_fields') ?? []),
      'excluded_field_patterns' => (array) ($c->get('excluded_field_patterns') ?? []),
      'max_results' => max(1, (int) ($c->get('max_results') ?? 20)),
      'max_chars' => max(500, (int) ($c->get('max_chars') ?? 20000)),
      'include_unpublished' => (bool) ($c->get('include_unpublished') ?? FALSE),
      'include_author' => (bool) ($c->get('include_author') ?? FALSE),
      'export_page_size' => max(1, min(200, (int) ($c->get('export_page_size') ?? 50))),
    ];
  }

  /**
   * Content types the tools may touch: configured allowlist ∩ existing bundles.
   *
   * @return array<string, string>
   *   bundle => label.
   */
  public function allowedBundles(): array {
    $all = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $id => $info) {
      $all[$id] = (string) $info['label'];
    }
    $configured = $this->settings()['bundles'];
    if (!$configured) {
      return $all;
    }
    return array_intersect_key($all, array_flip($configured));
  }

  /**
   * Narrow a requested bundle list to what is allowed (empty = all allowed).
   */
  public function resolveBundles(array $requested): array {
    $allowed = array_keys($this->allowedBundles());
    $requested = array_values(array_filter(array_map('strval', $requested)));
    return $requested ? array_values(array_intersect($allowed, $requested)) : $allowed;
  }

  /**
   * Full-text search over title and text fields, access-checked.
   *
   * @return array{results: list<array>, total: int, truncated: bool}
   *   Matching documents, the total number of matches and whether the result
   *   list was cut at the limit.
   */
  public function search(string $query, array $bundles, ?string $langcode, int $limit, int $offset, AccountInterface $account, ?int $term = NULL): array {
    $s = $this->settings();
    $limit = max(1, min($s['max_results'], $limit));
    $bundles = $this->resolveBundles($bundles);
    if (!$bundles) {
      return ['results' => [], 'total' => 0, 'truncated' => FALSE];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $q = $storage->getQuery()->accessCheck(TRUE)->condition('type', $bundles, 'IN');
    if (!$s['include_unpublished']) {
      $q->condition('status', NodeInterface::PUBLISHED);
    }
    if ($langcode) {
      $q->condition('langcode', $langcode);
    }
    $query = trim($query);
    if ($query !== '') {
      $or = $q->orConditionGroup()->condition('title', $query, 'CONTAINS');
      foreach ($this->searchableTextFields($bundles) as $field) {
        $or->condition($field . '.value', $query, 'CONTAINS');
      }
      $q->condition($or);
    }
    if ($term) {
      $or = $q->orConditionGroup();
      $any = FALSE;
      foreach ($this->termReferenceFields($bundles) as $field) {
        $or->condition($field . '.target_id', $term);
        $any = TRUE;
      }
      if ($any) {
        $q->condition($or);
      }
      else {
        return ['results' => [], 'total' => 0, 'truncated' => FALSE];
      }
    }
    $count = (clone $q)->count()->execute();
    $ids = $q->sort('changed', 'DESC')->range($offset, $limit)->execute();
    $results = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $node = $this->translation($node, $langcode);
      if (!$node->access('view', $account)) {
        continue;
      }
      $results[] = $this->summary($node, $query);
    }
    return [
      'results' => $results,
      'total' => (int) $count,
      'truncated' => (int) $count > $offset + count($results),
    ];
  }

  /**
   * Recently changed content.
   */
  public function recent(array $bundles, ?string $langcode, ?int $since, int $limit, AccountInterface $account): array {
    $s = $this->settings();
    $limit = max(1, min($s['max_results'], $limit));
    $bundles = $this->resolveBundles($bundles);
    if (!$bundles) {
      return ['results' => [], 'total' => 0];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $q = $storage->getQuery()->accessCheck(TRUE)->condition('type', $bundles, 'IN');
    if (!$s['include_unpublished']) {
      $q->condition('status', NodeInterface::PUBLISHED);
    }
    if ($langcode) {
      $q->condition('langcode', $langcode);
    }
    if ($since) {
      $q->condition('changed', $since, '>=');
    }
    $count = (clone $q)->count()->execute();
    $ids = $q->sort('changed', 'DESC')->range(0, $limit)->execute();
    $results = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $node = $this->translation($node, $langcode);
      if ($node->access('view', $account)) {
        $results[] = $this->summary($node);
      }
    }
    return ['results' => $results, 'total' => (int) $count];
  }

  /**
   * Resolve "12", "/node/12", "/about-us", a full URL or a UUID to a node.
   */
  public function resolve(string $ref, ?string $langcode = NULL): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ref = trim($ref);
    if ($ref === '') {
      return NULL;
    }
    if (ctype_digit($ref)) {
      $node = $storage->load((int) $ref);
      return $node instanceof NodeInterface ? $node : NULL;
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref)) {
      $found = $storage->loadByProperties(['uuid' => $ref]);
      $node = $found ? reset($found) : NULL;
      return $node instanceof NodeInterface ? $node : NULL;
    }
    $path = $ref;
    if (preg_match('#^https?://#i', $ref)) {
      $path = parse_url($ref, PHP_URL_PATH) ?: '/';
    }
    $path = '/' . ltrim($path, '/');
    // Strip a language prefix such as /fr/about.
    $languages = array_keys($this->languageManager->getLanguages());
    if (preg_match('#^/([a-z]{2}(?:-[a-zA-Z]{2,4})?)(/.*)?$#', $path, $m) && in_array($m[1], $languages, TRUE)) {
      $langcode = $langcode ?? $m[1];
      $path = $m[2] ?? '/';
      if ($path === '') {
        $path = '/';
      }
    }
    $internal = $this->aliasManager->getPathByAlias($path, $langcode);
    if (preg_match('#^/node/(\d+)$#', $internal, $m)) {
      $node = $storage->load((int) $m[1]);
      return $node instanceof NodeInterface ? $node : NULL;
    }
    return NULL;
  }

  /**
   * One node as a document, or NULL when not allowed.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string|null $langcode
   *   Translation to use, NULL for the node's default language.
   * @param string $format
   *   One of markdown, text or fields.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account the MCP session runs as.
   * @param bool $forExport
   *   TRUE when building a corpus export (skips the per-call summary).
   *
   * @return array|null
   *   The document, or NULL when the account may not see the node.
   */
  public function document(NodeInterface $node, ?string $langcode, string $format, AccountInterface $account, bool $forExport = FALSE): ?array {
    $s = $this->settings();
    if (!isset($this->allowedBundles()[$node->bundle()])) {
      return NULL;
    }
    $node = $this->translation($node, $langcode);
    if (!$node->access('view', $account)) {
      return NULL;
    }
    if (!$s['include_unpublished'] && !$node->isPublished()) {
      return NULL;
    }
    $doc = $this->summary($node);
    $doc['fields'] = [];
    $body = [];
    // "fields" returns the structured values plus the same Markdown document.
    $markdown = $format !== 'text';
    foreach ($this->exposedFields($node) as $name => $definition) {
      $items = $node->get($name);
      if ($items->isEmpty()) {
        continue;
      }
      $rendered = $this->renderField($items, $definition, $format);
      if ($rendered === NULL || $rendered === '' || $rendered === []) {
        continue;
      }
      $doc['fields'][$name] = [
        'label' => (string) $definition->getLabel(),
        'type' => $definition->getType(),
        'value' => $rendered,
      ];
      if (is_string($rendered)) {
        $body[] = ($markdown ? '## ' . $definition->getLabel() . "\n\n" : $definition->getLabel() . ":\n") . $rendered;
      }
      elseif (is_array($rendered)) {
        $flat = implode(', ', array_map(static fn($v) => is_array($v) ? ($v['label'] ?? $v['title'] ?? $v['url'] ?? json_encode($v)) : (string) $v, $rendered));
        $body[] = ($markdown ? '**' . $definition->getLabel() . ':** ' : $definition->getLabel() . ': ') . $flat;
      }
    }
    $text = ($markdown ? '# ' . $node->label() . "\n\n" : $node->label() . "\n\n") . implode("\n\n", $body);
    $truncated = FALSE;
    if (mb_strlen($text) > $s['max_chars']) {
      $text = Unicode::truncate($text, $s['max_chars'], TRUE, TRUE);
      $truncated = TRUE;
    }
    $doc['content'] = $text;
    $doc['content_format'] = $format === 'text' ? 'text/plain' : 'text/markdown';
    $doc['truncated'] = $truncated;
    $doc['content_hash'] = hash('sha256', $text);
    $doc['citation'] = sprintf('%s — %s', $node->label(), $doc['url']);
    if ($format !== 'fields') {
      unset($doc['fields']);
    }
    if (!$forExport) {
      $doc['translations'] = array_values(array_diff(array_keys($node->getTranslationLanguages()), [$node->language()->getId()]));
    }
    return $doc;
  }

  /**
   * Incremental corpus export: documents changed since a timestamp.
   *
   * Pages are ordered by (changed, nid) so a cursor survives new edits.
   *
   * @return array{documents: list<array>, next_cursor: ?string, has_more: bool, total_remaining: int}
   *   The page of documents, the cursor for the next page, whether more pages
   *   exist and how many documents remain after this page.
   */
  public function export(array $bundles, ?string $langcode, ?int $since, ?string $cursor, int $pageSize, AccountInterface $account): array {
    $s = $this->settings();
    $pageSize = max(1, min($s['export_page_size'], $pageSize));
    $bundles = $this->resolveBundles($bundles);
    if (!$bundles) {
      return ['documents' => [], 'next_cursor' => NULL, 'has_more' => FALSE, 'total_remaining' => 0];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $q = $storage->getQuery()->accessCheck(TRUE)->condition('type', $bundles, 'IN');
    if (!$s['include_unpublished']) {
      $q->condition('status', NodeInterface::PUBLISHED);
    }
    if ($langcode) {
      $q->condition('langcode', $langcode);
    }
    if ($since) {
      $q->condition('changed', $since, '>=');
    }
    if ($cursor && preg_match('/^(\d+):(\d+)$/', $cursor, $m)) {
      $cChanged = (int) $m[1];
      $cNid = (int) $m[2];
      $after = $q->orConditionGroup()
        ->condition('changed', $cChanged, '>')
        ->condition($q->andConditionGroup()->condition('changed', $cChanged)->condition('nid', $cNid, '>'));
      $q->condition($after);
    }
    $remaining = (int) (clone $q)->count()->execute();
    $ids = $q->sort('changed', 'ASC')->sort('nid', 'ASC')->range(0, $pageSize)->execute();
    $documents = [];
    $last = NULL;
    foreach ($storage->loadMultiple($ids) as $node) {
      $last = $node;
      $langs = $langcode ? [$langcode] : array_keys($node->getTranslationLanguages());
      foreach ($langs as $l) {
        if (!$node->hasTranslation($l)) {
          continue;
        }
        $doc = $this->document($node, $l, 'markdown', $account, TRUE);
        if ($doc) {
          $documents[] = $doc;
        }
      }
    }
    $hasMore = $remaining > count($ids);
    return [
      'documents' => $documents,
      'next_cursor' => $hasMore && $last ? $last->getChangedTime() . ':' . $last->id() : NULL,
      'has_more' => $hasMore,
      'total_remaining' => max(0, $remaining - count($ids)),
    ];
  }

  /**
   * Orientation: content types, counts, languages, vocabularies.
   */
  public function siteMap(AccountInterface $account): array {
    $s = $this->settings();
    $storage = $this->entityTypeManager->getStorage('node');
    $types = [];
    $counts = [];
    $agg = $storage->getAggregateQuery()->accessCheck(FALSE)->groupBy('type')->aggregate('nid', 'COUNT');
    if (!$s['include_unpublished']) {
      $agg->condition('status', NodeInterface::PUBLISHED);
    }
    foreach ($agg->execute() as $row) {
      $counts[$row['type']] = (int) $row['nid_count'];
    }
    foreach ($this->allowedBundles() as $bundle => $label) {
      $fields = [];
      foreach ($this->fieldManager->getFieldDefinitions('node', $bundle) as $name => $def) {
        if ($this->isExposed($name, $def)) {
          $fields[] = ['name' => $name, 'label' => (string) $def->getLabel(), 'type' => $def->getType()];
        }
      }
      $types[] = [
        'id' => $bundle,
        'label' => $label,
        'count' => $counts[$bundle] ?? 0,
        'fields' => $fields,
      ];
    }
    $languages = [];
    foreach ($this->languageManager->getLanguages() as $lang) {
      $languages[] = ['id' => $lang->getId(), 'label' => $lang->getName(), 'default' => $lang->isDefault()];
    }
    $vocabularies = [];
    if ($this->entityTypeManager->hasDefinition('taxonomy_vocabulary')) {
      foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $vocab) {
        $vocabularies[] = ['id' => $vocab->id(), 'label' => $vocab->label()];
      }
    }
    return [
      'site_name' => (string) $this->configFactory->get('system.site')->get('name'),
      'base_url' => rtrim((string) $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost(), '/'),
      'default_language' => $this->languageManager->getDefaultLanguage()->getId(),
      'languages' => $languages,
      'content_types' => $types,
      'vocabularies' => $vocabularies,
      'tools_hint' => 'Use search_content to find pages, get_content to read one as Markdown with a citation URL, list_recent for what changed, get_taxonomy for a vocabulary tree, export_content to pull the corpus incrementally.',
    ];
  }

  /**
   * Taxonomy tree.
   */
  public function taxonomy(string $vid, ?string $langcode, AccountInterface $account, int $maxDepth = 0): ?array {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return NULL;
    }
    $vocab = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
    if (!$vocab) {
      return NULL;
    }
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = [];
    foreach ($storage->loadTree($vid, 0, $maxDepth > 0 ? $maxDepth : NULL, TRUE) as $term) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      if ($langcode && $term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      if (!$term->access('view', $account)) {
        continue;
      }
      $terms[] = [
        'id' => (int) $term->id(),
        'name' => $term->label(),
        'depth' => (int) $term->depth,
        'parent' => (int) ($term->parents[0] ?? 0),
        'description' => $this->markdown->convert((string) ($term->getDescription() ?? '')),
        'url' => $term->toUrl()->setAbsolute()->toString(),
      ];
    }
    return ['vocabulary' => ['id' => $vocab->id(), 'label' => $vocab->label()], 'terms' => $terms];
  }

  /**
   * Compact representation of a node for lists.
   */
  public function summary(NodeInterface $node, string $highlight = ''): array {
    $s = $this->settings();
    $out = [
      'id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'type' => $node->bundle(),
      'type_label' => $this->allowedBundles()[$node->bundle()] ?? $node->bundle(),
      'title' => $node->label(),
      'url' => $node->toUrl()->setAbsolute()->toString(),
      'langcode' => $node->language()->getId(),
      'status' => $node->isPublished() ? 'published' : 'unpublished',
      'created' => date(DATE_ATOM, (int) $node->getCreatedTime()),
      'changed' => date(DATE_ATOM, (int) $node->getChangedTime()),
      'snippet' => $this->snippet($node, $highlight),
    ];
    if ($s['include_author'] && $node->getOwner()) {
      $out['author'] = $node->getOwner()->getDisplayName();
    }
    return $out;
  }

  /**
   * Pick the requested translation when it exists.
   */
  public function translation(NodeInterface $node, ?string $langcode): NodeInterface {
    if ($langcode && $node->hasTranslation($langcode)) {
      return $node->getTranslation($langcode);
    }
    return $node;
  }

  /**
   * The content fields of a node that may be exposed.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Field definitions keyed by field name.
   */
  public function exposedFields(NodeInterface $node): array {
    $out = [];
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if ($this->isExposed($name, $definition)) {
        $out[$name] = $definition;
      }
    }
    return $out;
  }

  /**
   * Field allowlist logic.
   */
  public function isExposed(string $name, FieldDefinitionInterface $definition): bool {
    if (in_array($name, self::ALWAYS_EXCLUDED, TRUE) || $name === 'title') {
      return FALSE;
    }
    $s = $this->settings();
    if (in_array($name, $s['excluded_fields'], TRUE)) {
      return FALSE;
    }
    foreach ($s['excluded_field_patterns'] as $pattern) {
      // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged -- Shell-style patterns are what administrators type; no filesystem access is involved.
      if ($pattern !== '' && fnmatch($pattern, $name)) {
        return FALSE;
      }
    }
    if (in_array($definition->getType(), ['password', 'comment', 'layout_section'], TRUE)) {
      return FALSE;
    }
    $exposed = TRUE;
    $this->moduleHandler->alter('mcp_content_tools_field_exposed', $exposed, $name, $definition);
    return $exposed;
  }

  /**
   * Render one field for a document.
   *
   * @return string|array|null
   *   Markdown/text for long text, scalars or lists for structured fields.
   */
  protected function renderField(FieldItemListInterface $items, FieldDefinitionInterface $definition, string $format): string|array|null {
    $type = $definition->getType();
    switch ($type) {
      case 'text':
      case 'text_long':
      case 'text_with_summary':
        $parts = [];
        foreach ($items as $item) {
          $html = (string) $item->processed;
          $parts[] = $format === 'text' ? trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $this->markdown->convert($html);
        }
        return trim(implode("\n\n", array_filter($parts)));

      case 'string':
      case 'string_long':
      case 'email':
      case 'telephone':
        $vals = array_map(static fn($i) => (string) $i->value, iterator_to_array($items));
        return count($vals) === 1 ? $vals[0] : $vals;

      case 'list_string':
      case 'list_integer':
      case 'list_float':
        $allowed = $definition->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
        $vals = [];
        foreach ($items as $item) {
          $vals[] = isset($allowed[$item->value]) ? (string) $allowed[$item->value] : (string) $item->value;
        }
        return count($vals) === 1 ? $vals[0] : $vals;

      case 'boolean':
        return $items->value ? 'yes' : 'no';

      case 'integer':
      case 'decimal':
      case 'float':
        $vals = array_map(static fn($i) => $i->value + 0, iterator_to_array($items));
        return count($vals) === 1 ? (string) $vals[0] : array_map('strval', $vals);

      case 'datetime':
      case 'daterange':
      case 'timestamp':
      case 'created':
      case 'changed':
        $vals = [];
        foreach ($items as $item) {
          if ($type === 'timestamp') {
            $vals[] = date(DATE_ATOM, (int) $item->value);
          }
          elseif ($type === 'daterange') {
            $vals[] = (string) $item->value . ' → ' . (string) $item->end_value;
          }
          else {
            $vals[] = (string) $item->value;
          }
        }
        return count($vals) === 1 ? $vals[0] : $vals;

      case 'link':
        $vals = [];
        foreach ($items as $item) {
          try {
            $url = $item->getUrl()->setAbsolute()->toString();
          }
          catch (\Throwable) {
            $url = (string) $item->uri;
          }
          $vals[] = ['title' => (string) $item->title, 'url' => $url];
        }
        return $vals;

      case 'entity_reference':
      case 'entity_reference_revisions':
      case 'dynamic_entity_reference':
        $vals = [];
        foreach ($items->referencedEntities() as $entity) {
          if (!$entity->access('view')) {
            continue;
          }
          $v = ['type' => $entity->getEntityTypeId(), 'id' => $entity->id(), 'label' => (string) $entity->label()];
          if ($entity->hasLinkTemplate('canonical')) {
            try {
              $v['url'] = $entity->toUrl()->setAbsolute()->toString();
            }
            catch (\Throwable) {
            }
          }
          // Paragraphs and other composite entities: render their own text
          // fields inline.
          if (in_array($entity->getEntityTypeId(), ['paragraph', 'block_content'], TRUE) && $entity instanceof FieldableEntityInterface) {
            $inner = [];
            $internal = [
              'id',
              'revision_id',
              'parent_id',
              'parent_type',
              'parent_field_name',
              'behavior_settings',
              'info',
              'reusable',
            ];
            foreach ($entity->getFieldDefinitions() as $n => $d) {
              if (!$this->isExposed($n, $d) || $entity->get($n)->isEmpty() || in_array($n, $internal, TRUE)) {
                continue;
              }
              $r = $this->renderField($entity->get($n), $d, $format);
              if ($r !== NULL && $r !== '' && $r !== []) {
                $inner[$n] = $r;
              }
            }
            if ($inner) {
              $v['content'] = $inner;
            }
          }
          $vals[] = $v;
        }
        // Compose paragraph text into the body for readability.
        if ($vals && isset($vals[0]['content'])) {
          $blocks = [];
          foreach ($vals as $v) {
            foreach ($v['content'] ?? [] as $r) {
              $blocks[] = is_array($r) ? implode(', ', array_map(static fn($x) => is_array($x) ? ($x['label'] ?? $x['title'] ?? $x['url'] ?? '') : (string) $x, $r)) : (string) $r;
            }
          }
          return trim(implode("\n\n", array_filter($blocks)));
        }
        return $vals;

      case 'image':
      case 'file':
        $vals = [];
        foreach ($items as $item) {
          $file = $item->entity;
          if (!$file || !$file->access('view')) {
            continue;
          }
          $v = ['url' => $file->createFileUrl(FALSE), 'filename' => $file->getFilename()];
          if ($type === 'image') {
            $v['alt'] = (string) $item->alt;
          }
          $vals[] = $v;
        }
        return $vals;

      case 'address':
        $vals = [];
        foreach ($items as $item) {
          $parts = [
            $item->address_line1,
            $item->address_line2,
            $item->postal_code,
            $item->locality,
            $item->country_code,
          ];
          $vals[] = trim(implode(', ', array_filter($parts)));
        }
        return count($vals) === 1 ? $vals[0] : $vals;

      default:
        // Unknown field type: expose the main property as text when it is
        // scalar.
        $main = $definition->getFieldStorageDefinition()->getMainPropertyName();
        if (!$main) {
          return NULL;
        }
        $vals = [];
        foreach ($items as $item) {
          $v = $item->get($main)->getValue();
          if (is_scalar($v)) {
            $vals[] = (string) $v;
          }
        }
        return $vals ? (count($vals) === 1 ? $vals[0] : $vals) : NULL;
    }
  }

  /**
   * A ~200-char snippet from the first text field, around the query when given.
   */
  protected function snippet(NodeInterface $node, string $highlight = ''): string {
    foreach ($this->exposedFields($node) as $name => $definition) {
      if (!in_array($definition->getType(), ['text_with_summary', 'text_long', 'text', 'string_long'], TRUE) || $node->get($name)->isEmpty()) {
        continue;
      }
      $item = $node->get($name)->first();
      $raw = $definition->getType() === 'text_with_summary' && !empty($item->summary) ? $item->summary : $item->value;
      $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
      if ($text === '') {
        continue;
      }
      if ($highlight !== '') {
        $pos = mb_stripos($text, $highlight);
        if ($pos !== FALSE && $pos > 80) {
          $text = '…' . mb_substr($text, $pos - 80);
        }
      }
      return Unicode::truncate($text, 220, TRUE, TRUE);
    }
    return '';
  }

  /**
   * Text fields across the bundles, for CONTAINS conditions.
   */
  protected function searchableTextFields(array $bundles): array {
    $fields = [];
    foreach ($bundles as $bundle) {
      foreach ($this->fieldManager->getFieldDefinitions('node', $bundle) as $name => $def) {
        if (in_array($def->getType(), ['text_with_summary', 'text_long', 'text', 'string_long', 'string'], TRUE) && $this->isExposed($name, $def)) {
          $fields[$name] = TRUE;
        }
      }
    }
    return array_keys($fields);
  }

  /**
   * Taxonomy reference fields across the bundles.
   */
  protected function termReferenceFields(array $bundles): array {
    $fields = [];
    foreach ($bundles as $bundle) {
      foreach ($this->fieldManager->getFieldDefinitions('node', $bundle) as $name => $def) {
        if ($def->getType() === 'entity_reference' && $def->getSetting('target_type') === 'taxonomy_term' && $this->isExposed($name, $def)) {
          $fields[$name] = TRUE;
        }
      }
    }
    return array_keys($fields);
  }

}
