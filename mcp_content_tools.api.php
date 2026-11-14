<?php

/**
 * @file
 * Hooks provided by MCP Content Tools.
 */

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Decide whether a field may be exposed to MCP clients.
 *
 * Runs after the configured exclusions; set $exposed to FALSE to hide a field
 * for reasons configuration cannot express.
 *
 * @param bool $exposed
 *   Current decision.
 * @param string $name
 *   Field machine name.
 * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
 *   Field definition.
 */
function hook_mcp_content_tools_field_exposed_alter(bool &$exposed, string $name, FieldDefinitionInterface $definition): void {
  if ($definition->getTargetBundle() === 'job_offer' && $name === 'field_salary') {
    $exposed = FALSE;
  }
}
