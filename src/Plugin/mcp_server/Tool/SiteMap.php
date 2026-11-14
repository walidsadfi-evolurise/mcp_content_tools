<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * MCP tool: site_map.
 */
#[Tool(
  id: 'site_map',
  label: new TranslatableMarkup('Site map'),
  description: new TranslatableMarkup('Orientation for this site: name, base URL, languages, the content types exposed to agents with their counts and fields, and the taxonomy vocabularies. Call it first to learn which "types" and "vocabulary" values the other tools accept.'),
  inputSchema: ['type' => 'object', 'properties' => []],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SiteMap extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if ($denied = $this->denied()) {
      return $denied;
    }
    return $this->exporter->siteMap($this->account);
  }

}
