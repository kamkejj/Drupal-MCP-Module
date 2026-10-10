<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\drupal_mcp\Mutation\ConfigurationMutator;
use Drupal\drupal_mcp\Mutation\MutationExecutor;
use Drupal\drupal_mcp\Mutation\SupportedFieldTypes;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Schema\ToolAnnotations;
use Mcp\Exception\ToolCallException;
use Drupal\drupal_mcp\Mutation\MutationException;

/**
 * Exposes bounded configuration creation, never arbitrary config writes.
 */
final class ConfigurationMutationToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly ConfigurationMutator $mutator,
    private readonly MutationExecutor $executor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $name = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,31}$'];
    $label = ['type' => 'string', 'minLength' => 1, 'maxLength' => 255];
    $common = [
      'label' => $label,
      'description' => ['type' => 'string', 'maxLength' => 4096],
      'idempotency_key' => ToolSchema::idempotencyKey(),
    ];
    $contracts = [
      'drupal_content_type_create' => [
        'Create content type',
        'Creates a node type with its native Title field. Configurable fields are separate operations.',
        ['machine_name' => $name, 'new_revision' => ['type' => 'boolean']] + $common,
        ['machine_name', 'label', 'idempotency_key'],
        ['administer content types'],
      ],
      'drupal_vocabulary_create' => [
        'Create vocabulary',
        'Creates a taxonomy vocabulary without terms or automatic MCP exposure.',
        ['machine_name' => $name] + $common,
        ['machine_name', 'label', 'idempotency_key'],
        ['administer taxonomy'],
      ],
      'drupal_field_create' => [
        'Create field',
        'Creates a supported configurable field and default displays on an explicitly approved node or taxonomy bundle. Rejects existing field storage.',
        [
          'entity_type' => ['type' => 'string', 'enum' => ['node', 'taxonomy_term']],
          'bundle' => $name,
          'field_name' => ['type' => 'string', 'pattern' => '^field_[a-z][a-z0-9_]{0,25}$'],
          'definition' => SupportedFieldTypes::schema(),
          'cardinality' => ['type' => 'integer', 'enum' => [-1, 1, 2, 3, 4, 5, 10, 20, 50]],
          'required' => ['type' => 'boolean'],
        ] + $common,
        ['entity_type', 'bundle', 'field_name', 'label', 'definition', 'idempotency_key'],
        [],
      ],
    ];
    $tools = [];
    foreach ($contracts as $tool => [$title, $description, $properties, $required, $permissions]) {
      $tools[] = new ToolDefinition(
        name: $tool,
        title: $title,
        description: $description,
        inputSchema: ToolSchema::object($properties, $required),
        handler: function (array $arguments, TokenAuthUser $caller) use ($tool): array {
          // Check current policy even when the executor returns a replay.
          try {
            $this->mutator->authorize($caller, $tool, $arguments);
          }
          catch (MutationException $exception) {
            throw new ToolCallException($exception->getMessage());
          }
          return $this->executor->execute($caller, $tool, $arguments, fn (): array => $this->mutator->create($caller, $tool, $arguments));
        },
        family: ToolFamily::ConfigurationMutation,
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
        extraPermissions: $permissions,
        availability: $tool === 'drupal_field_create' ? fn (TokenAuthUser $caller): bool => $this->mutator->fieldsAvailable($caller) : NULL,
      );
    }
    return $tools;
  }

}
