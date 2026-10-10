# Add generic, bounded configuration-creation tools to Drupal MCP

Status: Accepted — generic direction and initial target/type scope approved by the user.

## Context

Drupal MCP currently exposes editorial writes for nodes and taxonomy terms, but cannot create content types, vocabularies, or fields. Users need to perform these configuration operations through MCP rather than through an implicit local Drush fallback.

The motivating example is an Article content type with a Body field and a taxonomy-reference field. That example must not define the API: tools must compose reusable configuration operations without hard-coded bundle names or a fixed Title/Body field set.

The existing project plans excluded configuration writes from earlier phases. This decision explicitly expands that boundary; it does not authorize arbitrary configuration editing.

## Decision

Add three create-only tools:

1. `drupal_content_type_create`: accept a machine name, label, optional description, supported type settings, and idempotency key. Create the node type using Drupal config-entity APIs. Drupal provides its native Title base field; do not automatically attach Body or other configurable fields.
2. `drupal_vocabulary_create`: accept a machine name, label, optional description, supported vocabulary settings, and idempotency key. Create the vocabulary without terms or automatically granted permissions.
3. `drupal_field_create`: accept a target entity type and bundle, field machine name, label, optional description, supported field type, cardinality, required flag, explicitly supported storage/instance settings, and idempotency key. Create field storage and its bundle instance through Drupal entity APIs. Entity references are a supported field configuration, not an Article-specific tool.

Schemas must reject unknown properties. Generic means reusable across supported targets and field types, not a pass-through for arbitrary Drupal configuration.

### Initial support

- Field targets: `node` and `taxonomy_term`; require an existing bundle and explicit administrator-configured target enablement.
- Field types: `string`, `string_long`, `boolean`, `integer`, `decimal`, `float`, `text`, `text_long`, `text_with_summary`, and `entity_reference`. Expose only installed plugins approved in a reviewed field-type/settings registry.
- Entity references initially support nodes and taxonomy terms with explicit existing target bundles, the default selection handler, and automatic entity creation disabled. No arbitrary selection plugins.
- Cardinality: one of 1, 2, 3, 4, 5, 10, 20, 50, or Drupal's unlimited sentinel (-1); validate field-name limits and settings using installed core APIs.
- Display behavior: provide server-selected default widgets/formatters for supported fields, subject to native display-administration permissions. Do not accept arbitrary display plugin identifiers or overwrite existing display choices.
- Existing storage: reject an existing field storage name in the first version, even if compatible, rather than silently sharing or changing storage across bundles. Storage reuse can be added through a separately reviewed contract.

These are explicitly approved initial bounds, not limitations inferred from existing content read/write support.

### Authorization and exposure

- Add a separate configuration-mutation family, disabled by default on fresh installs and upgrades.
- Require the configured write scope and `access mcp write`, consistent with other mutations.
- Require native permissions: `administer content types` for node types; `administer taxonomy` for vocabularies; and the target-specific `administer {entity_type} fields` for fields. Require applicable display permissions when configuring displays.
- Respect native access-handler and access-hook denials, using the authenticated token-effective caller, not an unrestricted underlying user.
- Use administrator-controlled restrictions for supported field targets/types. Editorial writable-bundle allowlists do not confer configuration-administration authority.
- Do not automatically modify content/term exposure or writable allowlists when creating configuration.
- Recheck enablement and target authorization before replay and before persistence.

### Persistence and safety

- Reuse `MutationExecutor` and existing idempotency infrastructure.
- Require bounded, validated input; never silently derive machine names from labels.
- Same key and payload replays the result. Changed payload under the same key fails.
- A different request targeting existing configuration fails without overwrite or adoption.
- Serialize name/storage collisions using target-level locks independently of idempotency locks.
- Preflight dependencies, permissions, plugin settings, and collisions before saving.
- Multi-entity operations must track only entities created by the request and attempt compensating cleanup on failure. Do not delete pre-existing or concurrently modified configuration. Report a bounded partial-failure result if recovery cannot safely finish; never claim atomicity across config saves and hooks.
- Return small structural projections and created config names, not unrestricted configuration exports.
- Audit operation, caller/client identity, target IDs, outcome, and replay/recovery status without logging full arguments or supplied field content.

