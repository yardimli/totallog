# Repair hourly sensor reports

Deploy the server code. This fix uses the existing tables; no migration is needed.
Update the desktop app and browser extension for the foreground/idle checks.
The desktop client preserves upload payloads on retry, and the server recognizes
exact matches in the existing desktop activity records.

Preview one account's repair (replace the email):

```sh
php artisan sensors:repair-hourly --user=you@example.com --discard-invalid-browsing
```

Apply the same repair:

```sh
php artisan sensors:repair-hourly --user=you@example.com --discard-invalid-browsing --apply
```

Use `--user=123` for a numeric account ID. Add `--from=2026-10-12 --to=2026-10-13`
to limit both preview and apply to those inclusive log dates. With no dates, all
days for that account are considered. The command does not touch other accounts
or non-sensor log entries.

Each browser, desktop and mobile-browser hour gets its own report, using activity
timestamps in the configured application timezone, not the old report's display
time or metadata. Exact duplicate activity records are removed; distinct activity
is retained. Empty/redundant sensor reports are deleted after moving their activity.
Desktop durations are retained, including gaps already excluded by the desktop
sampler; visits remain counts. Overlapping but non-identical historical sessions
are not guessed away, so multi-device totals can exceed an hour.

`--discard-invalid-browsing` removes whole browser sessions whose timestamps or
durations cannot fit the hour in which they started (for example a 04:21 session
ending near noon). It also removes negative time ranges or durations longer than
the recorded range. It does **not** claim to recover the true active minutes from
these corrupt records. Without this option those records are counted as invalid
in the preview but retained. This rule applies to browser heartbeat sessions, not
desktop batch ranges or mobile history visits.

The preview reports `before`/`after` sensor block counts, removed activity rows,
exact duplicates, and invalid browser sessions. Before changing each day,
`--apply` writes and flushes the original sensor blocks and activity records to
`storage/app/sensor-repairs/<timestamp>-<account>-<random>.jsonl`; the path is printed.
Keep this private backup for inspection or manual restoration. Each day is a
transaction, writers for these sensors use the same account lock, and a failed day
rolls back. Earlier committed days stay repaired. Rerunning does not duplicate data.
Related attachments or other unexpected records prevent deletion and roll back
that day's repair.

## Removing known AFK domains from a specific date range

Historical data has no continuous idle history. A valid-looking browsing session
cannot reliably be classified as AFK from its domain alone. If you know that **all**
recordings for a domain in a selected range should be removed, use the explicit
domain option. This also removes any genuine active visits to that exact domain
in the range; it is not a permanent blocklist. Both date bounds are required.

```sh
php artisan sensors:repair-hourly --user=you@example.com --from=2026-10-12 --to=2026-10-13 --discard-invalid-browsing --remove-browsing-domain=drive.google.com --remove-browsing-domain=kdp.amazon.com --remove-browsing-domain=remotedesktop.google.com
```

Review the preview, then repeat with `--apply` if that is the intended scope.

## Stop new over-reporting

Reload the updated unpacked browser extension (version 2.0.2) in
`chrome://extensions` and rebuild/install the updated desktop app. The extension
requires a focused, non-minimized browser window and an active OS idle state.
It coalesces simultaneous heartbeat triggers. On Windows, the desktop bridge
independently requires a foreground browser process and fewer than 180 idle
seconds; an unavailable OS idle reading fails closed. History imports remain
separate and are allowed while idle because they count past visits, not duration.

Desktop sampling requires both interval endpoints to be active, and skips long
sampling gaps such as sleep/resume. Each upload's payload is persisted before
sending and retained on retries, even after restart. Duplicate detection compares
the account, client, application, process, timestamps and duration against existing
activity rows. It does not require a receipt table, and cannot recognize a retry
once the corresponding activity has been deleted.

The three-minute idle threshold intentionally permits a short period without
keyboard/mouse input (for example while reading). It does not count hours of
absence. An already-open tab is not itself evidence of activity. Test by leaving a
tab open, switching to a non-browser app, then leaving the computer untouched for
more than three minutes; browser time should stop advancing. Repeat with screen
lock and sleep/resume on the actual Windows installation.
