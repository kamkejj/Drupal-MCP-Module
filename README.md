# Drupal MCP

[![CI](https://github.com/kamkejj/Drupal-MCP/actions/workflows/ci.yml/badge.svg)](https://github.com/kamkejj/Drupal-MCP/actions/workflows/ci.yml)

This module exposes Drupal through a bounded Model Context Protocol (MCP)
server at `/mcp` on your site's HTTPS origin (for example,
`https://drupalmcp.ddev.site:30001/mcp` in the local DDEV environment). It lets authenticated MCP clients
inspect selected site data and structure, run explicitly approved diagnostics,
and perform narrowly allowlisted taxonomy and content mutations. Every request requires a
Simple OAuth bearer token associated with an active Drupal user; OAuth scopes
limit the token while Drupal permissions and entity access remain authoritative.

## Capabilities

The module provides these MCP tool families:

- Site identity and current-user information.
- Entity-type, bundle, content-type, field, and role schema inspection.
- Paginated reads of allowlisted published content and taxonomy terms, with
  Drupal entity and field access checks applied to every result.
- Bounded inspection of blocks, menus, URL aliases, files, and user profiles.
- Disabled-by-default Drush diagnostics limited to approved, typed,
  non-destructive commands.
- Disabled-by-default database diagnostics against curated views through a
  separately provisioned read-only database principal.
- Disabled-by-default taxonomy term creation, update, and deletion for
  explicitly writable vocabularies. Mutations enforce native Drupal access,
  revision preconditions, field validation, and idempotency where applicable.
- Disabled-by-default node creation and update for explicitly writable node
  bundles (a strict subset of the bundles exposed for reads), with the same
  native access, revision precondition, field validation, and idempotency
  guarantees as taxonomy mutations.

- Disabled-by-default generic configuration creation: content types,
  vocabularies, and reviewed configurable fields on nodes and taxonomy terms.
  Native configuration administration permissions and explicit field target/type
  approvals are required. New structures are not automatically exposed for
  content reads or editorial writes.

See [Configuration creation](docs/configuration-creation.md) for tool contracts
and enablement, and the [accepted decision](docs/decisions/generic-mcp-configuration-creation.md).

The server uses Streamable HTTP, OAuth authorization-code flow with PKCE,
RFC 8707 resource binding, paginated tool discovery, signed cursors, bounded
request/output sizes, rate limiting, and server-side MCP sessions. Tool families
and exposed bundles, vocabularies, commands, and database surfaces are all
configured explicitly and fail closed.

## OAuth capabilities

`mcp:read` exposes only tools for which the authenticated user also has `access
mcp read` and every native Drupal permission required by the tool. `mcp:write`
exposes only enabled mutation tools when the user also has `access mcp write`
and the required native entity, field, and text-format access. Scope possession
never grants a Drupal permission or bypasses entity access.

The scopes are independent capability ceilings: `mcp:write` does not imply
`mcp:read`, and `mcp:read` does not permit mutations. Request both scopes when a
client needs both catalogues. Taxonomy mutations remain unavailable until the
`taxonomy` mutation family and at least one writable vocabulary are explicitly
enabled; destructive deletion has a separate opt-in switch.

Run the commands below from the Drupal project root.

## Composer patches

The current release requires the security and protocol patches listed in
[`patches.json`](patches.json). A root Drupal project must use
`cweagans/composer-patches` and point its `extra.patches-file` setting at the
module's patch manifest. The CI workflow installs the module into a clean
Drupal project and fails if any required patch no longer applies.

## OAuth setup in Drupal administration

Complete these steps before authorizing an MCP client. Enabling the module alone
does not provision signing keys, OAuth scopes, or a registered client. Use an
administrator account for setup; use a dedicated non-administrator account for
normal MCP access.

### 1. Confirm the site and canonical endpoint

Install the required Composer patches above, enable `drupal_mcp` and its
Simple OAuth dependencies, and ensure the site is reachable over trusted HTTPS.
Apply outstanding database updates after upgrading the module.

At **Configuration > Web services > Drupal MCP settings**
(`/admin/config/services/drupal-mcp`), set **Canonical MCP resource URI** to the
exact external endpoint, including any nonstandard port:

```text
https://drupalmcp.ddev.site:30001/mcp
```

Use your actual hostname and port everywhere below. Do not omit `:30001` when
that is how the site is reached. Drupal trusted-host configuration must permit
the hostname. Leave resource binding set to **Require**; changing it to Audit
is not a fix for a mismatched URL.

### 2. Configure OAuth signing keys

Open **Configuration > People > Simple OAuth**
(`/admin/config/people/simple_oauth`). Configure **Public Key** and **Private
Key** with absolute paths readable by Drupal's PHP runtime, outside the web root.
For local DDEV, a suitable directory is:

```text
/var/www/html/secrets/simple_oauth
```

Create the directory first with restrictive permissions. Click **Generate keys**,
enter that directory under **Directory for the keys**, and click **Generate**.
Save the settings with the resulting `public.key` and `private.key` paths.
**Generation replaces existing keys in that directory**: use a new directory for
initial setup, and plan rotation separately for an existing installation.
Never commit keys or put them under `web/`; verify your project's ignore rules.

### 3. Create scope ceilings and authorize the Drupal user

At `/admin/people/roles`, create ordinary roles (not administrator roles) for
**MCP read scope ceiling** and, if needed, **MCP write scope ceiling**. Configure
their permissions at `/admin/people/permissions`:

- Read ceiling: `access mcp read`, plus only the native read permissions required
  by the intended tools, such as `access content`. Structural content-type tools
  additionally require `administer content types`.
- Write ceiling: `access mcp write`, plus only the native mutation permissions
  required, such as `create article content`, appropriate edit permissions, and
  access to the intended text formats. Configuration-creation tools require
  their native configuration-administration permissions.

Open `/admin/config/people/simple_oauth`, select the dynamic scope provider if
necessary, then open `/admin/config/people/simple_oauth/oauth2_scope/dynamic`.
Use **Add** to create a scope named **`mcp:read`** and, if writes are needed,
**`mcp:write`**. For each scope:

- Enable **Authorization Code** and **Refresh Token** under **Grant types**.
- Select **Role** under **Granularity** and the corresponding ceiling role.
- Save the scope. Configuration IDs such as `mcp_read` and `mcp_write` are
  distinct from the OAuth names `mcp:read` and `mcp:write` requested by clients.

Give the actual MCP user's normal role the matching MCP capability permission
and native permissions, plus **`grant simple_oauth codes`** to complete browser
consent. Ceiling roles need not be assigned to users: token permissions are
bounded by both the user's permissions and the scopes. Do not use UID 1 or an
administrator-role account to validate least-privilege scope isolation.

### 4. Register the Pi client

Open **Consumers** at `/admin/config/services/consumer` and add a consumer at
`/admin/config/services/consumer/add`:

- **Label**: `Pi Drupal MCP (local)`.
- **Client ID**: `drupal-mcp` (not the consumer UUID or numeric entity ID).
- **Grant types**: Authorization Code and Refresh Token.
- **Is Confidential?**: unchecked; this is a public local client, without a secret.
- Under **Authorization Code settings**, enable **Use PKCE?**.
- **Redirect URIs**: exactly `http://127.0.0.1:19876/callback`.
- **Scopes**: select the scopes this client is allowed to request.
- Leave **Automatic authorization** disabled so the user explicitly consents.

Save. The callback's scheme, host, port, and path must match exactly;
`localhost` and `127.0.0.1` are not interchangeable. Other MCP clients need their
own registration and exact callback URI.

### 5. Enable only the intended MCP surfaces

Return to `/admin/config/services/drupal-mcp`. Enable the intended tool families
and select readable content types/vocabularies. For mutations, separately enable
the relevant mutation family and writable targets; writable targets must also
be readable. Empty allowlists enable no targets. Configuration creation has
separate field-target/type approvals; diagnostics have additional prerequisites
below. Save and rebuild Drupal caches:

```shell
ddev drush cr
```

OAuth scopes and client registration do not enable these surfaces or bypass
native Drupal permissions.

## Authorize Pi with `/mcp-auth drupalmcp`

With `pi-mcp-adapter` installed and loaded in Pi, add the following server entry
to `~/.pi/agent/mcp-adapter.json`, merging it with any existing servers:

```json
{
  "mcpServers": {
    "drupalmcp": {
      "url": "https://drupalmcp.ddev.site:30001/mcp",
      "caFile": "/absolute/path/to/mkcert/rootCA.pem",
      "auth": "oauth",
      "oauth": {
        "grantType": "authorization_code",
        "clientId": "drupal-mcp",
        "redirectUri": "http://127.0.0.1:19876/callback",
        "scope": "mcp:read mcp:write"
      }
    }
  }
}
```

For read-only clients, register and request only `mcp:read`. For local DDEV,
replace `caFile` with the actual mkcert CA file (`mkcert -CAROOT` identifies its
directory). Ensure the browser and the runtime performing OAuth requests also
trust that CA. Omit `caFile` for publicly trusted certificates; never disable TLS
verification. Local DDEV hostnames are not accessible to cloud-hosted clients.

After editing the adapter configuration, run these commands **one at a time in
Pi**, not in the shell:

```text
/reload
```

```text
/mcp-auth drupalmcp
```

The adapter caches server definitions, so reconnecting without `/reload` can
still use an old URL. Complete login and consent in the newly opened browser
tab. Port `19876` must be available on the machine running Pi, and the browser
must be able to reach its loopback callback. Ask Pi to call `drupal_site_info`
to verify authenticated MCP access after authorization.

### Setup troubleshooting

- **`fetch failed` / connection refused**: verify DDEV is running and the client
  URL uses the actual HTTPS port. Reload Pi after correcting its configuration.
- **Protected resource does not match expected**: the client URL and Drupal's
  canonical resource URI must agree, including the port. Rebuild Drupal caches;
  `/.well-known/oauth-protected-resource` should advertise that exact resource.
- **`invalid_client`**: confirm the Client ID exists and the callback matches
  exactly. A public PKCE client must not require a client secret.
- **“You need to set the OAuth2 private key”**: configure both signing-key paths
  and verify PHP can read the files.
- **Invalid scope or missing tools/access denied**: check scope registration,
  client scope selection, user permissions, scope ceilings, enabled families,
  and target allowlists. Write does not imply read. Obtain a new token after
  changing requested scopes.
- **Stale or failed authorization**: run `/mcp-auth drupalmcp` again and use the
  current browser tab; do not reuse a previous authorization code.

Anonymous `/mcp` requests returning HTTP `401` are expected and confirm only
that the endpoint is reachable, not that OAuth setup is complete.

## Test prerequisites

Start DDEV and confirm that the MCP and OAuth modules are enabled:

```shell
ddev start
ddev drush pm:list --type=module --status=enabled --format=list \
  | grep -E 'drupal_mcp|simple_oauth'
```

If the local OAuth test users, roles, scope, and PKCE client have not been
created, run:

```shell
ddev exec env MCP_BASE_URL="https://drupalmcp.ddev.site:30001" \
  drush php:script scripts/mcp-oauth-setup.php
```

Use the site's actual origin for `MCP_BASE_URL`; the script sets the canonical
resource URI from it. This development-fixture script changes roles, users,
scopes, and settings. It is not a replacement for the admin setup above: the
project-root script registers the `mcp-inspector` client with its own callback,
not Pi's `drupal-mcp` client, and does not configure signing keys. Review the
script before running it on an existing site.

The setup script writes generated test-user passwords to
`secrets/mcp-test-credentials.txt`. The file is outside the web root and must
not be committed. Rotate or delete these credentials after testing.

## Automated tests

Run the module's PHPUnit suite (unit, kernel, and functional tests; kernel and
functional tests need the test database and base URL). The base URL is
resolved inside the web container, so it targets the container's own web
server rather than the public DDEV hostname and router port:

