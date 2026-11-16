<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Plugin\mcp_server\Tool;

use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_content_tools\ContentExporter;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared wiring and permission check for the content tools.
 */
abstract class ContentToolBase extends ToolPluginBase {

  /**
   * The permission this tool requires.
   */
  protected const PERMISSION = 'use mcp content tools';

  /**
   * The exporter doing the access-checked work.
   *
   * @var \Drupal\mcp_content_tools\ContentExporter
   */
  protected ContentExporter $exporter;

  /**
   * The account the MCP session runs as.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $account;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'));
    $instance->exporter = $container->get('mcp_content_tools.exporter');
    $instance->account = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Enabled by default: the module exists to expose these tools.
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * Access failure payload, or NULL when the account may call the tool.
   */
  protected function denied(): ?array {
    if ($this->account->hasPermission(static::PERMISSION)) {
      return NULL;
    }
    return [
      'error' => 'forbidden',
      'message' => sprintf('The account this MCP session runs as lacks the "%s" permission.', static::PERMISSION),
    ];
  }

  /**
   * Argument helpers.
   */
  protected function str(array $args, string $key, ?string $default = NULL): ?string {
    $v = $args[$key] ?? NULL;
    if ($v === NULL || $v === '') {
      return $default;
    }
    return is_scalar($v) ? trim((string) $v) : $default;
  }

  /**
   * Reads an integer argument, falling back to a default.
   */
  protected function int(array $args, string $key, int $default): int {
    $v = $args[$key] ?? NULL;
    return is_numeric($v) ? (int) $v : $default;
  }

  /**
   * Reads a list argument given as an array or a comma-separated string.
   */
  protected function list(array $args, string $key): array {
    $v = $args[$key] ?? [];
    if (is_string($v)) {
      $v = array_map('trim', explode(',', $v));
    }
    return is_array($v) ? array_values(array_filter(array_map('strval', $v), 'strlen')) : [];
  }

  /**
   * Parses a point in time given in one of the accepted forms.
   *
   * Accepts "2026-01-01", "2026-01-01T10:00:00Z", relative durations such as
   * "7d" or "24h", or a unix timestamp.
   */
  protected function since(array $args, string $key): ?int {
    $v = $this->str($args, $key);
    if ($v === NULL) {
      return NULL;
    }
    if (ctype_digit($v)) {
      return (int) $v;
    }
    if (preg_match('/^(\d+)([hdw])$/', $v, $m)) {
      $mult = ['h' => 3600, 'd' => 86400, 'w' => 604800][$m[2]];
      return time() - (int) $m[1] * $mult;
    }
    $ts = strtotime($v);
    return $ts === FALSE ? NULL : $ts;
  }

}
