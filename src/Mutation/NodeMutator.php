<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\file\FileInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\filter\FilterFormatInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Settings;

/**
 * Owns the complete policy and persistence boundary for node mutations.
 *
 * Create and update only. The only writable base field is "title"; every
 * other base field, including bundle overrides, is protected and all
 * remaining writes go through configured fields, each gated by edit access
 * and entity validation. Referenced entities and files must be accessible
 * to the caller.
 */
final class NodeMutator implements EntityMutatorInterface {

  private const FORMATTED_TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];
  private const REFERENCE_TYPES = ['entity_reference', 'taxonomy_term_reference'];
  private const FILE_TYPES = ['file', 'image'];

  /**
   * Item properties a caller may supply per file field type.
   */
  private const FILE_ITEM_PROPERTIES = [
    'file' => ['target_id', 'description'],
    'image' => ['target_id', 'alt', 'title'],
  ];
  private const COMMAND_KEYS = [
    'type', 'id', 'expected_revision_id', 'changes', 'idempotency_key', '_session', '_request',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Settings $settings,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function create(AccountInterface $account, array $command): array {
    $started = microtime(TRUE);
    $bundle = (string) ($command['type'] ?? '');
    try {
      $this->assertEnabledBundle($bundle);
      $storage = $this->entityTypeManager->getStorage('node');
      $access = $this->entityTypeManager->getAccessControlHandler('node')
        ->createAccess($bundle, $account, [], TRUE);
      if (!$access->isAllowed()) {
        throw $this->failure('entity_access_denied', 'Node creation is not permitted.');
      }

      $node = $storage->create(['type' => $bundle]);
      \assert($node instanceof NodeInterface);
      $this->applyFields($account, $node, $command, TRUE);
      $this->validate($node);
      $this->prepareRevision($node);
      $node->save();
      $result = $this->project($node, $this->fieldNames($command));
      $this->audit('create', 'success', $bundle, (int) $node->id(), $result['revision_id'], $started, $command, $account);
      return $result;
    }
    catch (NodeMutationException $exception) {
      $this->audit('create', $exception->category, $bundle, NULL, NULL, $started, $command, $account);
      throw $exception;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function update(AccountInterface $account, array $command): array {
    $started = microtime(TRUE);
    $id = (int) ($command['id'] ?? 0);
    $lockName = 'drupal_mcp:node:' . $id;
    if ($id < 1 || !$this->lock->acquire($lockName, 30.0)) {
      throw $this->failure('temporary_failure', 'The node is temporarily unavailable; retry later.');
    }

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $storage->resetCache([$id]);
      $node = $storage->load($id);
      if (!$node instanceof NodeInterface) {
        throw $this->failure('not_found', 'The node was not found or is inaccessible.');
      }
      $bundle = $node->bundle();
      // Entity access is checked before the bundle allowlist and revision
      // precondition so callers without update access cannot probe which
      // bundles are writable or learn the current revision id.
      if (!$node->access('update', $account)) {
        throw $this->failure('entity_access_denied', 'The node was not found or is inaccessible.');
      }
      $this->assertEnabledBundle($bundle);
      $expected = (int) ($command['expected_revision_id'] ?? 0);
      $current = $this->revisionId($node);
      if ($expected < 1 || $expected !== $current) {
        throw $this->failure('revision_conflict', sprintf('Revision conflict; current revision is %d.', $current));
      }
      $changes = $command['changes'] ?? NULL;
      if (!\is_array($changes) || $changes === []) {
        throw $this->failure('invalid_value', 'At least one change is required.');
      }
      $this->applyFields($account, $node, $changes, FALSE);
      $this->validate($node);
      $this->prepareRevision($node);
      $node->save();
      $result = $this->project($node, $this->fieldNames($changes));
      $this->audit('update', 'success', $bundle, $id, $result['revision_id'], $started, $command, $account);
      return $result;
    }
    catch (NodeMutationException $exception) {
      $storage->resetCache([$id]);
      $this->audit('update', $exception->category, $bundle ?? NULL, $id ?: NULL, NULL, $started, $command, $account);
      throw $exception;
    }
    catch (\Throwable $exception) {
      $storage->resetCache([$id]);
      $this->audit('update', 'unexpected_failure', $bundle ?? NULL, $id ?: NULL, NULL, $started, $command, $account);
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(AccountInterface $account, array $command): array {
    throw $this->failure('mutation_disabled', 'Node deletion is not supported through MCP.');
  }

  /**
   * Applies writable fields with per-type value normalization.
   */
  private function applyFields(AccountInterface $account, NodeInterface $node, array $input, bool $creating): void {
    $names = [];
    foreach (array_keys($input) as $name) {
      if (\is_string($name) && !\in_array($name, self::COMMAND_KEYS, TRUE)) {
        $this->assertWritableField($account, $node, $name);
        $names[] = $name;
      }
    }
    if ($names === []) {
      throw $this->failure('invalid_value', 'At least one writable field is required.');
    }
    if ($creating && (!\is_string($input['title'] ?? NULL) || trim($input['title']) === '')) {
      throw $this->failure('invalid_value', 'A non-empty node title is required.');
    }
    $before = $creating ? NULL : $this->providedValues($node, $names);
    foreach ($names as $name) {
      $this->setField($account, $node, $name, $input[$name]);
    }
    if (!$creating && $before === $this->providedValues($node, $names)) {
      throw $this->failure('no_changes', 'The requested update does not change the node.');
    }
  }

  /**
   * Enforces the protected base-field denylist and per-field edit access.
   *
   * Base fields are identified through their storage, not their definition
   * class: bundle overrides (BaseFieldOverride) are configuration entities
   * rather than BaseFieldDefinition instances. Reference-like types without
   * a dedicated, access-checked normalizer are refused outright, so a target
   * id can never reach storage without the caller's own access check.
   */
  private function assertWritableField(AccountInterface $account, NodeInterface $node, string $name): void {
    $definition = $node->getFieldDefinition($name);
    if ($definition === NULL
      || ($name !== 'title' && $definition->getFieldStorageDefinition()->isBaseField())) {
      throw $this->failure('field_not_writable', sprintf('Field "%s" is not writable.', $name));
    }
    $type = $definition->getType();
    if (is_subclass_of($definition->getItemDefinition()->getClass(), EntityReferenceItem::class)
      && !\in_array($type, self::REFERENCE_TYPES, TRUE)
      && !\in_array($type, self::FILE_TYPES, TRUE)) {
      throw $this->failure('field_not_writable', sprintf('Field "%s" is not writable.', $name));
    }
    if (!$node->get($name)->access('edit', $account)) {
      throw $this->failure('field_not_writable', sprintf('Field "%s" is not writable.', $name));
    }
  }

  /**
   * Sets one field with type-aware normalization.
   */
  private function setField(AccountInterface $account, NodeInterface $node, string $name, mixed $value): void {
    if ($name === 'title') {
      if (!\is_string($value) || trim($value) === '') {
        throw $this->failure('invalid_value', 'The node title must be a non-empty string.');
      }
      $node->setTitle(trim($value));
      return;
    }
    $definition = $node->getFieldDefinition($name);
    $type = $definition->getType();
    if (\in_array($type, self::FORMATTED_TEXT_TYPES, TRUE)) {
      $items = [$this->normalizeFormattedText($account, $value)];
    }
    elseif (\in_array($type, self::REFERENCE_TYPES, TRUE)) {
      $items = $this->normalizeReferences($account, $definition, $value);
    }
    elseif (\in_array($type, self::FILE_TYPES, TRUE)) {
      $items = $this->normalizeFiles($account, $type, $value);
    }
    else {
      $items = \is_array($value) ? $value : [$value];
    }
    $node->set($name, $items);
  }

  /**
   * Normalizes file items and authorizes every file for the caller.
   *
   * A caller may only reference a file it can already view and download
   * under Drupal's native file access: its own files, public files, or
   * private files reachable through an entity it may view. Every submitted
   * item is checked for the explicit caller rather than relying on core's
   * ReferenceAccess constraint, which reads the ambient current user and can
   * skip newly added targets on update.
   */
  private function normalizeFiles(AccountInterface $account, string $type, mixed $value): array {
    if (!\is_array($value) || !array_is_list($value)) {
      throw $this->failure('invalid_reference', 'File fields require a list of file items.');
    }
    $allowed = self::FILE_ITEM_PROPERTIES[$type];
    $items = [];
    foreach ($value as $item) {
      if (!\is_array($item) || !\is_int($item['target_id'] ?? NULL) || $item['target_id'] < 1
        || array_diff(array_keys($item), $allowed) !== []) {
        throw $this->failure('invalid_reference', 'A file item is malformed.');
      }
      foreach (array_diff($allowed, ['target_id']) as $property) {
        if (\array_key_exists($property, $item) && !\is_string($item[$property])) {
          throw $this->failure('invalid_reference', 'A file item is malformed.');
        }
      }
      $items[] = $item;
    }
    if ($items === []) {
      return [];
    }

    $ids = array_column($items, 'target_id');
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple($ids);
    foreach ($ids as $id) {
      $file = $files[$id] ?? NULL;
      if (!$file instanceof FileInterface
        || !$file->access('view', $account)
        || !$file->access('download', $account)) {
        throw $this->failure('invalid_reference', 'A file reference is invalid or inaccessible.');
      }
    }
    return $items;
  }

  /**
   * Normalizes and authorizes a formatted text item.
   */
  private function normalizeFormattedText(AccountInterface $account, mixed $value): array {
    if (!\is_array($value) || !\is_string($value['value'] ?? NULL) || !\is_string($value['format'] ?? NULL)) {
      throw $this->failure('invalid_text_format', 'Text fields require string value and format properties.');
    }
    $format = $this->entityTypeManager->getStorage('filter_format')->load($value['format']);
    if (!$format instanceof FilterFormatInterface || !$format->access('use', $account)) {
      throw $this->failure('invalid_text_format', 'The requested text format is not available.');
    }
    return ['value' => $value['value'], 'format' => $value['format']];
  }

  /**
   * Normalizes and validates entity references.
   */
  private function normalizeReferences(AccountInterface $account, FieldDefinitionInterface $definition, mixed $value): array {
    if (!\is_array($value) || (!array_is_list($value) && !\is_int($value))) {
      throw $this->failure('invalid_reference', 'References must be a list of entity IDs.');
    }
    $ids = \is_int($value) ? [$value] : $value;
    if ($ids === []) {
      return [];
    }
    $targetType = (string) ($definition->getSetting('target_type') ?? 'taxonomy_term');
    $handler = (array) ($definition->getSetting('handler_settings') ?? []);
    $bundles = array_values((array) ($handler['target_bundles'] ?? []));
    $targets = $this->entityTypeManager->getStorage($targetType)->loadMultiple($ids);
    $items = [];
    foreach ($ids as $id) {
      $target = $targets[$id] ?? NULL;
      if (!\is_int($id) || $id < 1 || $target === NULL
        || ($bundles !== [] && !\in_array($target->bundle(), $bundles, TRUE))
        || !$target->access('view', $account)) {
        throw $this->failure('invalid_reference', 'A reference is invalid or inaccessible.');
      }
      $items[] = ['target_id' => $id];
    }
    return $items;
  }

  /**
   * Validates the complete entity.
   */
  private function validate(NodeInterface $node): void {
    $violations = $node->validate();
    if ($violations->count() > 0) {
      throw $this->failure('validation_failed', 'The node failed validation.');
    }
  }

  /**
   * Enforces fail-closed mutation and bundle configuration.
   */
  private function assertEnabledBundle(string $bundle): void {
    if (!$this->settings->familyEnabled(ToolFamily::NodeMutation)) {
      throw $this->failure('mutation_disabled', 'Node mutations are disabled.');
    }
    if (!\in_array($bundle, $this->settings->writableNodeBundles(), TRUE)) {
      throw $this->failure('target_not_writable', 'The content type is not writable through MCP.');
    }
  }

  /**
   * Always creates a new revision with an MCP revision log message.
   */
  private function prepareRevision(NodeInterface $node): void {
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Updated by Drupal MCP node mutation.');
  }

  /**
   * Returns the optimistic-concurrency revision identifier.
   */
  private function revisionId(NodeInterface $node): int {
    return (int) ($node->getRevisionId());
  }

  /**
   * Projects only approved mutation result fields.
   */
  private function project(NodeInterface $node, array $names): array {
    $fields = ['title' => (string) $node->label()];
    foreach ($names as $name) {
      if ($name === 'title') {
        continue;
      }
      $list = $node->get($name);
      $type = $node->getFieldDefinition($name)->getType();
      if (\in_array($type, self::FORMATTED_TEXT_TYPES, TRUE)) {
        $item = $list->first();
        $fields[$name] = $item === FALSE ? NULL : ['value' => (string) $item->value, 'format' => (string) $item->format];
      }
      elseif (\in_array($type, self::REFERENCE_TYPES, TRUE) || \in_array($type, self::FILE_TYPES, TRUE)) {
        $fields[$name] = array_values(array_map(
          static fn (array $entry): int => (int) $entry['target_id'],
          $list->getValue(),
        ));
      }
      else {
        $item = $list->first();
        $fields[$name] = $item === FALSE ? NULL : $item->value;
      }
    }
    return [
      'id' => (int) $node->id(),
      'type' => $node->bundle(),
      'revision_id' => $this->revisionId($node),
      'changed' => (int) $node->getChangedTime(),
      'fields' => $fields,
    ];
  }

  /**
   * Returns the command's writable field names.
   *
   * @return string[]
   *   The writable field names, in command order.
   */
  private function fieldNames(array $input): array {
    return array_values(array_filter(
      array_keys($input),
      static fn (mixed $name): bool => \is_string($name) && !\in_array($name, self::COMMAND_KEYS, TRUE),
    ));
  }

  /**
   * Returns canonical field values for no-op detection.
   */
  private function providedValues(NodeInterface $node, array $names): array {
    $values = [];
    foreach ($names as $name) {
      $values[$name] = $name === 'title'
        ? ['title' => (string) $node->getTitle()]
        : $node->get($name)->getValue();
    }
    return $values;
  }

  /**
   * Creates a categorized safe failure.
   */
  private function failure(string $category, string $message): NodeMutationException {
    return new NodeMutationException($category, $message);
  }

  /**
   * Records content-free mutation audit metadata.
   */
  private function audit(string $operation, string $outcome, ?string $bundle, ?int $nodeId, ?int $revisionId, float $started, array $command, AccountInterface $account): void {
    $consumer = method_exists($account, 'getConsumer') ? $account->getConsumer()->getClientId() : NULL;
    $key = \is_string($command['idempotency_key'] ?? NULL) ? $command['idempotency_key'] : '';
    $this->logger->notice('MCP node mutation audit.', [
      'uid' => $account->id(),
      'consumer' => $consumer,
      'operation' => $operation,
      'bundle' => $bundle,
      'node_id' => $nodeId,
      'revision_id' => $revisionId,
      'idempotency_digest' => $key === '' ? NULL : substr(hash('sha256', $key), 0, 12),
      'outcome' => $outcome,
      'duration_ms' => (int) ((microtime(TRUE) - $started) * 1000),
    ]);
  }

}
