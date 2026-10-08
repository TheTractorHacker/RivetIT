# Deprecated compatibility shims

RivetIT began as a fork of ITFlow, and its shared code now lives in the RivetCore library (`rivet/rivet-core`). The old
`ITFlow\...` classes below are thin compatibility shims over RivetCore. They are deprecated as of **26.10.26**.

**Removal plan:** every shim stays, working and unchanged, for the whole 1.x line of RivetCore and every RivetIT release
that ships it. They are removed in 2.0 (see RivetCore ADR-005). Until then nothing breaks; new code should call RivetCore
directly. `tests/shim_deprecations.php` fails if a shim loses its `@deprecated` tag.

| Shim (kept for 1.x) | Use instead | Notes |
|---|---|---|
| `ITFlow\Audit\AuditService` | `RivetCore\Audit\AuditService` | The shim wires the mysqli adapter, request context and the event-bus hook. Static `record()` stays. |
| `ITFlow\KB\DocxConverter` | `RivetCore\KB\DocxConverter` | `class_alias`: identical class. |
| `ITFlow\KB\DocxConversionException` | `RivetCore\KB\DocxConversionException` | `class_alias`. |
| `ITFlow\KB\PdfConverter` | `RivetCore\KB\PdfConverter` | `class_alias`. |
| `ITFlow\KB\PdfConversionException` | `RivetCore\KB\PdfConversionException` | `class_alias`. |
| `ITFlow\Cron\JobRunner` | `RivetCore\Cron\JobRunner` | Only adds the old default state directory `sys_get_temp_dir()/rivetit-jobs`; pass it explicitly. |
| `ITFlow\ITSM\ChangeService` | `RivetCore\ITSM\ChangeService` | Construct with `ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter`. |
| `ITFlow\ITSM\ProblemService` | `RivetCore\ITSM\ProblemService` | Also needs `TicketsProblemLink`. |
| `ITFlow\Jobs\JobQueue` | `RivetCore\Jobs\JobQueue` | Construct with `MysqliDatabaseAdapter`. |
| `ITFlow\Webhooks\WebhookDispatcher` | `RivetCore\Webhooks\WebhookDispatcher` | The shim supplies the `webhooks` table subscriptions and the `X-ITFlow` / `X-RivetIT` header prefixes. |
| `ITFlow\Automation\AutomationRuleEvaluator` | `RivetCore\Automation\AutomationRuleEvaluator` | Construct with `MysqliDatabaseAdapter`. |
| `ITFlow\Redis\Lock` | `RivetCore\Redis\LockManager` | `new LockManager(new GlobalRedisClientProvider(), 'rivetit:')`. |
| `ITFlow\Redis\RateLimit` | `RivetCore\Redis\RateLimiter` | `new RateLimiter(new GlobalRedisClientProvider(), 'rivetit:')`. |

Not shims (RivetIT's own code that merely uses RivetCore, so not deprecated): `ITFlow\Cron\JobCatalog` (the job list is
edition data), `ITFlow\Knowledge\CredentialReferenceRenderer` (edition UI), `ITFlow\Mcp\McpConfig` / `McpDiagnostics` / `McpIdentityLinks` (map this edition's settings and users onto Core), `ITFlow\Redis\RedisSettings`,
`ITFlow\Redis\CronGuard`, `ITFlow\Compliance\*`, `ITFlow\Workflow\WorkflowService` (the engine moved into this edition),
`ITFlow\Webhooks\DestinationConfig`, `ITFlow\Webhooks\WebhookTester` and the `ITFlow\Core\Adapter\*` classes.

Call sites already moved to RivetCore directly: the KB DOCX/PDF import (`agent/post/kb_article.php`) and the training
DOCX importer. Others stay on the shim until the construction arguments above are worth repeating at the call site.
