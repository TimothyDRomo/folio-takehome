# Folio Take-Home — Agent Context

## App overview
Small PHP 8.3 / SQLite document-sharing tool. Staff create documents and share
them with recipients via persistent hex tokens. No framework — vanilla PHP.
Runs entirely inside Docker. Edit files on host, changes reflect immediately on
browser refresh.

## File structure
```
lib/
  bootstrap.php   — db(), current_staff(), audit_log(), random_token(), h()
  layout.php      — render_header(), render_footer()
public/
  index.php       — redirects to admin.php
  admin.php       — document creation form + document list
  share.php       — generate share link for a specific document
  view.php        — recipient view, resolves token to document
  assets/
    style.css
tests/
  test.php        — test runner, add new tests here
migrations/       — numbered SQL migration files (001_*.sql, 002_*.sql ...)
migrate.php       — runs unapplied migrations in order on startup
schema.sql        — NEVER edit this, baseline only
seed.php          — seeds db.sqlite on docker compose up
db.sqlite         — generated, never commit
```

## Key functions — lib/bootstrap.php
```php
db()                          // PDO singleton, SQLite
current_staff()               // returns staff row id=1 (Freddy Folio, hardcoded — no real auth)
audit_log(                    // call explicitly after every significant action
    string $action,           // e.g. 'create', 'schedule', 'view'
    string $entity_type,      // e.g. 'document', 'share'
    int    $entity_id,        // the affected row's id
    array  $details = []      // gets json_encode()d into details column
)
random_token()                // generates hex share token
h(string $s)                  // htmlspecialchars — use on ALL user input in templates
```

## Audit log — call for every significant action
- Document created → audit_log('create', 'document', $docId, ['title' => $title, 'publish_at' => $publish_at])
- Share link created → audit_log('create', 'share', $shareId, ['document_id' => ..., 'recipient_email' => ...])
- Note: audit_log() calls current_staff() internally — it assumes a logged-in staff member.
  Do NOT call it from view.php (recipient context, no staff session).

## Timezone gotcha
bootstrap.php sets date_default_timezone_set('America/Chicago').
SQLite's datetime('now') returns UTC regardless of PHP timezone setting.
For publish_at comparisons use PHP's date('Y-m-d H:i:s') which respects the set timezone.

## Migration conventions
- NEVER edit schema.sql
- Add migrations to migrations/ as numbered SQL files: 001_add_publish_at.sql
- migrate.php runs all migrations in order on startup
- docker compose up must work from a fresh clone with zero manual steps
- Migrations use ALTER TABLE to add columns to existing tables

## Test pattern (tests/test.php)
```php
test('description of what is being tested', function () {
    // arrange — set up data, modify seeded state via db() queries
    // act — perform the action
    // assert — use assert_true($condition, 'failure message')
});
```
- Seed runs fresh before every test run — always start from known state
- Tests are database-level assertions, not HTTP assertions
- Add at least one test per feature built
- Run tests: docker compose exec app php tests/test.php

## Feature 1 — Scheduled publishing
### Design decisions made
- Gate is on STAFF side (admin.php document list), not recipient side
- In admin.php document table: if publish_at is in the future, replace
  "Create share →" link with plain text "Available [date]" — no clickable link
- publish_at is nullable — null means publish immediately (no gate)

### Schema change
Migration 001: ALTER TABLE documents ADD COLUMN publish_at TEXT;
(nullable, stored as SQLite datetime string)

### admin.php changes needed
- Add optional datetime-local input to New Document form
- On POST: capture publish_at, include in INSERT
- In document list query: include publish_at in SELECT
- In document list render: show "Available [date]" instead of share link if
  publish_at is not null and in the future

## Feature 2 — Human-readable document IDs
### Design decisions made
- COMPLEMENT existing share tokens, do not replace them
- Readable ID lives on documents table as a new 'readable_id' column (TEXT UNIQUE)
- Have a new "Readable ID" field limited to 26 characters. if populated use it, if not use the title.
- Format: substring of (readable_id or title value), 0 , 26.  (with spaces replaced by '-') + '-' + 3 random lowercase alphanumeric chars for a total of 30 characters.
  Example: welcome-packet-k7x
- Character set: lowercase a-z and 0-9 only — no ambiguous chars in the last 3 characters only(0/O, 1/l)
- Last 3 characters are auto-generated from readable_id base (or title if left blank) 
- On unique constraint collision: retry with new random suffix
- Readable ID can be used in the URL to access the document.
- Two routes to view document. the existing view.php?token= and the new view.php?id= (plus the new readable_id)

### Schema change
Migration 002: ALTER TABLE documents ADD COLUMN readable_id TEXT UNIQUE;

### Readable_id generation function (add to bootstrap.php)
```php
function generate_readable_id(string $input): string {
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($input)));
    $base = substr($base, 0, 26);
    $base = trim($base, '-');
    $chars = 'abcdefghjkmnpqrtuvwxy34679'; // no ambiguous chars (0/O, 1/l/i, s/5, 2/z removed)
    $suffix = '';
    for ($i = 0; $i < 3; $i++) {
        $suffix .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $base . '-' . $suffix;
}
```

### admin.php changes needed
- On document INSERT: generate readable_id, retry on UNIQUE violation
- Display readable_id in document list table
- audit_log includes readable_id in details array

## Feature 3 — Search by name (if time allows)
### Design decisions made
- Server-side LIKE contains search — not client-side row hiding
- Single field search on title only (body search adds noise for staff workflow)
- SQL: WHERE title LIKE '%{term}%'
- No fuzzy/tokenized matching — internal tool with small doc set,
  staff know roughly what they titled documents
- For production would use SQLite Full text search extension
- Search input with Search button and a Clear button that resets to full list. 
- Clear button only present when ?search= is present in URL
- No new migration needed

### admin.php changes needed
- Add search input + search button + Clear button above document list
- On GET with ?search=term: filter query with LIKE
- Clear button resets to /admin.php with no query param

## What NOT to touch
- schema.sql (historical baseline, never edited directly)
- Existing share token mechanism (hex tokens in shares table unchanged)
- docker-compose.yml
- Dockerfile
- seed.php (beyond what's needed for tests to work)
- lib/bootstrap.php db() connection logic