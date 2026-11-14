<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: get_content.
 */
#[Tool(
  id: 'get_content',
  label: new TranslatableMarkup('Get content'),
  description: new TranslatableMarkup('Read one page in full as Markdown (default), plain text, or structured fields. Accepts a node id, a UUID, a path alias such as "/about", or a full URL. Returns the text, its SHA-256 hash, the canonical URL and a ready-to-use citation ("Title — URL"). Quote the URL when you use the content.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'id' => ['type' => 'string', 'description' => 'Node id, UUID, path alias or URL.'],
      'langcode' => ['type' => 'string', 'description' => 'Preferred translation, e.g. "fr". Falls back to the original.'],
      'format' => ['type' => 'string', 'enum' => ['markdown', 'text', 'fields'], 'description' => 'markdown (default), text, or fields (structured values per field plus the Markdown).'],
    ],
    'required' => ['id'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetContent extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    $ref = $this->str($arguments, 'id', '') ?? '';
    $node = $this->exporter->resolve($ref, $this->str($arguments, 'langcode'));
    if (!$node) {
      return ['error' => 'not_found', 'message' => sprintf('No content matches "%s".', $ref)];
    }
    $format = $this->str($arguments, 'format', 'markdown');
    $format = in_array($format, ['markdown', 'text', 'fields'], TRUE) ? $format : 'markdown';
    $doc = $this->exporter->document($node, $this->str($arguments, 'langcode'), $format, $this->account);
    if (!$doc) {
      return ['error' => 'not_found', 'message' => 'This content is not available to the current account.'];
    }
    return $doc;
  }

}
