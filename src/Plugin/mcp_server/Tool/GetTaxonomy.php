<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: get_taxonomy.
 */
#[Tool(
  id: 'get_taxonomy',
  label: new TranslatableMarkup('Get taxonomy'),
  description: new TranslatableMarkup('The terms of one vocabulary as a flat list with depth and parent, so you can filter search_content by "term". Vocabulary ids come from site_map.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'vocabulary' => ['type' => 'string', 'description' => 'Vocabulary machine name, e.g. "tags".'],
      'langcode' => ['type' => 'string'],
      'max_depth' => ['type' => 'integer', 'description' => 'Limit tree depth (0 = all).'],
    ],
    'required' => ['vocabulary'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetTaxonomy extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $vid = $this->str($arguments, 'vocabulary', '') ?? '';
    $result = $this->exporter->taxonomy($vid, $this->str($arguments, 'langcode'), $this->account, $this->int($arguments, 'max_depth', 0));
    return $result ?? ['error' => 'not_found', 'message' => sprintf('No vocabulary "%s".', $vid)];
  }

}
