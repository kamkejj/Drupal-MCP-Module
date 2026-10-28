<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

/**
 * The tool families this module can expose.
 *
 * A family is the unit of exposure: each case is toggled by
 * "families.<value>" in drupal_mcp.settings, read through
 * \Drupal\drupal_mcp\Settings::familyEnabled().
 */
enum ToolFamily: string {

  case Site = 'site';
  case EntitySchema = 'entity_schema';
  case Content = 'content';
  case Taxonomy = 'taxonomy';
  case Blocks = 'blocks';
  case Menus = 'menus';
  case Aliases = 'aliases';
  case Files = 'files';
  case Users = 'users';
  case Drush = 'drush';
  case Database = 'database';
  case NodeMutation = 'node_mutation';
  case TaxonomyMutation = 'taxonomy_mutation';
  case ConfigurationMutation = 'configuration_mutation';

  /**
   * A short administrative label.
   */
  public function label(): string {
    return match ($this) {
      self::Site => 'Site and caller identity',
      self::EntitySchema => 'Entity type and schema discovery',
      self::Content => 'Content reads',
      self::Taxonomy => 'Taxonomy reads',
      self::Blocks => 'Reusable block content',
      self::Menus => 'Menus',
      self::Aliases => 'Path aliases',
      self::Files => 'File metadata',
      self::Users => 'User inspection',
      self::Drush => 'Drush diagnostics (privileged)',
      self::Database => 'Database inspection (privileged)',
      self::NodeMutation => 'Content create and update',
      self::TaxonomyMutation => 'Taxonomy term create and update',
      self::ConfigurationMutation => 'Configuration creation (content types, vocabularies, fields)',
    };
  }

}
