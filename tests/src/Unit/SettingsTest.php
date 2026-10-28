<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Settings;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use PHPUnit\Framework\TestCase;

/**
 * Tests the typed settings reader.
 */
final class SettingsTest extends TestCase {

  /**
   * Tests that missing configuration resolves to the most restrictive values.
   */
  public function testMissingConfigurationFailsClosed(): void {
    $settings = new Settings(new ConfigFactoryStub([]));

    $this->assertTrue($settings->requiresResourceBinding());
    $this->assertSame([], $settings->allowedHosts());
    $this->assertSame([], $settings->nodeBundles());
    $this->assertFalse($settings->termDeleteEnabled());
    foreach (ToolFamily::cases() as $family) {
      $this->assertFalse($settings->familyEnabled($family));
    }
    $this->assertSame(20, $settings->pageSize());
    $this->assertSame(3600, $settings->sessionTtl());
  }

  /**
   * Tests host derivation, subset rules, and clamping.
   */
  public function testDerivedValues(): void {
    $settings = new Settings(new ConfigFactoryStub([
      Settings::NAME => [
        'resource_uri' => 'https://MCP.example/mcp',
        'resource_binding' => 'audit',
        'allowed_hosts' => ['mcp.example', 'internal.example', ''],
        'limits' => ['page_size' => 5, 'session_ttl' => 1],
        'families' => ['content' => TRUE, 'drush' => 'yes'],
        'content' => ['bundles' => ['article'], 'writable_bundles' => ['article', 'page']],
        'taxonomy' => ['vocabularies' => [], 'writable_vocabularies' => ['tags']],
        'configuration' => ['field_types' => ['string', 'not_a_supported_type']],
      ],
    ]));

    $this->assertFalse($settings->requiresResourceBinding());
    $this->assertSame(['mcp.example', 'internal.example'], $settings->allowedHosts());
    $this->assertSame(5, $settings->clampPageSize(50));
    $this->assertSame(3, $settings->clampPageSize(3));
    $this->assertSame(5, $settings->clampPageSize(NULL));
    $this->assertSame(60, $settings->sessionTtl());
    $this->assertTrue($settings->familyEnabled(ToolFamily::Content));
    $this->assertFalse($settings->familyEnabled(ToolFamily::Drush));
    $this->assertSame(['article'], $settings->writableNodeBundles());
    $this->assertSame([], $settings->writableVocabularies());
    $this->assertNotContains('not_a_supported_type', $settings->fieldTypes());
  }

}
