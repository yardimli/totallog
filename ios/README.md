# TotalLog for iPhone

A native SwiftUI client for the existing TotalLog Laravel server. There is no WebView, WebKit, JavaScript runtime, or third-party iOS dependency. The separate Notes workspace is intentionally excluded; daily log text and event comments remain supported.

## Open and run

1. Open `ios/TotalLog.xcodeproj` directly in Xcode. No blank project or import step is needed.
2. Select the **TotalLog** target → **Signing & Capabilities**, choose your Apple development team, and change `com.totallog.iphone` if your team needs another bundle identifier.
3. Select an iPhone or simulator running iOS 17 or later and run the **TotalLog** scheme.
4. Tap **Sign in with browser**. The default server is `https://total-log.com`. Sign in on the website using your normal web account, then tap **Open TotalLog** on the return page. Cancelling the browser prompt leaves that page open; it does not automatically redirect again. The app has no email, password, registration or server-address input on its sign-in screen. Existing installations retain a previously saved server address.

The checked-in project is ready to open. `python3 ios/generate_project.py` regenerates it if Swift files are added. Signing team settings should be entered in Xcode afterward. Build output and per-user Xcode settings are ignored by Git.

## Laravel deployment

Deploy the changed Laravel code and the new migration together, using the existing server's normal deployment process:

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

Keep the server's existing `.env`, database and `APP_KEY`. Do not generate a new production key. The migrations add `mobile_operations`, `mobile_revisions` and `mobile_browser_logins`; existing data tables and sensor API paths remain in place. HTTPS is required by the app. Ensure the web server permits the usual `Authorization: Bearer …` header.

For Google Calendar linking from iPhone, add the following additional authorized redirect URI to the existing Google OAuth client's configuration:

```
https://YOUR-SERVER/api/mobile/google/callback
```

Include any Laravel installation subdirectory in that URL. Use a persistent shared Laravel cache for the short-lived OAuth state if the server runs on multiple instances. Authorization opens the system browser and returns through `totallog://oauth/google-complete`; no bearer token is included in that URL.

## Included screens and behavior

| Area | Native implementation |
| --- | --- |
| Journal | Calendar date navigation, today shortcut, daily timeline, scheduled events, hidden entries, log creation/editing/deletion, emoji and event comments |
| Events | Create/edit/delete definitions, daily/weekly/monthly recurrence, weekdays/month dates, time slots, default counts, sticky visibility, values, colors, photo icons and ordering |
| Recording | Record selected values/time slots, retain offline occurrence time, view counts, capture optional location and edit place labels |
| Goals | Targets, periods, date bounds, manual points, event/GitHub sources, progress/history, custom icons, edits/deletion |
| Search | Cached log, event, date and sensor payload search |
| Sensors | Activity details from browser, desktop, mobile browsing, Kindle, GitHub and Google Calendar; enable/unlink, client pairing, GitHub connection and Google authorization/sync |
| AI | Model listing/selection, questions, proposed actions and explicit confirmations; pending proposals survive an app restart |
| Media | Native Quick Look preview and sharing for generated images; viewed attachments are cached for offline reuse |
| Account | Browser sign-in (website handles registration/password reset), profile, password change, account deletion, time/week preferences and OpenRouter settings |
| Screensaver | Shared settings and logo, native animated interpretations of the web styles, preview and idle activation while the app is open |
| Administration | Admin-only user list and demo reset |
| Sync | Connection status, pending changes, correction/retry/discard, and conflict history retaining the full local payload |

The native screensaver animations approximate the web themes rather than embedding their CSS animations. Password reset emails use the existing web reset page. OAuth and account/security operations, AI, and fetching new remote media require connectivity. Regular logs, event definitions/recordings, goals, visibility, ordering, location and preferences queue offline.

## Offline storage and sync