## Alternatives

### Keep configuration outside MCP

Retains the current smaller attack surface, but cannot fulfill configuration requests through MCP. Rejected as the desired workflow.

### Add an Article-specific setup tool

Simple for one request, but couples server capabilities to a particular content model and fixed fields. Rejected: Article is a composition example, not the API contract.

### Expose arbitrary configuration writes or shell execution

Broadly flexible but bypasses narrow validation and authorization boundaries. Rejected.

## Consequences

Configuration administration becomes available to explicitly authorized MCP clients. The module must maintain a reviewed registry of supported field types/settings, stronger configuration permission coverage, and partial-failure recovery tests.

Enabling editorial writes alone will not expose these tools. Operators must separately enable configuration writes and ensure OAuth permission ceilings include the necessary native administration permissions.

## Non-goals

- Updating or deleting configuration.
- Arbitrary config arrays, PHP, SQL, or shell writes.
- Automatically enabling new structures for editorial MCP access.
- Assigning roles or permissions as a side effect of creation.
- Automatically creating nodes or terms alongside configuration.
- Supporting every installed field plugin without review.

## Implementation plan

1. Add `src/Tool/ConfigurationMutationToolProvider.php` with explicit strict tool schemas, write capability, native permission gates, and shared executor delegation.
2. Add `src/Mutation/ConfigurationMutator.php`, `ConfigurationMutationException.php`, and a supported field-type/settings registry. Keep persistence, native access, collision locks, validation, projection, audit, and cleanup behind that boundary.
3. Register services in `drupal_mcp.services.yml`; add a configuration mutation case/key in `src/Mcp/ToolFamily.php`. Reuse the current scope/capability mapping.
4. Update `config/install/drupal_mcp.settings.yml`, `config/schema/drupal_mcp.schema.yml`, and `src/Form/SettingsForm.php` build/submit handling with default-off enablement and explicit field target/type restrictions. Add an update hook in `drupal_mcp.install` preserving existing settings and leaving the new family disabled.
5. Add unit tests for schemas, field registry, and permission-filtered discovery; extend `tests/src/Unit/ToolRegistryTest.php`.
6. Add kernel tests for real config creation, native access, invalid settings, collisions, replay, locks, and partial-failure cleanup. Add functional settings and real-OAuth discovery/direct-call coverage.
7. Document enablement and contracts in `README.md`, `docs/operations.md`, and `CONTEXT.md`; cross-reference this decision from existing project plans as appropriate.
8. After tested deployment and explicit operator enablement, refresh MCP discovery and use only the new MCP tools for the Article setup: create type, create Body field, create vocabulary, create reference field. Verify through existing inspection tools. Do not substitute Drush for these requested operations.

## Verification

- [ ] New configuration tools are absent by default, including after upgrade.
- [ ] Missing scope, MCP permission, native permission, or target enablement prevents discovery and execution.
- [ ] Tool contracts contain no fixed Article machine names or mandatory Body field.
- [ ] Supported settings are validated and unknown/unsupported properties rejected.
- [ ] Drupal supplies Title; configurable fields are separate operations.
- [ ] Existing configuration and shared storage are not overwritten.
- [ ] Idempotency replay/conflict and name-level concurrency behave as specified.
- [ ] Partial failures are cleaned up safely or reported honestly.
- [ ] New structures are not automatically exposed for editorial reads/writes.
- [ ] Default form/view behavior respects native permissions and preserves existing choices.
- [ ] Unit, kernel, functional, coding-standard, and architecture checks pass; record any unavailable checks explicitly.
- [ ] Article setup is performed and verified through MCP after deployment.
