<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

use Drupal\drupal_mcp\Settings;

/**
 * The server-defined catalogue of approvable Drush diagnostics.
 *
 * Nothing here executes anything; it only describes fixed contracts. A
 * command becomes callable when its ID is additionally enabled in the
 * drupal_mcp.settings "drush.commands" list (fail closed by default).
 *
 * Each projection keeps only explicitly allowlisted, non-sensitive output:
 * no database credentials, tokens, private configuration, environment
 * variables, or filesystem paths.
 */
final class ApprovedDrushCommands {

  /**
   * Executes the operation.
   *
   * @return array<string, \Drupal\drupal_mcp\Diagnostics\DrushCommandSpec>
   *   The operation result.
   */
  public static function all(): array {
    $specs = [];

    $specs['status'] = new DrushCommandSpec(
      id: 'status',
      drushCommand: 'status',
      description: 'Drush status: versions, bootstrap and database connectivity state. No credentials, hosts, or paths.',
      parameters: [],
      argv: fn (array $arguments, Settings $settings): ?array => [],
      project: fn (array $out): array => self::pick($out, [
        'drupal-version', 'drush-version', 'php-version', 'bootstrap',
        'db-driver', 'db-status', 'db-server', 'theme-default', 'theme-admin',
        'install-profile', 'language-default',
      ]),
    );

    $specs['pm:list'] = new DrushCommandSpec(
      id: 'pm:list',
      drushCommand: 'pm:list',
      description: 'Installed projects and themes with their machine names, type, and enable state.',
      parameters: [
        'type' => [
          'type' => 'string',
          'enum' => ['module', 'theme'],
          'description' => 'Optional filter by extension type.',
        ],
      ],
      argv: function (array $arguments, Settings $settings): ?array {
        $argv = ['--format=json'];
        $type = $arguments['type'] ?? NULL;
        if ($type !== NULL) {
          if (!\in_array($type, ['module', 'theme'], TRUE)) {
            return NULL;
          }
          $argv[] = '--type=' . $type;
        }
        return $argv;
      },
      // Drush emits filesystem paths and packaging metadata per extension;
      // only identity, type, enable state, and version leave the server.
      project: fn (array $out): array => array_map(
        fn (array $extension): array => self::pick($extension, ['name', 'display_name', 'type', 'status', 'version']),
        array_filter($out, 'is_array'),
      ),
    );

    $specs['config:get'] = new DrushCommandSpec(
      id: 'config:get',
      drushCommand: 'config:get',
      description: 'Reads one server-curated configuration projection enabled in MCP settings. Unapproved objects and properties are refused or removed before returning output.',
      parameters: [
        'name' => [
          'type' => 'string',
          'pattern' => '^[a-zA-Z0-9_\.]+$',
          'description' => 'Approved configuration object name.',
        ],
      ],
      argv: function (array $arguments, Settings $settings): ?array {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '' || !ApprovedConfigProjections::supports($name) || !\in_array($name, $settings->drushConfigNames(), TRUE)) {
          return NULL;
        }
        return [$name, '--format=json'];
      },
      project: fn (array $out, array $arguments): array => ApprovedConfigProjections::project(
        (string) ($arguments['name'] ?? ''),
        $out,
      ),
    );

    return $specs;
  }

  /**
   * Keeps only the listed keys of Drush JSON output.
   *
   * @param array<string, mixed> $out
   *   Parsed Drush JSON output.
   * @param list<string> $keys
   *   Approved top-level keys.
   *
   * @return array<string, mixed>
   *   Approved values keyed by their original names.
   */
  private static function pick(array $out, array $keys): array {
    $picked = [];
    foreach ($keys as $key) {
      if (\array_key_exists($key, $out)) {
        $picked[$key] = $out[$key];
      }
    }
    return $picked;
  }

}
