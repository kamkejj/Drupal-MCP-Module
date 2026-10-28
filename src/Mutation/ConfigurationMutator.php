<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Psr\Log\LoggerInterface;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Settings;

/**
 * Owns configuration creation, native authorization, collisions, and recovery.
 *
 * @see docs/decisions/generic-mcp-configuration-creation.md
 */
final class ConfigurationMutator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Settings $settings,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
    private readonly EntityDisplayRepositoryInterface $displays,
    private readonly EntityFieldManagerInterface $fields,
    private readonly PluginManagerInterface $fieldTypes,
    private readonly PluginManagerInterface $widgets,
    private readonly PluginManagerInterface $formatters,
    private readonly StreamWrapperManagerInterface $streamWrappers,
  ) {}

  /**
   * Whether at least one approved field target is administrable by this caller.
   */
  public function fieldsAvailable(TokenAuthUser $caller): bool {
    if ($this->settings->fieldTypes() === []) {
      return FALSE;
    }
    foreach ($this->settings->fieldTargets() as $target) {
      $type = explode(':', $target, 2)[0];
      if (in_array($type, ['node', 'taxonomy_term'], TRUE) && $caller->hasPermission("administer $type fields") && $caller->hasPermission("administer $type form display") && $caller->hasPermission("administer $type display")) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks current policy before persistence or idempotency replay.
   */
  public function authorize(TokenAuthUser $caller, string $tool, array $arguments): void {
    if (!$this->settings->familyEnabled(ToolFamily::ConfigurationMutation) || !$caller->hasPermission(OperationCapability::Write->permission())) {
      throw new MutationException('access_denied', 'Configuration creation is not permitted.');
    }
    $permission = match ($tool) {
      'drupal_content_type_create' => 'administer content types',
      'drupal_vocabulary_create' => 'administer taxonomy',
      'drupal_field_create' => 'administer ' . ($arguments['entity_type'] ?? '') . ' fields',
      default => '',
    };
    if (!$permission || !$caller->hasPermission($permission)) {
      throw new MutationException('access_denied', 'The native configuration administration permission is required.');
    }
    if ($tool === 'drupal_field_create') {
      $target = ($arguments['entity_type'] ?? '') . ':' . ($arguments['bundle'] ?? '');
      $type = $arguments['definition']['type'] ?? '';
      if (!in_array($target, $this->settings->fieldTargets(), TRUE) || !in_array($type, $this->settings->fieldTypes(), TRUE)) {
        throw new MutationException('target_disabled', 'This field target or field type is not enabled.');
      }
      if (!$caller->hasPermission('administer ' . $arguments['entity_type'] . ' form display') || !$caller->hasPermission('administer ' . $arguments['entity_type'] . ' display')) {
        throw new MutationException('access_denied', 'Native form and view display administration permissions are required.');
      }
    }
  }

  /**
   * Creates configuration under a name-level lock with bounded recovery.
   */
  public function create(TokenAuthUser $caller, string $tool, array $arguments): array {
    $this->authorize($caller, $tool, $arguments);
    $id = $arguments['machine_name'] ?? $arguments['field_name'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) || !is_string($arguments['label'] ?? NULL) || trim($arguments['label']) === '' || mb_strlen($arguments['label']) > 255 || mb_strlen($arguments['description'] ?? '') > 4096) {
      throw new MutationException('invalid_input', 'Invalid machine name, label, or description.');
    }
    // Field storage is shared across bundles, so lock the storage identity.
    $target = $tool === 'drupal_field_create' ? ($arguments['entity_type'] ?? '') . '.' . $id : $id;
    $lock = 'drupal_mcp.configuration.' . $tool . '.' . $target;
    if (!$this->lock->acquire($lock, 60)) {
      throw new MutationException('in_progress', 'Configuration creation for this name is already in progress.');
    }
    $created = [];
    $outcome = 'failed';
    $bundleLock = $tool === 'drupal_field_create' ? 'drupal_mcp.configuration.display.' . ($arguments['entity_type'] ?? '') . '.' . ($arguments['bundle'] ?? '') : NULL;
    if ($bundleLock && !$this->lock->acquire($bundleLock, 60)) {
      $this->lock->release($lock);
      throw new MutationException('in_progress', 'Configuration creation for this bundle is already in progress.');
    }
    try {
      $this->authorize($caller, $tool, $arguments);
      $entityType = match ($tool) {
        'drupal_content_type_create' => 'node_type',
        'drupal_vocabulary_create' => 'taxonomy_vocabulary',
        default => 'field_storage_config',
      };
      $storage = $this->entities->getStorage($entityType);
      if ($storage->load($tool === 'drupal_field_create' ? $target : $id)) {
        throw new MutationException('already_exists', 'Configuration with this name already exists.');
      }
      $values = ['name' => $arguments['label'], 'description' => $arguments['description'] ?? $arguments['label']];
      if ($tool === 'drupal_content_type_create') {
        $entity = $storage->create($values + ['type' => $id, 'new_revision' => $arguments['new_revision'] ?? TRUE]);
        $this->save($entity, $caller, $created);
      }
      elseif ($tool === 'drupal_vocabulary_create') {
        $entity = $storage->create($values + ['vid' => $id]);
        $this->save($entity, $caller, $created);
      }
      else {
        $this->createField($caller, $arguments, $created);
      }
      $outcome = 'created';
      return [
        'machine_name' => $id,
        'created_config' => array_map(fn ($entity) => $entity->getConfigDependencyName(), $created),
      ];
    }
    catch (\Throwable $exception) {
      $this->logger->error('Configuration creation failed with {type}: {message}', [
        'type' => $exception::class,
        'message' => $exception->getMessage(),
      ]);
      // Never delete a pre-existing entity. Only exact saved snapshots qualify.
      $recoveryFailed = FALSE;
      // Preflight the dependency set before deleting anything: field
      // Storage deletion hooks cascade, so per-item checks are insufficient.
      foreach ($created as $entity) {
        $entityStorage = $this->entities->getStorage($entity->getEntityTypeId());
        $entityStorage->resetCache([$entity->id()]);
        $current = $entityStorage->load($entity->id());
        if ($current && $current->toArray() !== $entity->toArray()) {
          $recoveryFailed = TRUE;
          break;
        }
      }
      foreach ($recoveryFailed ? [] : array_reverse($created) as $entity) {
        try {
          $storage = $this->entities->getStorage($entity->getEntityTypeId());
          $storage->resetCache([$entity->id()]);
          $current = $storage->load($entity->id());
          if ($current && $current->toArray() === $entity->toArray()) {
            $current->delete();
          }
          elseif ($current) {
            $recoveryFailed = TRUE;
            // Storage deletion cascades to field instances. Never proceed to
            // a parent storage after finding a changed dependent instance.
            break;
          }
        }
        catch (\Throwable) {
          $recoveryFailed = TRUE;
          break;
        }
      }
      if ($exception instanceof MutationException && $exception->category === 'partial_failure') {
        $recoveryFailed = TRUE;
      }
      if ($recoveryFailed) {
        $outcome = 'partial_failure';
        throw new MutationException('partial_failure', 'Configuration creation failed and cleanup could not safely finish. Inspect configuration before retrying.');
      }
      throw $exception;
    }
    finally {
      $this->fields->clearCachedFieldDefinitions();
      $this->lock->release($lock);
      if ($bundleLock) {
        $this->lock->release($bundleLock);
      }
      $this->logger->notice('MCP configuration mutation: {tool}, caller {uid}, client {client}, target {target}, outcome {outcome}.', [
        'tool' => $tool,
        'uid' => $caller->id(),
        'client' => $caller->getConsumer()->uuid(),
        'target' => $target,
        'outcome' => $outcome,
      ]);
    }
  }

  /**
   * Saves a new entity only after respecting native access-hook denials.
   */
  private function save($entity, TokenAuthUser $caller, array &$created): void {
    $access = $entity->access('create', $caller, TRUE);
    // Generic field create handlers can be neutral: target permissions above
    // are mandatory, but an explicit access-hook denial must still win.
    if ($access->isForbidden()) {
      throw new MutationException('access_denied', 'Native configuration access denied creation.');
    }
    // Validate typed configuration against installed core constraints before
    // persistence. No arbitrary config/settings bypass this boundary.
    $entity->calculateDependencies();
    // Avoid caching a WeakReference on field entities: their core __sleep()
    // overrides do not exclude it when installed definitions are serialized.
    $violations = ConfigEntityAdapter::createFromEntity($entity)->validate();
    if (count($violations)) {
      throw new MutationException('invalid_configuration', 'Drupal configuration validation rejected the request.');
    }
    // Track before save as hooks may throw after a persistent write.
    $created[] = $entity;
    $entity->save();
  }

  /**
   * Creates a reviewed field and default display components.
   */
  private function createField(TokenAuthUser $caller, array $arguments, array &$created): void {
    $entityType = $arguments['entity_type'];
    $bundle = $arguments['bundle'];
    $name = $arguments['field_name'];
    if (!in_array($entityType, ['node', 'taxonomy_term'], TRUE) || !preg_match('/^field_[a-z][a-z0-9_]{0,25}$/D', $name)) {
      throw new MutationException('invalid_target', 'Unsupported field target or name.');
    }
    $bundleType = $entityType === 'node' ? 'node_type' : 'taxonomy_vocabulary';
    if (!$this->entities->getStorage($bundleType)->load($bundle)) {
      throw new MutationException('missing_bundle', 'The target bundle does not exist.');
    }
    [$storageSettings, $instanceSettings] = SupportedFieldTypes::settings($arguments['definition']);
    $type = $arguments['definition']['type'];
    [$widget, $formatter] = SupportedFieldTypes::all()[$type];
    if (!$this->fieldTypes->hasDefinition($type) || !$this->widgets->hasDefinition($widget) || !$this->formatters->hasDefinition($formatter)) {
      throw new MutationException('missing_plugin', 'A required field or display plugin is not enabled.');
    }
    if ($type === 'entity_reference') {
      $referenceBundleType = $storageSettings['target_type'] === 'node' ? 'node_type' : 'taxonomy_vocabulary';
      foreach ($arguments['definition']['target_bundles'] as $targetBundle) {
        if (!$this->entities->getStorage($referenceBundleType)->load($targetBundle)) {
          throw new MutationException('missing_reference_bundle', 'A reference target bundle does not exist.');
        }
      }
    }
    // The same WRITE_VISIBLE filter ImageItem uses for upload destinations.
    if ($type === 'image' && !\array_key_exists($storageSettings['uri_scheme'], $this->streamWrappers->getWrappers(StreamWrapperInterface::WRITE_VISIBLE))) {
      throw new MutationException('unavailable_scheme', 'The requested file storage scheme is not available on this site.');
    }
    $cardinality = $arguments['cardinality'] ?? 1;
    if (!in_array($cardinality, [-1, 1, 2, 3, 4, 5, 10, 20, 50], TRUE)) {
      throw new MutationException('invalid_cardinality', 'Unsupported field cardinality.');
    }
    $storage = $this->entities->getStorage('field_storage_config')->create([
      'entity_type' => $entityType,
      'field_name' => $name,
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storageSettings,
    ]);
    $field = $this->entities->getStorage('field_config')->create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $arguments['label'],
      'description' => $arguments['description'] ?? '',
      'required' => $arguments['required'] ?? FALSE,
      'settings' => $instanceSettings,
    ]);
    $displayId = "$entityType.$bundle.default";
    $snapshots = [];
    foreach (['entity_form_display', 'entity_view_display'] as $displayType) {
      $existing = $this->entities->getStorage($displayType)->load($displayId);
      $snapshots[$displayType] = $existing?->toArray();
    }
    $this->save($storage, $caller, $created);
    $this->save($field, $caller, $created);
    $this->fields->clearCachedFieldDefinitions();
    foreach (array_keys($snapshots) as $displayType) {
      $this->entities->getStorage($displayType)->resetCache([$displayId]);
    }
    $form = $this->displays->getFormDisplay($entityType, $bundle, 'default');
    $view = $this->displays->getViewDisplay($entityType, $bundle, 'default');
    foreach ([$form, $view] as $display) {
      if ($display->access($display->isNew() ? 'create' : 'update', $caller, TRUE)->isForbidden()) {
        throw new MutationException('access_denied', 'Native display administration access denied creation.');
      }
    }
    $written = [];
    try {
      foreach ([[$form, $widget], [$view, $formatter]] as [$display, $plugin]) {
        $options = ['type' => $plugin];
        if ($display->getEntityTypeId() === 'entity_view_display') {
          $options['label'] = 'above';
        }
        $display->setComponent($name, $options);
        // Track attempts too: a save hook can fail after the write.
        $written[$display->getEntityTypeId()] = $display;
        $display->save();
      }
    }
    catch (\Throwable $exception) {
      $failed = FALSE;
      foreach ($written as $displayType => $display) {
        try {
          $displayStorage = $this->entities->getStorage($displayType);
          $displayStorage->resetCache([$displayId]);
          $current = $displayStorage->load($displayId);
          if (!$current) {
            continue;
          }
          if ($current->toArray() !== $display->toArray()) {
            $failed = TRUE;
            continue;
          }
          if ($snapshots[$displayType] === NULL) {
            $current->delete();
          }
          else {
            foreach ($snapshots[$displayType] as $property => $value) {
              $current->set($property, $value);
            }
            $current->save();
          }
        }
        catch (\Throwable) {
          $failed = TRUE;
        }
      }
      if ($failed) {
        throw new MutationException('partial_failure', 'Display cleanup could not safely finish. Inspect configuration before retrying.');
      }
      throw $exception;
    }
  }

}
