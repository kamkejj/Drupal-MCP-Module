<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mutation\SupportedFieldTypes;

/**
 * The one typed reader of drupal_mcp.settings.
 *
 * Every consumer asks this class instead of reading configuration keys
 * directly, so defaults, clamping, and fail-closed fallbacks live in one
 * place. Missing or malformed values always resolve to the most restrictive
 * behaviour: families off, allowlists empty, resource binding required.
 */
final class Settings {

  public const NAME = 'drupal_mcp.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The canonical absolute URI of this MCP resource (RFC 8707 / RFC 9728).
   */
  public function resourceUri(): string {
    return (string) $this->get('resource_uri');
  }

  /**
   * Whether tokens without a verifiable resource binding are rejected.
   *
   * Only an explicit "audit" relaxes binding; anything else requires it.
   */
  public function requiresResourceBinding(): bool {
    return $this->get('resource_binding') !== 'audit';
  }

  /**
   * Hosts that may reach the endpoint: the resource URI host plus extras.
   *
   * @return list<string>
   *   Non-empty, lowercase host names.
   */
  public function allowedHosts(): array {
    $hosts = $this->strings('allowed_hosts');
    $resourceHost = parse_url($this->resourceUri(), PHP_URL_HOST);
    if (\is_string($resourceHost)) {
      array_unshift($hosts, $resourceHost);
    }
    return array_values(array_unique(array_map('strtolower', $hosts)));
  }

  /**
   * Browser origins allowed by CORS, in their configured (exact) form.
   *
   * @return list<string>
   *   Non-empty origin URLs.
   */
  public function allowedOrigins(): array {
    return $this->strings('allowed_origins');
  }

  /**
   * The server-side maximum page size for every paging surface.
   */
  public function pageSize(): int {
    return $this->int('limits.page_size', 20, 1);
  }

  /**
   * Clamps a requested page size to the configured maximum.
   */
  public function clampPageSize(?int $requested): int {
    return $requested !== NULL && $requested > 0 ? min($requested, $this->pageSize()) : $this->pageSize();
  }

  /**
   * Maximum accepted request body, in bytes.
   */
  public function maxBodyBytes(): int {
    return $this->int('limits.max_body_bytes', 1048576, 1024);
  }

  /**
   * MCP session inactivity lifetime, in seconds.
   */
  public function sessionTtl(): int {
    return $this->int('limits.session_ttl', 3600, 60);
  }

  /**
   * Mutation idempotency record lifetime, in seconds.
   */
  public function idempotencyTtl(): int {
    return $this->int('limits.idempotency_ttl', 86400, 60);
  }

  /**
   * Requests allowed per flood window, per user and client.
   */
  public function floodLimit(): int {
    return $this->int('limits.flood_limit', 240, 1);
  }

  /**
   * Flood control window, in seconds.
   */
  public function floodWindow(): int {
    return $this->int('limits.flood_window', 3600, 60);
  }

  /**
   * Whether a tool family is enabled.
   */
  public function familyEnabled(ToolFamily $family): bool {
    return $this->get('families.' . $family->value) === TRUE;
  }

  /**
   * Node bundles exposed to content reads.
   *
   * @return list<string>
   *   Bundle machine names.
   */
  public function nodeBundles(): array {
    return $this->strings('content.bundles');
  }

  /**
   * Node bundles writable through MCP; always a subset of readable bundles.
   *
   * @return list<string>
   *   Bundle machine names.
   */
  public function writableNodeBundles(): array {
    return array_values(array_intersect($this->strings('content.writable_bundles'), $this->nodeBundles()));
  }

  /**
   * Vocabularies exposed to term reads.
   *
   * @return list<string>
   *   Vocabulary machine names.
   */
  public function vocabularies(): array {
    return $this->strings('taxonomy.vocabularies');
  }

  /**
   * Vocabularies writable through MCP; always a subset of readable ones.
   *
   * @return list<string>
   *   Vocabulary machine names.
   */
  public function writableVocabularies(): array {
    return array_values(array_intersect($this->strings('taxonomy.writable_vocabularies'), $this->vocabularies()));
  }

  /**
   * Whether permanent taxonomy term deletion is offered.
   */
  public function termDeleteEnabled(): bool {
    return $this->get('taxonomy.allow_term_delete') === TRUE;
  }

  /**
   * Approved field targets ("node:bundle" or "taxonomy_term:vocabulary").
   *
   * @return list<string>
   *   Target identifiers.
   */
  public function fieldTargets(): array {
    return $this->strings('configuration.field_targets');
  }

  /**
   * Approved field plugin types, limited to those the module supports.
   *
   * @return list<string>
   *   Field type plugin IDs.
   */
  public function fieldTypes(): array {
    return array_values(array_intersect($this->strings('configuration.field_types'), array_keys(SupportedFieldTypes::all())));
  }

  /**
   * Approved Drush diagnostic command IDs.
   *
   * @return list<string>
   *   Command IDs.
   */
  public function drushCommands(): array {
    return $this->strings('drush.commands');
  }

  /**
   * Configuration names the config:get diagnostic may read.
   *
   * @return list<string>
   *   Configuration object names.
   */
  public function drushConfigNames(): array {
    return $this->strings('drush.config_names');
  }

  /**
   * Approved database surface IDs.
   *
   * @return list<string>
   *   Surface IDs.
   */
  public function databaseSurfaces(): array {
    return $this->strings('database.surfaces');
  }

  /**
   * Reads one raw value.
   */
  private function get(string $key): mixed {
    return $this->configFactory->get(self::NAME)->get($key);
  }

  /**
   * Reads a list of non-empty strings.
   *
   * @return list<string>
   *   The trimmed, non-empty strings.
   */
  private function strings(string $key): array {
    $value = $this->get($key);
    if (!\is_array($value)) {
      return [];
    }
    return array_values(array_filter(
      array_map(static fn (mixed $item): string => \is_string($item) ? trim($item) : '', $value),
      static fn (string $item): bool => $item !== '',
    ));
  }

  /**
   * Reads a positive integer with a default and a floor.
   */
  private function int(string $key, int $default, int $minimum): int {
    $value = $this->get($key);
    return max($minimum, is_numeric($value) && (int) $value > 0 ? (int) $value : $default);
  }

}
