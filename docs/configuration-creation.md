# Configuration creation through MCP

The `configuration` mutation family is separate from editorial writes and is
**disabled by default**, including on upgrade. There is no arbitrary config-write
tool. Existing configuration is never overwritten or adopted.

## Enablement

Apply update hook `11002` through the site's normal deployment workflow and
rebuild the Drupal service container. In the MCP settings form, explicitly enable
configuration creation, select approved field types, and enter approved field
targets as `node:bundle` or `taxonomy_term:vocabulary`, one per line. Targets may
be approved before their bundles are created, but field creation requires the
bundle to exist.

The OAuth token must have the configured write scope, `access mcp write`, and
the relevant native permissions in both the user's permissions and its OAuth
permission ceiling:

- Content types: `administer content types`.
- Vocabularies: `administer taxonomy`.
- Fields: `administer node fields` or `administer taxonomy_term fields`, plus
  the corresponding `administer … form display` and `administer … display`.

Field UI must be enabled for these native field/display administration
permissions. Required field/widget/formatter plugins must be enabled. After
changing an OAuth ceiling or grant, reauthorize as appropriate and refresh MCP
discovery. Write scope alone is not configuration-administration authority.

## Tools

All tools require `label` and `idempotency_key`; an optional `description` is
bounded to 4096 characters. Machine names must start with a lowercase letter
and contain only lowercase letters, digits, and underscores, at most 32 chars.

### `drupal_content_type_create`

Required: `machine_name`, `label`, `idempotency_key`.
Optional: `description`, `new_revision` (default true).

Creates the type with Drupal's native Title field. It does **not** attach Body or
any other configurable field.

### `drupal_vocabulary_create`

Required: `machine_name`, `label`, `idempotency_key`.
Optional: `description`.

Creates a vocabulary without terms or automatically granted permissions.

### `drupal_field_create`

Required: `entity_type`, `bundle`, `field_name`, `label`, `definition`,
`idempotency_key`. Optional: `description`, `required` (default false),
`cardinality` (default 1).

- `entity_type`: `node` or `taxonomy_term`.
- `field_name`: `field_` prefix followed by a lowercase letter and lowercase
  letters/digits/underscores, at most 32 total characters.
- `cardinality`: -1 (unlimited), 1, 2, 3, 4, 5, 10, 20, or 50.
- `definition.type`: `string`, `string_long`, `boolean`, `integer`, `decimal`,
  `float`, `text`, `text_long`, `text_with_summary`, or `entity_reference`.
- String/text allow `max_length` (1–4096).
- Decimal allows `precision` (10–32) and `scale` (0–10).
- Entity references require `target_type` (`node` or `taxonomy_term`) and
  `target_bundles` (1–50 unique existing bundle machine names). Selection uses
  the default handler; automatic entity creation is disabled.

Other definition properties are rejected. The target bundle, field type, and
required plugins must be enabled. Existing storage under the same entity type
and field name is rejected, even if compatible. Shared-storage reuse is not
supported in this version.

Creates default form/view components with server-selected plugins. Field writes
are serialized by storage identity and bundle-display identity. Creation does
not alter editorial read/write allowlists.

## Replay and failure handling

The same key and payload replay the original result; changed payloads under the
same key fail. A new key aimed at existing configuration fails. Current target
policy is checked before replay.

Successful results include `machine_name`, `created_config`, and
`idempotency_replayed`. Multi-save failures attempt conservative cleanup of only
configuration created by the request and restore affected displays only when
safe. An unresolved cleanup produces a partial-failure error: inspect the site's
configuration before retrying. Configuration saves and hooks are not globally
atomic.

## Composition example

An Article setup uses four independent calls: create the `article` content type,
create a `field_body` field with `definition.type: text_with_summary`, create the
`article` vocabulary, and create `field_article` with an entity-reference
definition targeting taxonomy terms in that vocabulary. The native Title field
already exists. No Article-specific tool or preset is involved.