```shell
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" \
  SIMPLETEST_BASE_URL="http://localhost" \
  vendor/bin/phpunit -c web/modules/custom/drupal_mcp/phpunit.xml.dist
```

Run the architecture and security checks against the bootstrapped Drupal site:

```shell
ddev drush php:script scripts/test-mcp-architecture.php
```

Test the complete authorization-code and PKCE flow as a non-administrator MCP
reader:

```shell
scripts/test-mcp-oauth-flow.sh mcp_reader_user
```

This covers login, consent, token exchange, RFC 8707 resource binding, MCP
initialization, `drupal_whoami`, discovery, compatibility sessions, refresh
tokens, and invalid-token rejection.

The functional suite additionally proves that access- and refresh-token
revocation (RFC 7009) takes effect immediately on `/mcp`; revocation of
encrypted refresh tokens currently relies on the Composer-managed contrib
patch `patches/simple-oauth-rfc7009-jwt-revocation.patch`.

Exercise the read-only tool surface as both a reader and an administrator:

```shell
scripts/test-mcp-tools.sh mcp_reader_user
scripts/test-mcp-tools.sh mcp_admin_user
```

Running both accounts verifies that Drupal permissions control which tools and
records are visible.

Run the authentication and authorization denial-path tests:

```shell
scripts/test-mcp-denials.sh
```

