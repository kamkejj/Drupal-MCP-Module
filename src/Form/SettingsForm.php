<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_mcp\Diagnostics\ApprovedConfigProjections;
use Drupal\drupal_mcp\Diagnostics\ApprovedDatabaseSurfaces;
use Drupal\drupal_mcp\Diagnostics\ApprovedDrushCommands;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mutation\SupportedFieldTypes;
use Drupal\drupal_mcp\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for the Drupal MCP endpoint.
 *
 * Form groups mirror the drupal_mcp.settings structure one to one, so
 * submission is a direct mapping. Database credentials, key paths, and
 * executable paths are deliberately not configurable here: they are
 * deployment-controlled and never tool-editable.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
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
    return 'drupal_mcp_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [Settings::NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(Settings::NAME);

    $form['endpoint'] = [
      '#type' => 'details',
      '#title' => $this->t('Endpoint and OAuth'),
      '#description' => $this->t('Clients need the OAuth scope <code>mcp:read</code> and/or <code>mcp:write</code> plus the matching "access mcp read/write" permission.'),
      '#open' => TRUE,
    ];
    $form['endpoint']['resource_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Canonical MCP resource URI'),
      '#description' => $this->t('The absolute HTTPS URI used for OAuth resource binding, ending with /mcp. Its host is always allowed.'),
      '#default_value' => $config->get('resource_uri'),
      '#required' => TRUE,
    ];
    $form['endpoint']['resource_binding'] = [
      '#type' => 'select',
      '#title' => $this->t('Resource binding'),
      '#description' => $this->t('"Require" rejects tokens not bound to the resource URI. "Audit" accepts unbound tokens with a log entry (development only). Tokens bound to another resource are always rejected.'),
      '#options' => [
        'require' => $this->t('Require (compliant)'),
        'audit' => $this->t('Audit (development fallback)'),
      ],
      '#default_value' => $config->get('resource_binding') === 'audit' ? 'audit' : 'require',
    ];
    $form['endpoint']['allowed_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional allowed hosts'),
      '#description' => $this->t('One per line, for hosts other than the resource URI host.'),
      '#default_value' => implode("\n", (array) $config->get('allowed_hosts')),
      '#rows' => 2,
    ];
    $form['endpoint']['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed CORS origins'),
      '#description' => $this->t('One absolute origin per line for browser-based clients. Leave empty to disallow cross-origin browser access.'),
      '#default_value' => implode("\n", (array) $config->get('allowed_origins')),
      '#rows' => 2,
    ];

    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits'),
      '#tree' => TRUE,
    ];
    foreach ($this->limitFields() as $key => [$title, $min]) {
      $form['limits'][$key] = [
        '#type' => 'number',
        '#title' => $title,
        '#min' => $min,
        '#default_value' => $config->get("limits.$key"),
        '#required' => TRUE,
      ];
    }

    $families = [];
    foreach (ToolFamily::cases() as $family) {
      $families[$family->value] = $family->label();
    }
    $form['families'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled tool families'),
      '#description' => $this->t('Mutations additionally require the write scope, "access mcp write", and native entity access. Privileged diagnostics require "administer site configuration".'),
      '#options' => $families,
      '#default_value' => array_keys(array_filter((array) $config->get('families'))),
    ];

    $nodeTypes = $this->bundleOptions('node');
    $form['content'] = [
      '#type' => 'details',
      '#title' => $this->t('Content'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'bundles' => $this->checkboxes($this->t('Readable content types'), $nodeTypes, $config->get('content.bundles')),
      'writable_bundles' => $this->checkboxes($this->t('Writable content types'), $nodeTypes, $config->get('content.writable_bundles'), $this->t('Must also be readable.')),
    ];

    $vocabularies = $this->bundleOptions('taxonomy_term');
    $form['taxonomy'] = [
      '#type' => 'details',
      '#title' => $this->t('Taxonomy'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'vocabularies' => $this->checkboxes($this->t('Readable vocabularies'), $vocabularies, $config->get('taxonomy.vocabularies')),
      'writable_vocabularies' => $this->checkboxes($this->t('Writable vocabularies'), $vocabularies, $config->get('taxonomy.writable_vocabularies'), $this->t('Must also be readable.')),
      'allow_term_delete' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow permanent deletion of unreferenced terms in writable vocabularies'),
        '#default_value' => (bool) $config->get('taxonomy.allow_term_delete'),
      ],
    ];

    $fieldTypes = array_keys(SupportedFieldTypes::all());
    $form['configuration'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration creation'),
      '#tree' => TRUE,
      'field_targets' => [
        '#type' => 'textarea',
        '#title' => $this->t('Approved field targets'),
        '#description' => $this->t('One per line: node:bundle or taxonomy_term:vocabulary. Targets may name bundles that do not exist yet.'),
        '#default_value' => implode("\n", (array) $config->get('configuration.field_targets')),
        '#rows' => 3,
      ],
      'field_types' => $this->checkboxes($this->t('Approved field types'), array_combine($fieldTypes, $fieldTypes), $config->get('configuration.field_types')),
    ];

    $form['drush'] = [
      '#type' => 'details',
      '#title' => $this->t('Drush diagnostics'),
      '#description' => $this->t('Commands, arguments, output projection, and runtime bounds are fixed by the module; these lists only switch them on.'),
      '#tree' => TRUE,
      'commands' => $this->checkboxes(
        $this->t('Approved commands'),
        array_map(static fn ($spec): string => $spec->id . ' — ' . $spec->description, ApprovedDrushCommands::all()),
        $config->get('drush.commands'),
      ),
      'config_names' => $this->checkboxes($this->t('Configuration readable by config:get'), ApprovedConfigProjections::options(), $config->get('drush.config_names')),
    ];

    $form['database'] = [
      '#type' => 'details',
      '#title' => $this->t('Database inspection'),
      '#description' => $this->t('Surfaces are queried only through the separately configured SELECT-only database principal.'),
      '#tree' => TRUE,
      'surfaces' => $this->checkboxes(
        $this->t('Approved surfaces'),
        array_map(static fn ($surface): string => $surface->label . ' — ' . $surface->description, ApprovedDatabaseSurfaces::all()),
        $config->get('database.surfaces'),
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $resourceUri = rtrim(trim((string) $form_state->getValue('resource_uri')), '/');
    $parts = parse_url($resourceUri);
    if (!\is_array($parts)
      || ($parts['scheme'] ?? '') !== 'https'
      || empty($parts['host'])
      || ($parts['path'] ?? '') !== '/mcp'
      || array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment'])) !== []) {
      $form_state->setErrorByName('resource_uri', $this->t('Enter an absolute HTTPS URI ending in /mcp, without credentials, a query, or a fragment.'));
    }

    foreach ($this->lines($form_state->getValue(['configuration', 'field_targets'])) as $target) {
      if (!preg_match('/^(node|taxonomy_term):[a-z][a-z0-9_]{0,31}$/D', $target)) {
        $form_state->setErrorByName('configuration][field_targets', $this->t('Use node:bundle or taxonomy_term:vocabulary machine names.'));
      }
    }

    foreach (['content' => 'bundles', 'taxonomy' => 'vocabularies'] as $group => $readable) {
      $writableKey = 'writable_' . $readable;
      $unreadable = array_diff($this->checked([$group, $writableKey], $form_state), $this->checked([$group, $readable], $form_state));
      if ($unreadable !== []) {
        $form_state->setErrorByName("$group][$writableKey", $this->t('Writable items must also be readable: @items.', ['@items' => implode(', ', $unreadable)]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $families = [];
    foreach (ToolFamily::cases() as $family) {
      $families[$family->value] = !empty($form_state->getValue(['families', $family->value]));
    }

    $this->config(Settings::NAME)
      ->set('resource_uri', rtrim(trim((string) $form_state->getValue('resource_uri')), '/'))
      ->set('resource_binding', $form_state->getValue('resource_binding') === 'audit' ? 'audit' : 'require')
      ->set('allowed_hosts', $this->lines($form_state->getValue('allowed_hosts')))
      ->set('allowed_origins', $this->lines($form_state->getValue('allowed_origins')))
      ->set('limits', array_map('intval', array_intersect_key((array) $form_state->getValue('limits'), $this->limitFields())))
      ->set('families', $families)
      ->set('content', [
        'bundles' => $this->checked(['content', 'bundles'], $form_state),
        'writable_bundles' => $this->checked(['content', 'writable_bundles'], $form_state),
      ])
      ->set('taxonomy', [
        'vocabularies' => $this->checked(['taxonomy', 'vocabularies'], $form_state),
        'writable_vocabularies' => $this->checked(['taxonomy', 'writable_vocabularies'], $form_state),
        'allow_term_delete' => (bool) $form_state->getValue(['taxonomy', 'allow_term_delete']),
      ])
      ->set('configuration', [
        'field_targets' => $this->lines($form_state->getValue(['configuration', 'field_targets'])),
        'field_types' => $this->checked(['configuration', 'field_types'], $form_state),
      ])
      ->set('drush', [
        'commands' => $this->checked(['drush', 'commands'], $form_state),
        'config_names' => $this->checked(['drush', 'config_names'], $form_state),
      ])
      ->set('database', [
        'surfaces' => $this->checked(['database', 'surfaces'], $form_state),
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * The numeric limits: key => [title, minimum].
   *
   * @return array<string, array{\Drupal\Core\StringTranslation\TranslatableMarkup, int}>
   *   Limit field definitions.
   */
  private function limitFields(): array {
    return [
      'page_size' => [$this->t('Maximum page size for list tools'), 1],
      'max_body_bytes' => [$this->t('Maximum request body size (bytes)'), 1024],
      'session_ttl' => [$this->t('Session time-to-live (seconds)'), 60],
      'idempotency_ttl' => [$this->t('Idempotency retention (seconds)'), 60],
      'flood_limit' => [$this->t('Requests per window, per user and client'), 1],
      'flood_window' => [$this->t('Rate limit window (seconds)'), 60],
    ];
  }

  /**
   * Builds a checkboxes element; stale configured values stay selectable.
   */
  private function checkboxes(mixed $title, array $options, mixed $selected, mixed $description = NULL): array {
    $selected = array_values(array_filter((array) $selected, 'is_string'));
    $options += array_combine($selected, $selected) ?: [];
    return [
      '#type' => 'checkboxes',
      '#title' => $title,
      '#description' => $description,
      '#options' => $options,
      '#default_value' => $selected,
    ];
  }

  /**
   * Bundle options for an entity type, keyed by machine name.
   *
   * @return array<string, string>
   *   Labels keyed by bundle machine name.
   */
  private function bundleOptions(string $entityTypeId): array {
    return array_map(
      static fn (array $info): string => (string) $info['label'],
      $this->bundleInfo->getBundleInfo($entityTypeId),
    );
  }

  /**
   * Returns the selected keys of a checkboxes element.
   *
   * @return list<string>
   *   Selected option keys.
   */
  private function checked(array $path, FormStateInterface $form_state): array {
    return array_values(array_map('strval', array_keys(array_filter((array) $form_state->getValue($path)))));
  }

  /**
   * Splits a textarea value into trimmed, non-empty lines.
   *
   * @return list<string>
   *   The lines.
   */
  private function lines(mixed $value): array {
    return array_values(array_filter(array_map('trim', explode("\n", (string) $value))));
  }

}
