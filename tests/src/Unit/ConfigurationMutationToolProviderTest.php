<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Tool\ConfigurationMutationToolProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests generic, strict configuration tool contracts without invoking handlers.
 */
final class ConfigurationMutationToolProviderTest extends TestCase {

  /**
   * Tests strict schemas with no Article-specific preset.
   */
  public function testGenericContracts(): void {
    $provider = (new \ReflectionClass(ConfigurationMutationToolProvider::class))->newInstanceWithoutConstructor();
    $tools = $provider->tools();
    $this->assertSame(['drupal_content_type_create', 'drupal_vocabulary_create', 'drupal_field_create'], array_column($tools, 'name'));
    foreach ($tools as $tool) {
      $this->assertSame(OperationCapability::Write, $tool->capability);
      $this->assertSame(ToolFamily::ConfigurationMutation, $tool->family);
      $this->assertFalse($tool->inputSchema['additionalProperties']);
      $this->assertContains('idempotency_key', $tool->inputSchema['required']);
      $this->assertStringNotContainsString('article', json_encode($tool->inputSchema));
    }
    $this->assertArrayNotHasKey('body', (array) $tools[0]->inputSchema['properties']);
    $this->assertSame('mutation_families.configuration', ToolFamily::ConfigurationMutation->settingsKey());
  }

}
