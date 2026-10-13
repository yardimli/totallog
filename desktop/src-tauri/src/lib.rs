mod activity;
mod browser_bridge;

use browser_bridge::{BridgeConfig, BrowserBridge};
use serde::{Deserialize, Serialize};
use std::{
    sync::atomic::{AtomicBool, Ordering},
    thread,
    time::Duration,
};
use tauri::{
    menu::{Menu, MenuItem},
    tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent},
    Emitter, Manager, WebviewUrl, WebviewWindow, WebviewWindowBuilder, WindowEvent,
};

const DEFAULT_KINDLE_URL: &str = "https://read.amazon.com/kindle-library";
const AMAZON_DOMAINS: [&str; 13] = [
    "amazon.com",
    "amazon.ca",
    "amazon.co.uk",
    "amazon.com.au",
    "amazon.de",
    "amazon.fr",
    "amazon.es",
    "amazon.it",
    "amazon.co.jp",
    "amazon.in",
    "amazon.com.br",
    "amazon.com.mx",
    "amazon.nl",
];

#[tauri::command]
fn current_activity() -> activity::ActivitySnapshot {
    activity::capture()
}

#[derive(Deserialize)]
struct BrowserBridgeConfig {
    app_url: String,
    pairing_key: String,
}

#[tauri::command]
fn configure_browser_bridge(
    bridge: tauri::State<'_, BrowserBridge>,
    payload: BrowserBridgeConfig,
) -> Result<(), String> {
    total_log_url(&payload.app_url)?;
    if !(32..=128).contains(&payload.pairing_key.len())
        || !payload
            .pairing_key
            .chars()
            .all(|character| character.is_ascii_alphanumeric() || matches!(character, '_' | '-'))
    {
        return Err("The desktop pairing key is not valid.".into());
    }
    bridge.configure(BridgeConfig {
        app_url: payload.app_url,
        pairing_key: payload.pairing_key,
    });
    Ok(())
}

#[tauri::command]
async fn check_server_connection(payload: BrowserBridgeConfig) -> Result<UploadResult, String> {
    let base = total_log_url(&payload.app_url)?;
    let endpoint = base
        .join("api/sensors/desktop/check")
        .map_err(|error| error.to_string())?;
    let response = reqwest::Client::new()
        .get(endpoint)
        .header("Accept", "application/json")
        .header("X-TotalLog-Key", payload.pairing_key)
        .send()
        .await
        .map_err(|error| format!("Could not reach Total Log: {error}"))?;
    let status = response.status();
    if !status.is_success() {
        return Err(if status.as_u16() == 401 {
            "Desktop sensor is not paired yet. Finish pairing in your browser.".into()
        } else {
            format!("Total Log connection check returned {}.", status.as_u16())
        });
    }

    Ok(UploadResult {
        status: status.as_u16(),
    })
}

#[derive(Deserialize)]
struct ActivityUpload {
    app_url: String,
    pairing_key: String,
    client_id: String,
    application: String,
    process_name: String,
    observed_at: String,
}

#[derive(Deserialize, Serialize)]
struct ActivityIncrement {
    application: String,
    process_name: String,
    started_at: String,
    ended_at: String,
    duration_seconds: u32,
}

#[derive(Deserialize)]
struct ActivityBatchUpload {
    app_url: String,
    pairing_key: String,
    client_id: String,
    activities: Vec<ActivityIncrement>,
}

#[derive(Serialize)]
struct UploadResult {
    status: u16,
}

#[derive(Clone, Deserialize, Serialize)]
struct KindleBook {
    title: String,
    author: Option<String>,
    asin: Option<String>,
}

#[derive(Default)]
struct KindleSyncState(AtomicBool);

#[derive(Clone, Deserialize, Serialize)]
struct KindleStatusReport {
    status: String,
    message: Option<String>,
}

#[derive(Deserialize)]
struct KindleUpload {
    app_url: String,
    pairing_key: String,
    client_id: String,
    book: KindleBook,
    observed_at: String,
}

#[tauri::command]
async fn send_activity(payload: ActivityUpload) -> Result<UploadResult, String> {
    let base = total_log_url(&payload.app_url)?;
    let endpoint = base
        .join("api/sensors/desktop/activity")
        .map_err(|error| error.to_string())?;
    let response = reqwest::Client::new()
        .post(endpoint)
        .header("Accept", "application/json")
        .header("X-TotalLog-Key", payload.pairing_key)
        .json(&serde_json::json!({
            "application": payload.application,
            "process_name": payload.process_name,
            "client_id": payload.client_id,
            "observed_at": payload.observed_at,
        }))
        .send()
        .await
        .map_err(|error| format!("Could not reach Total Log: {error}"))?;
    let status = response.status();
    if !status.is_success() {
        let body = response.text().await.unwrap_or_default();
        return Err(if status.as_u16() == 401 {
            "Desktop sensor is not paired yet. Finish pairing in your browser.".into()
        } else {
            format!("Total Log returned {}: {}", status.as_u16(), body)
        });
    }

    Ok(UploadResult {
        status: status.as_u16(),
    })
}

