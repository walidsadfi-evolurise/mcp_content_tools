<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: search_content.
 */
#[Tool(
  id: 'search_content',
  label: new TranslatableMarkup('Search content'),
  description: new TranslatableMarkup('Search published content by keywords (title and text fields). Returns id, type, title, URL, snippet, dates. Use get_content with the id or url to read a result in full. Only content the current account may view is returned.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'query' => ['type' => 'string', 'description' => 'Keywords or a phrase to match (case-insensitive substring match).'],
      'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Restrict to these content type machine names (see site_map). Default: all exposed types.'],
      'langcode' => ['type' => 'string', 'description' => 'Language code, e.g. "fr". Default: any.'],
      'term' => ['type' => 'integer', 'description' => 'Only content tagged with this taxonomy term id (see get_taxonomy).'],
      'limit' => ['type' => 'integer', 'description' => 'Max results (default 10, capped by site settings).'],
      'offset' => ['type' => 'integer', 'description' => 'Skip this many results, for paging.'],
    ],
    'required' => ['query'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SearchContent extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $query = $this->str($arguments, 'query', '') ?? '';
    $term = $this->int($arguments, 'term', 0);
    if ($query === '' && !$term) {
      return ['error' => 'invalid_arguments', 'message' => 'Provide "query" and/or "term".'];
    }
    return $this->exporter->search(
      $query,
      $this->list($arguments, 'types'),
      $this->str($arguments, 'langcode'),
      $this->int($arguments, 'limit', 10),
      max(0, $this->int($arguments, 'offset', 0)),
      $this->account,
      $term ?: NULL,
    );
  }

}