These checks cover missing and invalid bearer tokens, cookie-only requests,
userless client-credentials tokens, missing OAuth scope, missing Drupal
permission, and revoked access/refresh tokens.

## Database diagnostics

Provision and verify the restricted database-inspection principal when testing
the database tools:

```shell
scripts/provision-db-readonly.sh
```

The provisioning check proves that the principal can read only the curated
inspection schema and cannot read the normal Drupal schema or perform writes.
The user-account surface additionally requires `administer users`; the taxonomy
surface requires `administer taxonomy`. Node data is intentionally unavailable
through database diagnostics because SQL cannot safely reproduce Drupal node
access grants; use the entity content tools instead.

## Drush diagnostics

Drush diagnostics require a Unix deployment with `/usr/bin/setsid` or
`/bin/setsid` and PHP's POSIX process functions. Each command runs in an
isolated process group so output-limit, timeout, and integration failures
terminate the command and all descendants. Diagnostics fail closed when these
process-control capabilities are unavailable.

## Security semantics worth knowing

- Field-level structure (definitions, types, labels) is exposed only through
  `drupal_fields_list` and `drupal_content_type_get`, which require the
  native `administer content types` permission. `drupal_schema_get` returns
  entity-type and bundle structure only.
- `drupal_file_get` follows Drupal core's file access semantics: metadata of
  files in the public filesystem is visible to accounts with `access
  content`, exactly as rendered `<img>` tags would reveal. Private-scheme
  files still require core's download permission.
