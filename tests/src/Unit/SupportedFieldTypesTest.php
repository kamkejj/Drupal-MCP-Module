<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Mutation\MutationException;
use Drupal\drupal_mcp\Mutation\SupportedFieldTypes;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the finite settings registry independently of Drupal storage.
 */
#[Group('drupal_mcp')]
final class SupportedFieldTypesTest extends UnitTestCase {

  /**
   * Tests storage and instance settings separation.
   */
  public function testScalarAndReferenceSettings(): void {
    $this->assertSame(
      [['max_length' => 128], []],
      SupportedFieldTypes::settings(['type' => 'string', 'max_length' => 128]),
    );
    [$storage, $instance] = SupportedFieldTypes::settings([
      'type' => 'entity_reference',
      'target_type' => 'taxonomy_term',
      'target_bundles' => ['topics'],
    ]);
    $this->assertSame(['target_type' => 'taxonomy_term'], $storage);
    $this->assertSame(['topics' => 'topics'], $instance['handler_settings']['target_bundles']);
    $this->assertFalse($instance['handler_settings']['auto_create']);
    $this->assertCount(count(SupportedFieldTypes::all()), SupportedFieldTypes::schema()['oneOf']);
  }

  /**
   * Tests unknown setting rejection.
   */
  public function testUnsupportedSettingsRejected(): void {
    $this->expectException(MutationException::class);
    SupportedFieldTypes::settings(['type' => 'string', 'arbitrary_config' => TRUE]);
  }

  /**
   * Tests unreviewed plugin rejection.
   */
  public function testUnsupportedPluginRejected(): void {
    $this->expectException(MutationException::class);
    SupportedFieldTypes::settings(['type' => 'image']);
  }

  /**
   * Tests numeric setting bounds.
   */
  public function testInvalidNumericSettingRejected(): void {
    $this->expectException(MutationException::class);
    SupportedFieldTypes::settings(['type' => 'decimal', 'scale' => 11]);
  }

}