- Browser sign-in uses a ten-minute, single-use code bound to a device-held verifier. Expiry is enforced by the server using the integer `expires_at_epoch` field; it never compares a phone-local clock or parses a timezone-dependent database timestamp. The expiry migration intentionally invalidates older pending sign-ins, so start a fresh sign-in after deployment. Cancelling in the app clears the local request and sends a verifier-authenticated cancellation to the server. The browser return page checks whether the request is still active and disables its link after cancellation, completion or expiry. If cancellation cannot reach the server while offline, local callbacks are still ignored and the server request expires normally. The website remains signed in, independently of the app request. The pending request is stored in Keychain so returning from the browser can also complete after an app restart. No bearer token is sent through the return URL. The first successful sign-in downloads account data. Subsequent launches can browse and edit the cached account without a network connection.
- The snapshot, aliases and pending operations are stored together in an atomically replaced JSON file in Application Support with iOS file protection. The bearer token is stored in Keychain. Passwords, OpenRouter keys, GitHub tokens and pairing keys are not saved in the offline queue.
- New records receive client UUIDs. Later operations may reference those UUIDs before the server assigns numeric IDs.
- Uploads contain at most 100 operations. The server records operation receipts under `(user_id, operation_id)` so retries cannot duplicate successful changes. Reusing an acknowledged ID with different contents is rejected.
- Local queue entries are acknowledged only after both upload results and a full server snapshot arrive. Edits created while a request is in flight remain in the queue. A failed download or local write does not discard them.
- Sync runs when the app becomes active, when connectivity returns, after edits, on pull-to-refresh and every 45 seconds while the main app is active. iOS suspension does not permit continuous background polling; reopening the app resumes sync.
- This version transfers a complete account snapshot rather than a paginated change feed. This makes deletions, including database cascades, visible without missing an incremental cursor. Very large accounts may take longer to download and persist. Binary attachments download when opened, and only previously downloaded attachments are available offline.
- Rejected changes remain visible and editable in **More → Sync & offline changes**. Resolve errors before signing out. **Sign in again** renews authentication without removing the offline queue. Accounts/servers cannot be switched while edits are pending.

## Conflict rules

Each mutation has an absolute UTC `edited_at`. The client estimates its clock offset from server responses. The server compares the edit time with the current record revision, independently of upload arrival time. The later edit wins; equal timestamps favor the server. Timestamps more than five minutes into the server's future are rejected.

Eloquent observers track web and sensor saves/deletes with microsecond revisions. The tracked models commit their row changes and observer revisions in the same database transaction, including ordinary web saves. Event edits include the associated block revision. Event ordering now uses individual model saves so web reordering also updates revisions. Manual goal points are append operations and use idempotency, rather than competing with goal definition edits.

Deletions have tombstones. Older edits cannot resurrect a newer deletion. Newer edits can restore retained record data when its required parents still exist. A removed credential-bearing sensor must be linked again, and already deleted media files cannot be recovered by timestamp comparison. Missing parents or invalid references are reported rather than silently recreating unrelated data. Database writes outside Eloquent do not produce microsecond observer revisions; existing row timestamps and complete snapshots still apply, but new integrations should use model saves or explicitly record revisions.

Keep operation receipts and tombstones: purging them requires a deliberate client resynchronization/retention policy.

## Validation and device checks

Validated on this iMac using Xcode 26.6 with signing disabled. No app was launched on an iPhone or simulator as part of the handoff; device testing remains yours.

```sh
xcodebuild -project ios/TotalLog.xcodeproj -scheme TotalLog \
  -sdk iphonesimulator -configuration Debug \
  -derivedDataPath /tmp/totallog-ios-build CODE_SIGNING_ALLOWED=NO build

APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  vendor/bin/phpunit --filter=MobileSyncTest

swiftc -module-cache-path /tmp/totallog-swift-module-cache \
  ios/TotalLog/Models.swift ios/TotalLog/SyncMerge.swift \
  ios/Tests/SyncMergeTests.swift -o /tmp/totallog-sync-tests
/tmp/totallog-sync-tests
```

The key above is for isolated in-memory tests only. Tests use SQLite from `phpunit.xml`; do not use this key for a real server.

Suggested first device check:

1. Sign in and compare a populated day, event definitions, goals and sensor history with the web.
2. Enable airplane mode. Create a log and event definition, record the event, add goal points, then close and reopen the app. Confirm the changes persist.
3. Reconnect and verify the same changes appear on the web once, including after another sync.
4. Edit a shared log on both clients at different times and confirm the later edit wins. Check the losing local payload in the sync history.
5. Delete on the web while the phone is offline; reconnect and inspect the timeline and any conflicts.

The mobile browser-sign-in and sync API tests pass together (22 tests, 162 assertions), including cross-timezone expiry and server cancellation, as do the standalone Swift merge checks. The updated Swift sources also pass iPhone-simulator type checking. The repository-wide PHP run has 137 passing tests and two unrelated failures in unchanged files: a semantic-class check at `resources/views/logs/show.blade.php:55`, and the absent tracked `public/totallog-chrome-extension/manifest.json`. The web asset build also needs ES-module interpretation for its existing PostCSS config with the installed Node version; verification assets were built with a temporary `type: module` setting, then `package.json` was restored. No web build configuration change is included here.

Authentication follows Laravel's [Sanctum mobile token approach](https://laravel.com/docs/10.x/sanctum#mobile-application-authentication). The app UI is built with [SwiftUI](https://developer.apple.com/documentation/swiftui).
