<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\drupal_mcp\Mcp\ToolSchema;

/**
 * Reviewed field plugins, settings, and default display components.
 */
final class SupportedFieldTypes {

  /**
   * Raster image extensions an image field may accept.
   *
   * SVG is excluded on purpose: it can carry script and is not a raster
   * format the image toolkit processes.
   */
  public const IMAGE_EXTENSIONS = ['png', 'gif', 'jpg', 'jpeg', 'webp'];

  /**
   * Storage schemes an image field may use.
   *
   * Availability is checked at creation time against the registered stream
   * wrappers: "private" exists only when a private file path is configured.
   */
  public const IMAGE_SCHEMES = ['public', 'private'];

  /**
   * Returns the supported widget and formatter for each field type.
   */
  public static function all(): array {
    return [
      'string' => ['string_textfield', 'string'],
      'string_long' => ['string_textarea', 'basic_string'],
      'boolean' => ['boolean_checkbox', 'boolean'],
      'integer' => ['number', 'number_integer'],
      'decimal' => ['number', 'number_decimal'],
      'float' => ['number', 'number_decimal'],
      'text' => ['text_textfield', 'text_default'],
      'text_long' => ['text_textarea', 'text_default'],
      'text_with_summary' => ['text_textarea_with_summary', 'text_default'],
      'entity_reference' => ['entity_reference_autocomplete', 'entity_reference_label'],
      'image' => ['image_image', 'image'],
    ];
  }

  /**
   * Returns a discriminated, strict schema for field-specific settings.
   */
  public static function schema(): array {
    $variants = [];
    foreach (array_keys(self::all()) as $type) {
      $properties = ['type' => ['type' => 'string', 'enum' => [$type]]];
      $required = ['type'];
      if ($type === 'entity_reference') {
        $properties += [
          'target_type' => ['type' => 'string', 'enum' => ['node', 'taxonomy_term']],
          'target_bundles' => [
            'type' => 'array',
            'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,31}$'],
            'minItems' => 1,
            'maxItems' => 50,
            'uniqueItems' => TRUE,
          ],
        ];
        $required = array_merge($required, ['target_type', 'target_bundles']);
      }
      elseif ($type === 'string' || $type === 'text') {
        $properties['max_length'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 4096];
      }
      elseif ($type === 'decimal') {
        $properties['precision'] = ['type' => 'integer', 'minimum' => 10, 'maximum' => 32];
        $properties['scale'] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10];
      }
      elseif ($type === 'image') {
        $properties['uri_scheme'] = [
          'type' => 'string',
          'enum' => self::IMAGE_SCHEMES,
          'description' => 'File storage; "private" requires a configured private file path. Defaults to "public".',
        ];
        $properties['file_extensions'] = [
          'type' => 'array',
          'items' => ['type' => 'string', 'enum' => self::IMAGE_EXTENSIONS],
          'minItems' => 1,
          'uniqueItems' => TRUE,
          'description' => 'Allowed upload extensions. Defaults to all listed raster formats.',
        ];
        $properties['alt_field_required'] = [
          'type' => 'boolean',
          'description' => 'Whether alternative text is required. Defaults to true.',
        ];
      }
      $variants[] = ToolSchema::object($properties, $required);
    }
    return ['oneOf' => $variants];
  }

  /**
   * Validates settings and splits storage settings from instance settings.
   */
  public static function settings(array $definition): array {
    $type = $definition['type'] ?? '';
    if (!isset(self::all()[$type])) {
      throw new MutationException('invalid_field_type', 'Unsupported field type.');
    }
    $allowed = match ($type) {
      'entity_reference' => ['type', 'target_type', 'target_bundles'],
      'string', 'text' => ['type', 'max_length'],
      'decimal' => ['type', 'precision', 'scale'],
      'image' => ['type', 'uri_scheme', 'file_extensions', 'alt_field_required'],
      default => ['type'],
    };
    if (array_diff(array_keys($definition), $allowed)) {
      throw new MutationException('invalid_settings', 'Unsupported field settings.');
    }
    $storage = [];
    $instance = [];
    foreach (['max_length' => [1, 4096], 'precision' => [10, 32], 'scale' => [0, 10]] as $key => [$min, $max]) {
      if (array_key_exists($key, $definition)) {
        if (!is_int($definition[$key]) || $definition[$key] < $min || $definition[$key] > $max) {
          throw new MutationException('invalid_settings', 'Invalid numeric field setting.');
        }
        $storage[$key] = $definition[$key];
      }
    }
    if ($type === 'entity_reference') {
      if (!in_array($definition['target_type'] ?? '', ['node', 'taxonomy_term'], TRUE) || empty($definition['target_bundles']) || !is_array($definition['target_bundles'])) {
        throw new MutationException('invalid_reference', 'References require a supported target type and existing target bundles.');
      }
      $storage = ['target_type' => $definition['target_type']];
      $bundles = $definition['target_bundles'];
      if (count($bundles) > 50 || count(array_unique($bundles)) !== count($bundles) || !array_is_list($bundles)) {
        throw new MutationException('invalid_reference', 'Reference bundles must be a unique list of at most 50 entries.');
      }
      foreach ($bundles as $bundle) {
        if (!is_string($bundle) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $bundle)) {
          throw new MutationException('invalid_reference', 'Invalid reference target bundle.');
        }
      }
      $instance = [
        'handler' => 'default:' . $definition['target_type'],
        'handler_settings' => [
          'target_bundles' => array_combine($bundles, $bundles),
          'auto_create' => FALSE,
        ],
      ];
    }
    if ($type === 'image') {
      [$storage, $instance] = self::imageSettings($definition);
    }
    return [$storage, $instance];
  }

  /**
   * Validates image settings; the upload directory and limits stay default.
   *
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}
   *   Storage settings and instance settings.
   */
  private static function imageSettings(array $definition): array {
    $scheme = $definition['uri_scheme'] ?? 'public';
    if (!\in_array($scheme, self::IMAGE_SCHEMES, TRUE)) {
      throw new MutationException('invalid_settings', 'Unsupported image storage scheme.');
    }
    $extensions = $definition['file_extensions'] ?? self::IMAGE_EXTENSIONS;
    if (!is_array($extensions) || $extensions === [] || !array_is_list($extensions)
      || count(array_unique($extensions)) !== count($extensions)
      || array_diff($extensions, self::IMAGE_EXTENSIONS) !== []) {
      throw new MutationException('invalid_settings', 'Image extensions must be a unique list of supported raster formats.');
    }
    $altRequired = $definition['alt_field_required'] ?? TRUE;
    if (!is_bool($altRequired)) {
      throw new MutationException('invalid_settings', 'The alternative-text requirement must be a boolean.');
    }
    return [
      ['uri_scheme' => $scheme],
      ['file_extensions' => implode(' ', $extensions), 'alt_field' => TRUE, 'alt_field_required' => $altRequired],
    ];
  }

}
