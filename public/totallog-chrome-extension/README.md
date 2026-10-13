# Total Log Browsing Sensor

1. Open `chrome://extensions` in Chrome.
2. Enable **Developer mode**.
3. Select **Load unpacked** and choose this `totallog-chrome-extension` folder.
4. Pair Total Log Desktop with your account and leave it running.
5. Open the extension settings and select **Check desktop app**.

The extension connects only to the local bridge exposed by Total Log Desktop at `http://127.0.0.1:32145/`. It has no server URL or pairing key of its own. If the desktop app is unavailable, Chrome displays a red `!` badge on the extension icon and the settings page reports that the desktop app is not running.

The extension sends only the active HTTP(S) hostname to the desktop app when the active tab changes and once per minute while Chrome is active. The desktop app forwards it using its own server pairing. Page paths, titles, query strings, and ports never leave the extension. Total Log stores the complete hostname—including subdomains such as `docs.github.com` or `mail.google.com`—and elapsed time in hourly browsing blocks. A session is considered finished after three minutes without a heartbeat.

Version 2.0.2 requires the browser window to have focus and not be minimized, in
addition to Chrome's idle check. Background tabs and simultaneous event triggers
do not produce extra heartbeats. The updated desktop bridge independently checks
Windows foreground/idle state before forwarding activity. Reload the extension
after updating its files, and install the updated desktop app for both checks.

When Chrome **History and tabs** sync is enabled, the extension also scans history synchronized from other devices every five minutes. It sends only the hostname, visit time, and a one-way deduplication hash. Total Log groups these visits into separate **Mobile browsing** blocks by hour and counts visits per domain. Chrome identifies them only as non-local visits, so history from multiple remote devices cannot be separated.

Use **Sync past data** in the extension settings to ignore the incremental cursor and rescan the complete history range Chrome still retains. The scan pages through the available history index and the server safely ignores visits it has already imported.

The **Debug** tab contains a paginated raw-history inspector and up to 1,000 extension diagnostic messages. The history view shows each visit's full URL, timestamp, transition, and `isLocal` value locally so sync and classification problems can be diagnosed. **Copy report** copies the current status, history snapshot, and messages as JSON; full URLs in that report should be treated as private.

IP-address and other non-domain history entries are skipped individually and reported as skipped; they never stop the remaining batches. Visits are sent newest-first so recent daily logs are filled before older history.
