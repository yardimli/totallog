# Total Log Desktop

This is the Tauri 2 + Rust desktop logging app for Total Log. Version 0.6 adds:

- a daily timeline, text entries, recurring events and event definitions;
- calendar navigation, local history search, goals and progress history;
- account preferences, custom icons, local screensavers, API usage and sensor management;
- online AI chat with explicit confirmation of proposed changes, profile/password settings and GitHub linking;
- account-scoped SQLite storage and a durable offline mutation outbox;
- immediate upload after edits, five-minute foreground refresh and hourly background refresh;
- incremental server downloads, visible rejected/conflicting edits, and offline image viewing after the first download.

The Notes workspace is intentionally excluded. Account deletion and shared demo reset controls are not included.
Google authorization and account registration/password recovery open the website in the browser.

**Deploy the server changes and review pending migrations before running `php artisan migrate` on the server.**
The new `desktop_sync_versions` table enables cheap unchanged polls. See [desktop sync details](../docs/desktop-sync.md).

Sign in using your Total Log account at `https://total-log.com/`. The first download needs a connection;
afterward, logging and browsing downloaded history work offline. Tracker pairing remains separate under
**Desktop tracker → Connection settings**. The existing tracker still provides:

- foreground Windows application, executable path, and window-title detection;
- Windows idle-time detection;
- a tray icon that keeps monitoring after the settings window closes;
- dedicated desktop-sensor pairing with the Laravel application;
- local foreground-application sampling with aggregated foreground/background uploads;
- per-application totals for the last five minutes, last hour, and today;
- a dedicated Kindle Cloud Reader window whose Amazon session remains in WebView2;
- manual Kindle library capture that logs the first displayed book's title and author without collecting reading position;
- a loopback-only bridge for the optional browser extension, which forwards exact browser domains and synchronized mobile Chrome history using the desktop app's pairing key.

The browser bridge checks Windows foreground/idle state before forwarding live
browsing. Desktop samples stop at idle transitions and skip sleep/resume gaps.
Uploads retain the same payload after a lost response or restart. The server
detects exact duplicates using existing activity records; this fix requires no
new tables or migrations. Deploy the server code before the desktop update. If the server
or pairing changes while a batch is pending, restore that pairing to complete it.
See [hourly repair instructions](../docs/sensor-repair.md) for existing duplicate
reports and impossible browser durations.

## Windows prerequisites

1. In **Visual Studio Installer**, modify your Visual Studio installation and select **Desktop development with C++**. Keep the MSVC build tools and Windows 10/11 SDK components selected.
2. Install Rust with `winget install --id Rustlang.Rustup`, restart PowerShell, then run `rustup default stable-msvc`.
3. WebView2 is normally already installed on current Windows 10 and Windows 11 systems. If it is missing, install the Microsoft Edge WebView2 Evergreen Runtime.
4. For MSI builds, ensure the Windows optional feature **VBSCRIPT** is enabled.

## Develop

From this `desktop` directory:

```powershell
npm install
npm run desktop:dev
```

The first Rust build downloads crates and takes longer. Closing the window hides it; use the tray menu to reopen or quit it.

## Compile installers

```powershell
npm run desktop:build
```

The executable and installers are written below `src-tauri\target\release`. Tauri generates NSIS `.exe` and WiX `.msi` installers; it does not currently generate MSIX packages directly.

Run the Laravel migration before pairing a desktop client:

```powershell
php artisan migrate
```

For desktop activity, only the friendly application name, executable filename, client ID, observation time, and accumulated duration are sent. Full executable paths and window titles remain local. Amazon credentials and cookie values stay inside the app's WebView2 profile; Kindle uploads contain only title, author, ASIN, percentage or location, client ID, and observation time.

The app name, bundle identifier, publisher, icons, and signing configuration in `src-tauri\tauri.conf.json` are development placeholders. Replace them with the final reserved product identity before publishing.
