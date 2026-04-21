# hexis/audit-bundle

A Symfony bundle that captures security events (login, logout, failed-login, impersonation) and Doctrine writes (insert/update/delete) into pluggable storage — Elasticsearch primary with Doctrine fallback, or Doctrine-only. Supports optional pre-image snapshots per entity so versioning / time-travel can be built on top.

Built for when "who changed what, when" is a compliance question.

---

## What it does

- **Captures** `LoginSuccessEvent`, `LoginFailureEvent`, `LogoutEvent`, `SwitchUserEvent` (enter + exit).
- **Captures** Doctrine writes on entities marked `#[Auditable]`. `onFlush` collects, `postFlush` writes — so audit failures never roll back host data.
- **Snapshot modes** per-entity: `none` (diff-only record), `changed_fields` (old+new values on changed fields), `full` (pre-image + post-image + diff, for versioning).
- **Storage**: Elasticsearch primary + Doctrine fallback (or Doctrine-only). `ChainedAuditWriter` auto-falls-back on primary exception; `audit:drain-fallback` replays pending rows.
- **Non-blocking**: exceptions during capture or flush are swallowed and written to `error_log`. Audit never poisons the request path.
- **Session-id workflow correlation**: every event carries a SHA-256 hash of the session id — never the raw value — so sessions are correlatable for workflow visualization without creating a hijack vector.
- **Legacy migration** for apps with a hand-rolled audit table: `audit:migrate-legacy` with a pluggable row mapper.

---

## Requirements

- PHP 8.2+
- Symfony 7.0+ (tested on 7.3)
- Doctrine ORM 3 / DBAL 3
- `symfony/security-http`

Optional:

- `elastic/elasticsearch: ^8.0` — required when using Elasticsearch storage
- `symfony/messenger` — enables async flush instead of `kernel.terminate`

---

## Install

### 1. Require the package

```bash
composer require hexis/audit-bundle
```

In a monorepo with a path repository:

```json
"repositories": [
  { "type": "path", "url": "packages/audit-bundle" }
],
"require": {
  "hexis/audit-bundle": "@dev"
}
```

### 2. Register the bundle

```php
// config/bundles.php
return [
    // ...
    Hexis\AuditBundle\AuditBundle::class => ['all' => true],
];
```

### 3. Configure the bundle

```yaml
# config/packages/audit.yaml
audit:
    enabled: true
    environments: [prod, dev]

    security:
        enabled: true
        events: [login, login_failure, logout, switch_user]
        firewalls: ~                       # null or empty = all firewalls

    doctrine:
        enabled: true
        entity_managers: [default]         # EMs whose UoW we hook

    storage:
        primary: doctrine                  # doctrine | elasticsearch
        fallback: ~                        # ~ | doctrine
        elasticsearch:
            client: ~                      # service id of your configured ES Client adapter
            index: 'audit-%Y.%m'           # strftime tokens %Y/%m/%d/%H supported
            index_template_name: audit
            refresh: false
        doctrine:
            connection: default
            entity_manager: ~              # ~ = default EM
            table_prefix: hexis_audit_   # derives hexis_audit_log
            table: ~                     # set to a full name to override (e.g. 'my_app_audit')
            retention_days: 365

    snapshots:
        default_mode: none                 # none | changed_fields | full
        serializer_group: audit:snapshot

    context:
        capture_ip: true
        capture_user_agent: true
        capture_request_path: true
        capture_session_id: true           # stores SHA-256 hash, never raw session id
```

### 4. Run migrations

```bash
bin/console doctrine:migrations:migrate
```

One table is created: `hexis_audit_log` (vendor-namespaced to avoid collisions with host-owned `audit_log`). Name is configurable via `audit.storage.doctrine.table` (full override) or `audit.storage.doctrine.table_prefix`. Indexes cover `(occurred_at)`, `(actor_id, occurred_at)`, `(target_class, target_id, occurred_at)`, `(event_type, occurred_at)`, `(session_id_hash, occurred_at)`, and `(source_of_truth, pending_replay_at)`.

---

## Usage

### Capture a custom event from app code

```php
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\ContextCollector;

final readonly class PayrollService
{
    public function __construct(
        private AuditWriter $audit,
        private ContextCollector $context,
    ) {}

    public function approve(Payroll $payroll): void
    {
        // ... business logic ...

        $this->audit->write(new AuditEvent(
            type: EventType::CUSTOM,
            actor: $this->context->collectActor(),
            target: Target::entity(Payroll::class, $payroll->getId()),
            snapshot: Snapshot::none(),
            context: $this->context->collectContext(),
            action: 'payroll.approve',
        ));
    }
}
```

### Opt an entity into automatic capture

```php
use Hexis\AuditBundle\Attribute\Auditable;

#[ORM\Entity]
#[Auditable(mode: 'changed_fields', ignoreFields: ['updatedAt'])]
class Employee
{
    // ...
}
```

Modes:

| Mode | Written | Use case |
|---|---|---|
| `none` | Only the fact an entity was mutated + its class/id | Low-cost activity log |
| `changed_fields` | `{field: {old, new}}` diff over the changed columns | Change-tracking UI |
| `full` | pre_image + post_image + diff via Serializer group `audit:snapshot` | Versioning / time-travel |

`ignoreFields` excludes named fields from both diff and snapshot — good for `updatedAt` columns that would otherwise show up in every row.

For vendor entities you can't annotate, configure via YAML:

```yaml
audit:
    doctrine:
        classes:
            Some\Vendor\Entity\Foo:
                mode: changed_fields
                ignore_fields: [updated_at]
```

---

