# Desktop logging and synchronization

The Windows desktop app stores account data in `logging.sqlite3` in Tauri's per-user application data directory
(normally `%APPDATA%\com.totallog.desktop`). The server and numeric user ID together identify an account.
Cached records, pending operations and image downloads are partitioned by that identity.
Account tokens are protected with Windows DPAPI; passwords are never persisted. The database's logging
content is ordinary local SQLite data. Back up the application-data directory while the app is closed.

## Deployment

1. Deploy the PHP changes, including the new desktop pull endpoint and change observer.
2. Run `php artisan migrate --force` on the server. This adds the per-account change-version table.
3. Build/install desktop 0.6.0 with `cd desktop; npm install; npm run desktop:build`.
4. Quit the previous app from its tray menu before installing. Sign in and let the first download complete.

Existing mobile and sensor endpoints remain compatible. Existing tracker settings are preserved.
The desktop JSON configuration files are explicitly excluded from the repository's blanket JSON ignore
rule so fresh checkouts include the build configuration and IPC capabilities.

## Scheduling and local edits

- A visible, focused, non-minimized main window refreshes every five minutes.
- An unfocused, hidden or minimized main window refreshes every hour.
- Native Rust timers perform account sync even when WebView timers are throttled. Opening/focusing the
  app does not blindly fetch: the native timer checks the elapsed time against the foreground interval.
- An edit first inserts an immutable UUID operation in SQLite and updates the local view. It immediately
  requests upload, bypassing the timer. An edit made during an in-flight sync runs after it.
- Manual refresh and online actions also request immediate sync. Network failures retain local edits;
  later periodic or manual sync retries them. The queue is ordered and split into requests of at most 100.
- Existing desktop activity uploads use the same five-minute/hourly cadence, with immediate flush on
  a logging edit. The browser extension's existing live heartbeat bridge is a separate sensor protocol;
  it retains its live forwarding behavior. Kindle capture remains explicitly manual.

## Protocol and server load

Authentication and uploads reuse `/api/mobile/login` and `/api/mobile/sync`. Desktop reads use
`POST /api/mobile/desktop/pull` with an optional opaque `cursor`. A response contains account ID,
schema version, new cursor, changed records, deleted IDs, reset flag and server time. Notes and secrets
are excluded. Images download only when opened and are then cached locally.

Each relevant Eloquent model write invalidates a per-account version inside the model transaction.
An unchanged poll reads that small version and returns empty changes: no history serialization or goal
recalculation. Nested sensor records, goal entries and source changes invalidate their owner's version.
When changed, the server builds the existing mobile snapshot and compares record hashes against the
prior cursor, sending only changed records and deletion markers. This bounds transfer, although rebuilding
a changed account still costs work proportional to its history. Hash manifests expire after seven days;
an expired/unknown cursor returns a full reset. A reset replaces only cached server records, never the outbox.
An hourly reconciliation catches maintenance SQL that bypasses model observers and time-based expiry.

## Retries and conflicts

The server's existing idempotency receipts prevent duplicate events after a lost response. Operations
are never rewritten after acceptance. Accepted receipts remain in SQLite until downloaded changes and
the new cursor commit in one transaction, then the acknowledged queue rows are removed. If upload succeeds
but download fails, the next sync retries the download without resending accepted mutations.

Pending edits project over the last downloaded records. The server's existing edit-time conflict rules
choose the winning version. Conflicts and rejected inputs stay visible with their saved input; users can
copy or discard them. Rejected inputs can also be corrected and retried with their original creating UUID,
preserving dependent offline event/goal references. Account switching and sign-out are blocked while
unresolved local operations remain. Expired tokens can be renewed with **Settings → Sign in again**.

## Verification

`php artisan test --filter='DesktopSyncTest|MobileSyncTest|SensorTest|GoalTest|EventRepeatTest|HourlySensorRepairTest|SettingsPagesTest'`
checks isolation, nested deltas, unchanged polls, rollback, timestamps, retries and existing sensor behavior.

`node --test tests/desktop-logging.test.mjs tests/desktop-tracking.test.mjs` checks local projection and recurrence.
`cargo test --lib` in `desktop/src-tauri` checks persistent SQLite storage and upload-success/download-failure recovery.

For the browser smoke test, start `npm run dev` in `desktop`, then run
`npm exec --yes --package=@playwright/test -- node tests/desktop-ui.smoke.mjs` at the repository root.
It uses fixture IPC and headless Edge; it does not sign in or mutate a real account.