#[tauri::command]
async fn send_activity_batch(payload: ActivityBatchUpload) -> Result<UploadResult, String> {
    if payload.activities.is_empty() {
        return Err("There is no desktop activity to upload yet.".into());
    }
    let base = total_log_url(&payload.app_url)?;
    let endpoint = base
        .join("api/sensors/desktop/activity")
        .map_err(|error| error.to_string())?;
    let response = reqwest::Client::new()
        .post(endpoint)
        .header("Accept", "application/json")
        .header("X-TotalLog-Key", payload.pairing_key)
        .json(&serde_json::json!({
            "client_id": payload.client_id,
            "activities": payload.activities,
        }))
        .send()
        .await
        .map_err(|error| format!("Could not reach Total Log: {error}"))?;
    let status = response.status();
    if !status.is_success() {
        let body = response.text().await.unwrap_or_default();
        return Err(if status.as_u16() == 401 {
            "Desktop sensor is not paired yet. Finish pairing in your browser.".into()
        } else {
            format!("Total Log returned {}: {}", status.as_u16(), body)
        });
    }

    Ok(UploadResult {
        status: status.as_u16(),
    })
}

#[tauri::command]
async fn send_kindle_book(payload: KindleUpload) -> Result<UploadResult, String> {
    let base = total_log_url(&payload.app_url)?;
    let endpoint = base
        .join("api/sensors/kindle/book")
        .map_err(|error| error.to_string())?;
    let response = reqwest::Client::new()
        .post(endpoint)
        .header("Accept", "application/json")
        .header("X-TotalLog-Key", payload.pairing_key)
        .json(&serde_json::json!({
            "title": payload.book.title,
            "author": payload.book.author,
            "asin": payload.book.asin,
            "client_id": payload.client_id,
            "observed_at": payload.observed_at,
        }))
        .send()
        .await
        .map_err(|error| format!("Could not reach Total Log: {error}"))?;
    let status = response.status();
    if !status.is_success() {
        let body = response.text().await.unwrap_or_default();
        return Err(if status.as_u16() == 401 {
            "Desktop sensor is not paired yet. Finish pairing before syncing Kindle.".into()
        } else {
            format!("Total Log returned {}: {}", status.as_u16(), body)
        });
    }

    Ok(UploadResult {
        status: status.as_u16(),
    })
}

#[tauri::command]
async fn open_kindle(app: tauri::AppHandle) -> Result<(), String> {
    let url = kindle_library_url(DEFAULT_KINDLE_URL)?;
    if let Some(window) = app.get_webview_window("kindle") {
        window.navigate(url).map_err(|error| error.to_string())?;
        window.unminimize().map_err(|error| error.to_string())?;
        window.show().map_err(|error| error.to_string())?;
        window.set_focus().map_err(|error| error.to_string())?;
        return Ok(());
    }
    create_kindle_window(&app, url, true)?;
    Ok(())
}

#[tauri::command]
async fn sync_kindle(
    app: tauri::AppHandle,
    state: tauri::State<'_, KindleSyncState>,
) -> Result<(), String> {
    let url = kindle_library_url(DEFAULT_KINDLE_URL)?;
    state.0.store(true, Ordering::SeqCst);
    if let Some(window) = app.get_webview_window("kindle") {
        window.unminimize().map_err(|error| error.to_string())?;
        window.show().map_err(|error| error.to_string())?;
        window.set_focus().map_err(|error| error.to_string())?;
        window.navigate(url).map_err(|error| error.to_string())?;
        return Ok(());
    }
    create_kindle_window(&app, url, true)?;
    Ok(())
}

#[tauri::command]
fn kindle_manual_sync_ready(
    state: tauri::State<'_, KindleSyncState>,
    webview: WebviewWindow,
) -> Result<bool, String> {
    validate_kindle_sender(&webview)?;
    Ok(state.0.swap(false, Ordering::SeqCst))
}

#[tauri::command]
fn kindle_book_observed(
    app: tauri::AppHandle,
    webview: WebviewWindow,
    book: KindleBook,
) -> Result<(), String> {
    validate_kindle_sender(&webview)?;
    app.emit_to("main", "kindle-book", book)
        .map_err(|error| error.to_string())
}

#[tauri::command]
fn kindle_status_observed(
    app: tauri::AppHandle,
    webview: WebviewWindow,
    report: KindleStatusReport,
) -> Result<(), String> {
    validate_kindle_sender(&webview)?;
    app.emit_to("main", "kindle-status", report)
        .map_err(|error| error.to_string())
}

