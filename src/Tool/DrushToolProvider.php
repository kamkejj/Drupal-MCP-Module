<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\drupal_mcp\Diagnostics\ApprovedDrushCommands;
use Drupal\drupal_mcp\Diagnostics\DrushRunner;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Drupal\drupal_mcp\Settings;

/**
 * The typed Drush diagnostics tool (family: drush).
 *
 * One tool, server-defined command IDs. This is not a shell: callers choose
 * an approved command ID and typed parameters; the argv, output projection,
 * runtime bounds, and audit are fixed server-side. Requires "administer
 * site configuration" in addition to the MCP read gates, and the command
 * family must be enabled with the specific command approved in settings.
 */
final class DrushToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly DrushRunner $runner,
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $specs = ApprovedDrushCommands::all();
    $enum = array_values(array_intersect(array_keys($specs), $this->settings->drushCommands()));
    if ($enum === []) {
      // Fail closed: with no approved commands, do not advertise the tool.
      return [];
    }

    // Union of the enabled commands' typed parameters. Which subset applies
    // is decided per call in run() against the selected command's contract.
    $parameters = [];
    foreach ($enum as $id) {
      foreach ($specs[$id]->parameters as $name => $schema) {
        $parameters[$name] = $schema;
      }
    }

    return [
      new ToolDefinition(
        name: 'drupal_drush_run',
        title: 'Run approved Drush diagnostic',
        description: 'Runs one site-approved, non-destructive Drush diagnostic command with typed parameters. Only command IDs approved by the site administrator are accepted; unknown arguments are rejected before execution. Privileged: requires "administer site configuration".',
        inputSchema: ToolSchema::object([
          'command' => ['type' => 'string', 'enum' => $enum, 'description' => 'Approved diagnostic command ID.'],
          'arguments' => (object) [
            'type' => 'object',
            'description' => 'Typed parameters for the selected command.',
            'properties' => (object) $parameters,
            'additionalProperties' => FALSE,
          ],
        ], ['command']),
        handler: fn (array $args, TokenAuthUser $caller): array => $this->run($caller, (string) ($args['command'] ?? ''), (array) ($args['arguments'] ?? [])),
        family: ToolFamily::Drush,
        extraPermissions: ['administer site configuration'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function run(TokenAuthUser $caller, string $commandId, array $arguments): array {
    $specs = ApprovedDrushCommands::all();

    if (!isset($specs[$commandId]) || !\in_array($commandId, $this->settings->drushCommands(), TRUE)) {
      throw new ToolCallException(sprintf('Drush diagnostic "%s" is not approved on this site.', $commandId));
    }
    return $this->runner->run($caller, $specs[$commandId], $arguments);
  }

}