- Pagination cursors are HMAC-signed with the site hash salt and capped;
  they cannot be forged by clients.
- Drush `config:get` is limited to server-defined projections. The initial
  `system.site` projection returns only the site name, slogan, front page, and
  default language; email addresses, UUIDs, config hashes, and other properties
  are removed even when the configuration object is enabled.
- Uninstalling the module removes its permission grants from roles (standard
  Drupal behavior). Reinstalling does not restore them; re-run
  `scripts/mcp-oauth-setup.php` and re-enable the diagnostics families in
  settings afterwards.

## Manual MCP client testing

Use the following development settings with MCP Inspector or another client
that supports Streamable HTTP and OAuth authorization-code flow with PKCE:

- Server URL: your exact canonical endpoint, for example
  `https://drupalmcp.ddev.site:30001/mcp`
- OAuth client ID: `mcp-inspector`
- Redirect URI: `http://127.0.0.1:6274/oauth/callback`
- OAuth scope: `mcp:read` for read tools, `mcp:write` for mutations, or both
  scopes for both capabilities
- Reader username: `mcp_reader_user`
- Writer account: separately provision a user with the intended write/native
  permissions and configure the write scope and client; do not assume the
  project-root read-fixture script creates a writer.
- Administrator username: `mcp_admin_user`
- Password file: `secrets/mcp-test-credentials.txt`

