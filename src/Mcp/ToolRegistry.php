<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Mcp\Exception\ToolCallException;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_mcp\Settings;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Psr\Log\LoggerInterface;

/**
 * Collects tool providers and filters tools for the current caller.
 *
 * Filtering happens on every request: a filtered catalogue is never cached
 * across users, tokens, or consumers.
 */
final class ToolRegistry {

  /**
   * Initializes the registry.
   *
   * @param \Drupal\drupal_mcp\Settings $settings
   *   Module settings providing enabled tool families.
   * @param \Drupal\drupal_mcp\Mcp\ToolProviderInterface[] $providers
   *   Tagged tool provider services.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for denied tool executions.
   */
  public function __construct(
    private readonly Settings $settings,
    private readonly iterable $providers,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Lists the tool definitions visible to an account.
   *
   * A tool is visible when its family is enabled in settings, the token has
   * the scope and account permission required by its operation capability,
   * and the account holds every additional Drupal permission. Only these
   * tools are registered with the SDK, so they bound both tools/list and
   * tools/call.
   *
   * @return \Drupal\drupal_mcp\Mcp\ToolDefinition[]
   *   Definitions keyed by tool name.
   */
  public function toolsForAccount(AccountInterface $account): array {
    if (!$account instanceof TokenAuthUser) {
      return [];
    }

    // Each capability is resolved once per request, not once per tool.
    $granted = [];
    foreach (OperationCapability::cases() as $capability) {
      $granted[$capability->value] = $capability->isGrantedTo($account);
    }

    $tools = [];
    foreach ($this->providers as $provider) {
      foreach ($provider->tools() as $definition) {
        if (!$this->settings->familyEnabled($definition->family) || !$granted[$definition->capability->value]) {
          continue;
        }
        foreach ($definition->extraPermissions as $permission) {
          if (!$account->hasPermission($permission)) {
            continue 2;
          }
        }
        if ($definition->availability !== NULL && !($definition->availability)($account)) {
          continue;
        }
        $tools[$definition->name] = $this->withErrorAudit($definition);
      }
    }
    return $tools;
  }

  /**
   * Wraps a handler so unexpected failures are audited with their message.
   *
   * The wrapper passes the caller through with the argument bag; the
   * ServerFactory binds the caller into the SDK-facing adapter afterwards.
   * The wrapper never leaks details to the client; it only records what the
   * SDK would otherwise swallow.
   */
  private function withErrorAudit(ToolDefinition $definition): ToolDefinition {
    $inner = $definition->handler;
    $logger = $this->logger;
    $name = $definition->name;

    $wrapper = function (array $arguments, TokenAuthUser $caller) use ($inner, $logger, $name): mixed {
      try {
        return $inner($arguments, $caller);
      }
      catch (ToolCallException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        $logger->error('MCP tool "@tool" failed unexpectedly with @type.', [
          '@tool' => $name,
          '@type' => $e::class,
        ]);
        throw $e;
      }
    };

    return $definition->withHandler($wrapper);
  }

}
