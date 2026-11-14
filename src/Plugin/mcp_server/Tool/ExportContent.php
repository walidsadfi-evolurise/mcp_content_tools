<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: export_content.
 */
#[Tool(
  id: 'export_content',
  label: new TranslatableMarkup('Export content for indexing'),
  description: new TranslatableMarkup('Pull the corpus page by page for a search index or RAG store. Documents are ordered by change date; pass "since" with the time of your last run for an incremental sync and follow "next_cursor" until "has_more" is false. Each document carries id, url, langcode, changed, content (Markdown) and content_hash. Requires the "export content through mcp" permission.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'types' => ['type' => 'array', 'items' => ['type' => 'string']],
      'langcode' => ['type' => 'string', 'description' => 'One language only. Default: every translation, one document each.'],
      'since' => ['type' => 'string', 'description' => 'Only content changed at/after this time ("7d", ISO date-time, unix timestamp).'],
      'cursor' => ['type' => 'string', 'description' => 'The next_cursor of the previous page.'],
      'page_size' => ['type' => 'integer', 'description' => 'Documents per page (capped by site settings, default 50).'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ExportContent extends ContentToolBase {

  protected const PERMISSION = 'export content through mcp';

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    return $this->exporter->export(
      $this->list($arguments, 'types'),
      $this->str($arguments, 'langcode'),
      $this->since($arguments, 'since'),
      $this->str($arguments, 'cursor'),
      $this->int($arguments, 'page_size', 50),
      $this->account,
    );
  }

}
