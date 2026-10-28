<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Mutation\MutationException;
use Drupal\drupal_mcp\Mutation\SupportedFieldTypes;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
    SupportedFieldTypes::settings(['type' => 'file']);
  }

  /**
   * Tests image defaults: public storage, core extensions, required alt text.
   */
  public function testImageDefaults(): void {
    $this->assertSame(
      [
        ['uri_scheme' => 'public'],
        ['file_extensions' => 'png gif jpg jpeg webp', 'alt_field' => TRUE, 'alt_field_required' => TRUE],
      ],
      SupportedFieldTypes::settings(['type' => 'image']),
    );
  }

  /**
   * Tests the bounded image settings a caller may choose.
   */
  public function testImageBoundedSettings(): void {
    $this->assertSame(
      [
        ['uri_scheme' => 'private'],
        ['file_extensions' => 'png webp', 'alt_field' => TRUE, 'alt_field_required' => FALSE],
      ],
      SupportedFieldTypes::settings([
        'type' => 'image',
        'uri_scheme' => 'private',
        'file_extensions' => ['png', 'webp'],
        'alt_field_required' => FALSE,
      ]),
    );
  }

  /**
   * Tests unsafe or unreviewed image settings are rejected.
   */
  #[DataProvider('unsafeImageDefinitions')]
  public function testUnsafeImageSettingsRejected(array $definition): void {
    $this->expectException(MutationException::class);
    SupportedFieldTypes::settings(['type' => 'image'] + $definition);
  }

  /**
   * Image definitions outside the reviewed contract.
   */
  public static function unsafeImageDefinitions(): array {
    return [
      'scriptable svg' => [['file_extensions' => ['png', 'svg']]],
      'executable extension' => [['file_extensions' => ['php']]],
      'extension string' => [['file_extensions' => 'png php']],
      'empty extensions' => [['file_extensions' => []]],
      'duplicate extensions' => [['file_extensions' => ['png', 'png']]],
      'caller upload directory' => [['file_directory' => '../../tmp']],
      'unreviewed size limit' => [['max_filesize' => '1 GB']],
      'temporary scheme' => [['uri_scheme' => 'temporary']],
      'non-boolean alt flag' => [['alt_field_required' => 'yes']],
    ];
  }

  /**
   * Tests numeric setting bounds.
   */
  public function testInvalidNumericSettingRejected(): void {
    $this->expectException(MutationException::class);
    SupportedFieldTypes::settings(['type' => 'decimal', 'scale' => 11]);
  }

}