## Elasticsearch storage

The bundle never hard-codes a specific ES client. You provide an adapter service implementing `Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient` with three methods: `index()`, `bulk()`, `putIndexTemplate()`. The host chooses how to wire it — typically a thin adapter over `elastic/elasticsearch:^8.0`.

```php
use Elastic\Elasticsearch\ClientBuilder;
use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;

final readonly class ElasticClientAdapter implements ElasticsearchClient
{
    public function __construct(private \Elastic\Elasticsearch\Client $client) {}

    public function index(string $index, string $id, array $document, bool $refresh = false): void
    {
        $this->client->index([
            'index' => $index,
            'id' => $id,
            'body' => $document,
            'refresh' => $refresh ? 'true' : 'false',
        ]);
    }

    public function bulk(iterable $operations, bool $refresh = false): void
    {
        $body = [];
        foreach ($operations as [$idx, $id, $doc]) {
            $body[] = ['index' => ['_index' => $idx, '_id' => $id]];
            $body[] = $doc;
        }
        $this->client->bulk(['body' => $body, 'refresh' => $refresh ? 'true' : 'false']);
    }

    public function putIndexTemplate(string $name, array $template): void
    {
        $this->client->indices()->putIndexTemplate(['name' => $name, 'body' => $template]);
    }
}
```

Configure it:

```yaml
audit:
    storage:
        primary: elasticsearch
        fallback: doctrine            # optional — pending rows drain to ES later
        elasticsearch:
            client: App\Audit\ElasticClientAdapter
            index: 'audit-%Y.%m'
```

Then install the index template once (safe to re-run):

```bash
bin/console audit:install-elasticsearch-template
```

The shipped template maps `actor`, `target`, `request`, `session_id_hash`, `occurred_at`, etc. with appropriate types; `snapshot.pre_image` / `snapshot.post_image` / `context` are `object` fields with `enabled: false` so arbitrary entity shapes don't explode ES's field count.

### Fallback & drain

When `fallback: doctrine` is set, a primary write exception falls through to the Doctrine writer with `source_of_truth = 'fallback'` and `pending_replay_at = occurred_at`. Run periodically:

```bash
bin/console audit:drain-fallback --limit=1000
```

Replayed rows are marked `source_of_truth = 'replayed'` and `pending_replay_at = NULL`. Idempotent: the `event_id` (ULID) is the document id in ES, so replays upsert rather than duplicate.

---

## Commands

| Command | Purpose |
|---|---|
| `audit:install-elasticsearch-template` | Install/update the shipped ES index template. Only registered when ES is configured. |
| `audit:drain-fallback [--limit=N] [--dry-run]` | Replay rows from the Doctrine fallback into the primary writer. |
| `audit:migrate-legacy [--source-table=…] [--source-connection=…] [--batch=N] [--limit=N] [--restart] [--dry-run]` | Copy rows from a host-owned legacy audit table into the bundle's storage. Resumable via a progress file. |
| `audit:prune [--older-than-days=N] [--dry-run]` | Delete Doctrine rows older than the retention window. Does not touch Elasticsearch — use ILM there. |

---

## Multi-EM / cross-tenant setups

If the host's default connection is tenant-scoped but audit data should be cross-tenant, point the bundle at a global connection + EM:

```yaml
audit:
    storage:
        doctrine:
            connection: superadmin
            entity_manager: superadmin
```

Run the bundle's migration on that EM:

```bash
bin/console doctrine:migrations:migrate --em=superadmin
```

The Doctrine listener attaches per-EM via `doctrine.event_listener` tags. If you want only specific EMs audited, split the listener wiring via a compiler pass.

---

## Safety properties

- **No audit recursion.** The bundle's `AuditLog` entity is hard-coded in `AuditableRegistry::BUNDLE_CLASSES` as never-audited, and `DoctrineAuditWriter` uses direct DBAL (not ORM) so audit writes never re-enter the UoW the listener is attached to.
- **No host transaction coupling.** Capture happens in `onFlush`, actual writes happen in `postFlush` after the host transaction has committed. An audit write failure cannot roll back host data.
- **No credential leakage on failed login.** `SecurityAuditSubscriber` never reads the passport or raw token; it records only the attempted identifier (username/email) and the exception class. The password is never touched.
- **No lazy-load cascade during capture.** `AuditSnapshotNormalizer` stops at relations and emits `{@ref, id}` tuples instead of dereferencing, so full-mode snapshots don't trigger N+1 queries.
- **Session id is hashed.** Raw session id never leaves `ContextCollector`; only the SHA-256 hash is stored, preserving correlation without the hijack vector if the audit store is compromised.

## Known limitations (v0.1)

- **Soft-delete detection** is out of scope. Gedmo-style soft-deletes look like UPDATEs to the listener and will be tagged `ENTITY_UPDATE`, not `ENTITY_DELETE`.
- **No built-in UI.** Query the `hexis_audit_log` table directly or via your ES tooling of choice. A search/read UI is on the follow-up roadmap.
- **No ILM.** Elasticsearch retention must be handled on the cluster side.

---

## Development

```bash
cd packages/audit-bundle
../../vendor/bin/phpunit
```

Tests cover the writers (Doctrine + ES mock), the BufferedAuditWriter, ChainedAuditWriter, SecurityAuditSubscriber (each event type + credential-leak assertion), DoctrineAuditListener (insert/update/delete + full-mode pre/post images + recursion guard + ignore_fields), AuditSnapshotNormalizer (cycle-safe, relation caps), DefaultLegacyRowMapper, and DrainFallbackCommand.

---

## License

MIT.