Anonymous MCP access is intentionally disabled. Opening or calling `/mcp`
without a valid bearer token should return HTTP `401 Unauthorized`.

## Independent-client conformance

Run both advertised protocol revisions through pinned, independent official MCP
clients:

```shell
web/modules/custom/drupal_mcp/tests/conformance/run.sh
```

The harness uses TypeScript SDK `1.30.0` for handshake revision `2025-11-25`
and Python SDK `2.2.0` for modern revision `2026-07-28`. Each client traverses
the paginated tool catalogue and calls `drupal_whoami`. Set `MCP_ACCESS_TOKEN`
to supply an existing token; otherwise the harness obtains a short-lived token
with the local PKCE test client and credentials file. Dependencies are installed
into a temporary directory and do not modify this module or the Drupal project.
The default obtains a read token. Set `MCP_TOKEN_CAPABILITY=write` to obtain the
write fixture token, or set `MCP_ACCESS_TOKEN` directly. Write-only tokens do
not inherit read catalogue access.

## Taxonomy mutations

Taxonomy mutations are configured at **Administration > Configuration > Web
services > Drupal MCP settings** (`/admin/config/services/drupal-mcp`) by an
account with `administer drupal mcp`:

1. Under **Enabled tool families**, check **Taxonomy term create and update**.
2. Under **Taxonomy**, select **Tags** as both readable and writable. Writable
   vocabularies must also be readable.
3. Leave **Allow permanent deletion** disabled unless deletion is explicitly
   required.
4. Optionally adjust **Idempotency retention** under **Limits**; the default is
   86,400 seconds.
5. Save the configuration and rebuild caches.

The equivalent DDEV/Drush configuration for create and update is:

```shell
ddev drush config:set drupal_mcp.settings families.taxonomy_mutation true -y
ddev drush config:set drupal_mcp.settings taxonomy.writable_vocabularies '["tags"]' --input-format=json -y
ddev drush cr
```

Enable deletion separately only when needed:

```shell
ddev drush config:set drupal_mcp.settings taxonomy.allow_term_delete true -y
ddev drush cr
```

Verify the effective settings with:

```shell
ddev drush config:get drupal_mcp.settings families
ddev drush config:get drupal_mcp.settings taxonomy
```

`drupal_term_create` and `drupal_term_update` are exposed only after the
taxonomy mutation family is enabled and `tags` is writable.
`drupal_term_delete` is advertised only when
`taxonomy.allow_term_delete` is also enabled. Every operation still
requires an `mcp:write` token, `access mcp write`, and applicable native Drupal
entity, field, and text-format access; configuration never bypasses those
checks.

Updates require the current `expected_revision_id`; create and update also
require an idempotency key. Delete requires exact revision and term-name
confirmation and refuses to delete referenced terms.

## Content (node) mutations

Node mutations are configured at the same settings form: check **Content create
and update** under **Enabled tool families**, then select writable content
types under **Content**. Writable types must also be readable, so the writable
set is always a subset of the exposed set. The
equivalent DDEV/Drush configuration is:

```shell
ddev drush config:set drupal_mcp.settings content.bundles '["article"]' --input-format=json -y
ddev drush config:set drupal_mcp.settings families.node_mutation true -y
ddev drush config:set drupal_mcp.settings content.writable_bundles '["article"]' --input-format=json -y
ddev drush cr
```

`drupal_content_create` and `drupal_content_update` are exposed only after the
node mutation family is enabled and at least one exposed bundle is writable.
Both require an `mcp:write` token, `access mcp write`, and applicable native
Drupal node, field, and text-format access; configuration never bypasses those
checks. Updates require the current `expected_revision_id`, which
`drupal_content_get` exposes as `revision_id`; both operations require an
idempotency key. Updates save new revisions. Node deletion is not part of this
surface.

See [docs/conformance.md](docs/conformance.md) for the evidence matrix and
coverage boundary. [docs/operations.md](docs/operations.md) covers deployment,
registration, rotation, monitoring, and recovery procedures. Track
Drupal-forward dependency work in
[docs/dependency-deprecations.md](docs/dependency-deprecations.md).