pub fn run() {
    let browser_bridge = BrowserBridge::default();
    let setup_bridge = browser_bridge.clone();
    tauri::Builder::default()
        .manage(browser_bridge)
        .manage(KindleSyncState::default())
        .plugin(tauri_plugin_opener::init())
        .invoke_handler(tauri::generate_handler![
            current_activity,
            configure_browser_bridge,
            check_server_connection,
            send_activity,
            send_activity_batch,
            send_kindle_book,
            open_kindle,
            sync_kindle,
            kindle_manual_sync_ready,
            kindle_book_observed,
            kindle_status_observed,
        ])
        .setup(move |app| {
            setup_bridge.start(app.handle().clone());
            let show = MenuItem::with_id(app, "show", "Open Total Log", true, None::<&str>)?;
            let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&show, &quit])?;

            TrayIconBuilder::new()
                .icon(
                    app.default_window_icon()
                        .cloned()
                        .expect("application icon"),
                )
                .menu(&menu)
                .show_menu_on_left_click(false)
                .on_menu_event(|app, event| match event.id().as_ref() {
                    "show" => show_main_window(app),
                    "quit" => app.exit(0),
                    _ => {}
                })
                .on_tray_icon_event(|tray, event| {
                    if let TrayIconEvent::Click {
                        button: MouseButton::Left,
                        button_state: MouseButtonState::Up,
                        ..
                    } = event
                    {
                        show_main_window(tray.app_handle());
                    }
                })
                .build(app)?;

            let app_handle = app.handle().clone();
            thread::spawn(move || loop {
                let _ = app_handle.emit("activity-update", activity::capture());
                thread::sleep(Duration::from_secs(2));
            });
            Ok(())
        })
        .on_window_event(|window, event| {
            if let WindowEvent::CloseRequested { api, .. } = event {
                let _ = window.hide();
                api.prevent_close();
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running Total Log Desktop");
}

fn show_main_window(app: &tauri::AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.unminimize();
        let _ = window.show();
        let _ = window.set_focus();
    }
}

fn total_log_url(value: &str) -> Result<url::Url, String> {
    let mut url = url::Url::parse(value).map_err(|error| error.to_string())?;
    if !matches!(url.scheme(), "http" | "https") {
        return Err("Use an http:// or https:// Total Log URL.".into());
    }
    url.set_query(None);
    url.set_fragment(None);
    Ok(url)
}

fn kindle_library_url(value: &str) -> Result<url::Url, String> {
    let url = url::Url::parse(if value.trim().is_empty() {
        DEFAULT_KINDLE_URL
    } else {
        value
    })
    .map_err(|error| error.to_string())?;
    let host = url.host_str().unwrap_or_default();
    if url.scheme() != "https" || !is_read_amazon_host(host) {
        return Err("Use a Kindle Cloud Reader URL beginning with https://read.amazon.".into());
    }
    Ok(url)
}

fn create_kindle_window(
    app: &tauri::AppHandle,
    url: url::Url,
    visible: bool,
) -> Result<(), String> {
    let navigation_app = app.clone();
    WebviewWindowBuilder::new(app, "kindle", WebviewUrl::External(url))
        .title("Kindle Cloud Reader · Total Log")
        .inner_size(1180.0, 800.0)
        .min_inner_size(720.0, 540.0)
        .center()
        .visible(visible)
        .initialization_script_for_all_frames(include_str!("kindle.js"))
        .on_navigation(move |url| {
            let allowed = url.scheme() == "https" && url.host_str().is_some_and(is_amazon_host);
            if allowed && url.path().contains("/ap/signin") {
                let _ = navigation_app.emit_to(
                    "main",
                    "kindle-status",
                    KindleStatusReport {
                        status: "expired".into(),
                        message: Some(
                            "Sign in to Amazon in the Kindle window, then choose Sync now.".into(),
                        ),
                    },
                );
            }
            allowed
        })
        .build()
        .map(|_| ())
        .map_err(|error| error.to_string())
}

fn validate_kindle_sender(webview: &WebviewWindow) -> Result<(), String> {
    let url = webview.url().map_err(|error| error.to_string())?;
    if webview.label() != "kindle"
        || url.scheme() != "https"
        || !url.host_str().is_some_and(is_read_amazon_host)
    {
        return Err(
            "Kindle reports are only accepted from the dedicated Cloud Reader window.".into(),
        );
    }
    Ok(())
}

fn is_read_amazon_host(host: &str) -> bool {
    host.to_ascii_lowercase().starts_with("read.amazon.") && is_amazon_host(host)
}

fn is_amazon_host(host: &str) -> bool {
    let host = host.to_ascii_lowercase();
    AMAZON_DOMAINS
        .iter()
        .any(|domain| host == *domain || host.ends_with(&format!(".{domain}")))
}
