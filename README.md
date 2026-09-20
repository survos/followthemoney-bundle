# survos/followthemoney-bundle

Reusable historical candidate review using the standalone FtM model and a
replaceable resolution provider. Shared contract:
`lib/followthemoney/docs/ecosystem.md` in survos/mono.

```yaml
survos_follow_the_money:
    base_uri: '%env(YENTE_URL)%'
    datasets: [rappnews_1952_1954]
    review_role: ROLE_ADMIN
    base_template: base.html.twig
# Optional: token (gateway bearer), provider_service (ResolutionProvider service ID)
```

Register the bundle and import its `src/Controller/` attribute routes. The default
paths begin `/ftm/review`; all routes require the configured role. The host app
supplies login and a Twig base layout. Menu links belong to the application.
Implement `Review/EvidenceLinker` to translate archival source references into
URLs. Its default deliberately returns no link for opaque references.

```sh
php bin/console ftm:review:install
php bin/console ftm:review:import rappnews_1952_1954 2 /path/entities.jsonl /path/evidence.jsonl
php bin/console ftm:review:export rappnews_1952_1954 /path/decisions.jsonl
```

Installation creates only three `ftm_review_*` DBAL-managed tables in the default
application database. Exclude that prefix from ORM schema diff using the existing
connection's schema_filter (combine with existing exclusions). Ink supplies this
configuration. No tables are created in web requests. Future schema changes must
have explicit migrations; install is not a schema updater. PostgreSQL was tested
locally; unit storage checks run against SQLite. Back up this durable data.

Routes: GET home/search/candidate screen, POST `/{dataset}/decision`, GET
`/{dataset}/decisions` (latest decision per pair, most recent 200), and GET
`/{dataset}/history/{source}/{target}` (full pair history and provenance). Mutation
is session-authenticated and CSRF-protected; actor comes from the authenticated
user. This is a browser review API, not an unauthenticated ingestion endpoint.
Blank reasons, self-pairs, unknown/stale candidates and stale revisions fail.

Decisions are pairwise overlays, not destructive merges. The original index is
unchanged. `unresolved` supersedes the active judgement while preserving history.
No Nomenklatura clustering bridge is claimed. Candidates without locally imported
source generations cannot be reviewed yet. The index/evidence version check
assumes the publication process keeps versions immutable; use the publisher.

JSONL I/O uses survos/jsonl-bundle. Incomplete required properties are reported,
not fabricated. Malformed schema/wire data or evidence pointing to absent entities
rolls back the complete import. Generations and decision snapshots are retained.
The present import does not perform a full cross-entity schema-range audit.

Run `vendor/bin/phpunit -c bu/followthemoney-bundle/phpunit.xml.dist` from mono.
Ink's `tests/ftm-review-smoke.php` tests the real authenticated HTTP flow, writes
only synthetic demo decisions, and checks real source-block links read-only.
