<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Drush\Commands;

use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_content_tools\ContentExporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Exports the corpus from the command line.
 *
 * Produces the same documents as the export_content MCP tool.
 */
final class McpContentToolsCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly ContentExporter $exporter,
    private readonly AccountInterface $currentUser,
  ) {
    parent::__construct();
  }

  /**
   * Export content as JSON Lines, one document per line, for indexing.
   */
  #[CLI\Command(name: 'mcp-content-tools:export', aliases: ['mct-export'])]
  #[CLI\Option(name: 'types', description: 'Comma-separated content types.')]
  #[CLI\Option(name: 'langcode', description: 'One language only.')]
  #[CLI\Option(name: 'since', description: 'Only content changed since ("7d", date, timestamp).')]
  #[CLI\Usage(name: 'drush mct-export --since=7d > delta.jsonl', description: 'Incremental export of the last week.')]
  #[CLI\Usage(name: 'drush --user=indexer mct-export > corpus.jsonl', description: 'Export what the "indexer" account may see.')]
  public function export(array $options = ['types' => '', 'langcode' => NULL, 'since' => NULL]): void {
    $types = array_values(array_filter(array_map('trim', explode(',', (string) $options['types']))));
    $since = NULL;
    if ($options['since']) {
      $v = (string) $options['since'];
      $units = ['h' => 3600, 'd' => 86400, 'w' => 604800];
      if (ctype_digit($v)) {
        $since = (int) $v;
      }
      elseif (preg_match('/^(\d+)([hdw])$/', $v, $m)) {
        $since = time() - (int) $m[1] * $units[$m[2]];
      }
      else {
        $since = strtotime($v) ?: NULL;
      }
    }
    $cursor = NULL;
    $n = 0;
    do {
      $page = $this->exporter->export($types, $options['langcode'] ?: NULL, $since, $cursor, 200, $this->currentUser);
      foreach ($page['documents'] as $doc) {
        $this->output()->writeln(json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $n++;
      }
      $cursor = $page['next_cursor'];
    } while ($page['has_more'] && $cursor);
    $this->logger()->notice(dt('@n documents exported.', ['@n' => $n]));
  }

}
