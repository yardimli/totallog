# Mobile API v1

Base path: `/api/mobile`. Send `Accept: application/json`. Protected routes require a Sanctum bearer token with the `mobile` ability; guest/demo users cannot use these APIs.

## Browser sign-in

The iPhone sign-in screen opens the external browser against `https://total-log.com` by default. It has no credential inputs.

1. The app generates a random verifier and state and posts `{challenge: SHA256(verifier) as lowercase hex, state, device_name, expected_user_id?}` to `/browser-login/start`. Both verifier and state are 64 hex characters. The server returns `{request_id, url}`.
2. Open `url` in the browser. The authenticated web route `/mobile/sign-in/{id}` uses the existing login/intended-redirect mechanism. After login it shows a return page with an explicit **Open TotalLog** link to `totallog://signin?request_id=…&state=…&code=…`. There is no automatic redirect; cancelling the browser prompt leaves this page usable.
3. The app verifies request/state and posts `{request_id, code, verifier}` to `/browser-login/exchange`. The response contains the mobile token and account. Codes expire after ten minutes measured with server-side Unix seconds (`expires_at_epoch`) and can be exchanged once; only hashes of codes/verifiers are retained server-side. Pending edits restrict sign-in to their original account. Reloading the browser authorization page returns the same verifier-bound code for that account instead of replacing it. Once approved, a request cannot be reassigned to a different account. Rejections include a non-secret `error_code` and log only the request ID and reason.

Deploy both the `mobile_browser_logins` table migration and the `add_absolute_expiry_to_mobile_browser_logins` migration before using this flow. Existing pending requests must be restarted after the expiry migration. Phone, PHP and database timezones need not match. `POST /browser-login/cancel` takes `{request_id, verifier}` and idempotently removes an unconsumed request. The authenticated web endpoint `/mobile/sign-in/{id}/status` reports whether the current account’s request is active so the return page can disable a cancelled or completed link. No extra third-party OAuth configuration is required for TotalLog sign-in. Existing credential endpoints remain available for compatibility but are not used by the iPhone sign-in screen.

## Authentication and snapshots

Calendar fields `logs[].log_date` and `goals[].start_date/end_date` are date-only
`YYYY-MM-DD` strings (or null for open goal boundaries), never UTC timestamps.
Clients must preserve these calendar dates without timezone conversion. Actual
instants, including edit timestamps, remain ISO 8601 timestamps.


- `POST /login`: `{email, password, device_name}` → `{token, user, server_time}`.
- `POST /register`: `{name, email, password, password_confirmation}`.
- `POST /forgot-password`: `{email}`.
- `POST /logout`: revokes the current token.
- `GET /snapshot`: complete account snapshot with `schema_version: 1`, `server_time`, `user`, `tasks`, `goals` with sources/entries, `logs`, `blocks` with event/sensor/attachment details, redacted `sensors`, `api_calls`, `chat_proposals`, `screensaver_logo` and `revisions`. No Notes workspace records or authentication secrets are serialized.

## Mutation envelope

`POST /sync`:

```json
{
  "operations": [{
    "id": "814bc5d9-a5a7-49a9-b9eb-ce496c978adb",
    "kind": "blocks.create",
    "edited_at": "2026-09-10T08:00:00.123Z",
    "target_id": null,
    "data": {"log_date": "2026-09-10", "type": "text", "content": "An offline entry", "occurred_at": "16:00"}
  }]
}
```

Response: `{results: [{id, status, entity_id?, message?}], server_time}`. Status is `applied`, `conflict`, or `rejected`. Accepted/conflicting results are durable receipts. Rejected operations may be corrected and retried; their dependent operations must retain the creating UUID. Request-level failures are HTTP errors; clients must retain unacknowledged operations. A reused receipted UUID with a modified payload is rejected.

Supported operation kinds:

- `blocks.create/update/delete/visibility` (`visibility` uses explicit `is_hidden`).
- `tasks.create/update/delete/position` (`position` is zero-based).
- `events.create/update/location` (delete an event through its block).
- `goals.create/update/delete/progress`.
- `settings.update/screensaver/logo` (`logo_data` is a nullable, cropped 128×128 PNG data URL).
- `sensors.update/delete` (`update` uses explicit `enabled`).

Create/update data uses the existing Laravel controller validation contracts. Event and log creation also require `log_date: YYYY-MM-DD`; event creation requires `task_definition_id`, optional `value` and `scheduled_time`. Goal progress uses `points`, `occurred_on`, optional `note`. Location accepts latitude, longitude, location_accuracy, city and suburb. Definition forms use `options_text`, `scheduled_times_text`, `weekdays` or `month_days_text`; goals use `task_definition_id` and `github_projects_text`.

`target_id` and `data.task_definition_id` can refer to an earlier create operation's UUID for the same account. Send operations in dependency order. The server serializes mobile operations per user and runs each operation in its own database transaction, including its idempotency receipt. Clients should not send generic note models or arbitrary Eloquent fields.

## Online actions

- `POST /refresh-day/{YYYY-MM-DD}`: same GitHub/calendar refresh and stale browser/desktop finalization used by day viewing on the web.
- `GET /attachments/{id}`: owned generated-image file.
- `GET /models`, `POST /chat/{YYYY-MM-DD}`, `POST /chat-actions/{id}/confirm`: existing AI functionality; actions require explicit user confirmation.
- `POST /settings`: direct settings save for secret API-key changes.
- `POST /profile`, `/password`, `/account/delete`: profile/security operations. Password change and deletion require the existing password.
- `POST /sensors/github`, `/sensors/pair`, `/sensors/google-calendar/sync`.
- `POST /google/connect` → authorization URL; `GET /google/callback` validates a short-lived state and returns to the iPhone custom scheme.
- `GET /admin/users`, `POST /admin/reset-demo`: admin-only.

Existing `/api/sensors/*` routes are unchanged. See `ios/README.md` for timestamp, deletion, storage and foreground-sync details.
