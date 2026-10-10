<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\drupal_mcp\Mcp\ToolSchema;

/**
 * Reviewed field plugins, settings, and default display components.
 */
final class SupportedFieldTypes {

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
    return [$storage, $instance];
  }

}
