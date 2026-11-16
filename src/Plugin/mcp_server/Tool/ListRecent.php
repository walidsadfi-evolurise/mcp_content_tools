<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: list_recent.
 */
#[Tool(
  id: 'list_recent',
  label: new TranslatableMarkup('List recent content'),
  description: new TranslatableMarkup('Most recently changed content, newest first. Filter by content type, language and a "since" bound ("7d", "24h", a date or a unix timestamp). Good for "what is new" and for keeping an index fresh.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'types' => [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => 'Content type machine names. Default: all exposed types.',
      ],
      'langcode' => ['type' => 'string'],
      'since' => ['type' => 'string', 'description' => '"7d", "48h", "2026-01-01", ISO date-time or unix timestamp.'],
      'limit' => ['type' => 'integer', 'description' => 'Default 10.'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ListRecent extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    return $this->exporter->recent(
      $this->list($arguments, 'types'),
      $this->str($arguments, 'langcode'),
      $this->since($arguments, 'since'),
      $this->int($arguments, 'limit', 10),
      $this->account,
    );
  }

}
