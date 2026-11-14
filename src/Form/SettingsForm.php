<?php

declare(strict_types=1);

namespace Drupal\mcp_content_tools\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Which content and fields the tools expose.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mcp_content_tools_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mcp_content_tools.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mcp_content_tools.settings');
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $id => $info) {
      $options[$id] = $info['label'];
    }
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('These tools are read-only and always check the calling account\'s access to each node. Settings here narrow further what any agent may see. Grant <em>Use MCP content tools</em> (and <em>Access MCP server</em>) to the role your MCP clients authenticate as.') . '</p>',
    ];
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types exposed'),
      '#options' => $options,
      '#default_value' => $config->get('bundles') ?? [],
      '#description' => $this->t('Leave everything unticked to expose all content types.'),
    ];
    $form['excluded_fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fields never exposed'),
      '#default_value' => implode("\n", $config->get('excluded_fields') ?? []),
      '#description' => $this->t('One field machine name per line, e.g. <code>field_internal_notes</code>.'),
      '#rows' => 4,
    ];
    $form['excluded_field_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Field name patterns never exposed'),
      '#default_value' => implode("\n", $config->get('excluded_field_patterns') ?? []),
      '#description' => $this->t('Shell-style patterns, one per line: <code>field_internal_*</code>, <code>*_private</code>.'),
      '#rows' => 3,
    ];
    $form['max_results'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum results per search / listing'),
      '#default_value' => $config->get('max_results') ?? 20,
      '#min' => 1,
      '#max' => 200,
    ];
    $form['max_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum characters per document'),
      '#default_value' => $config->get('max_chars') ?? 20000,
      '#min' => 500,
      '#max' => 500000,
      '#description' => $this->t('Longer documents are truncated and flagged <code>truncated: true</code>.'),
    ];
    $form['export_page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Documents per export page'),
      '#default_value' => $config->get('export_page_size') ?? 50,
      '#min' => 1,
      '#max' => 200,
    ];
    $form['include_unpublished'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include unpublished content for accounts allowed to view it'),
      '#default_value' => $config->get('include_unpublished') ?? FALSE,
      '#description' => $this->t('Off: only published content, whatever the account may see. On: drafts are returned when the account has the relevant permission (editorial agents).'),
    ];
    $form['include_author'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include the author display name'),
      '#default_value' => $config->get('include_author') ?? FALSE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $lines = static fn(string $v): array => array_values(array_filter(array_map('trim', preg_split('/\R/', $v) ?: []), 'strlen'));
    $this->config('mcp_content_tools.settings')
      ->set('bundles', array_values(array_filter($form_state->getValue('bundles'))))
      ->set('excluded_fields', $lines((string) $form_state->getValue('excluded_fields')))
      ->set('excluded_field_patterns', $lines((string) $form_state->getValue('excluded_field_patterns')))
      ->set('max_results', (int) $form_state->getValue('max_results'))
      ->set('max_chars', (int) $form_state->getValue('max_chars'))
      ->set('export_page_size', (int) $form_state->getValue('export_page_size'))
      ->set('include_unpublished', (bool) $form_state->getValue('include_unpublished'))
      ->set('include_author', (bool) $form_state->getValue('include_author'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
