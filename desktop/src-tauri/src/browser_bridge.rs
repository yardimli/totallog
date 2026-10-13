use std::{
    io::Read,
    sync::{Arc, Mutex},
    thread,
};

use serde::Serialize;
use tauri::Emitter;
use tiny_http::{Header, Method, Request, Response, Server, StatusCode};

pub const ADDRESS: &str = "127.0.0.1:32145";

#[derive(Clone)]
pub struct BridgeConfig {
    pub app_url: String,
    pub pairing_key: String,
}

#[derive(Clone, Default)]
pub struct BrowserBridge {
    config: Arc<Mutex<Option<BridgeConfig>>>,
}

#[derive(Serialize)]
struct HealthResponse {
    service: &'static str,
    protocol: u8,
    configured: bool,
}

#[derive(Clone, Serialize)]
struct BrowserExtensionReport {
    status: &'static str,
    message: String,
    received_at_unix_ms: u128,
}

impl BrowserBridge {
    pub fn configure(&self, config: BridgeConfig) {
        *self
            .config
            .lock()
            .expect("browser bridge configuration lock") = Some(config);
    }

    pub fn start(&self, app: tauri::AppHandle) {
        let bridge = self.clone();
        thread::spawn(move || {
            let Ok(server) = Server::http(ADDRESS) else {
                return;
            };
            for request in server.incoming_requests() {
                bridge.handle(&app, request);
            }
        });
    }

    fn handle(&self, app: &tauri::AppHandle, mut request: Request) {
        let origin_allowed = request
            .headers()
            .iter()
            .find(|header| header.field.equiv("Origin"))
            .is_some_and(|header| header.value.as_str().starts_with("chrome-extension://"));
        let extension_marked = request
            .headers()
            .iter()
            .find(|header| header.field.equiv("X-TotalLog-Extension"))
            .is_some_and(|header| header.value.as_str() == "1");

        if request.method() == &Method::Options {
            if origin_allowed {
                respond_json(request, 204, "");
            } else {
                respond_json(
                    request,
                    403,
                    r#"{"message":"Only the Total Log browser extension may use this bridge."}"#,
                );
            }
            return;
        }

        if request.method() == &Method::Get && request.url() == "/health" {
            let configured = self
                .config
                .lock()
                .expect("browser bridge configuration lock")
                .is_some();
            let body = serde_json::to_string(&HealthResponse {
                service: "total-log-desktop",
                protocol: 1,
                configured,
            })
            .expect("health response serializes");
            if origin_allowed || extension_marked {
                report_extension(
                    app,
                    "connected",
                    if configured {
                        "Browser extension connected to the desktop app."
                    } else {
                        "Browser extension connected. Pair the desktop app to forward its data."
                    },
                );
            }
            respond_json(request, 200, &body);
            return;
        }

        if !origin_allowed && !extension_marked {
            respond_json(
                request,
                403,
                r#"{"message":"Only the Total Log browser extension may use this bridge."}"#,
            );
            return;
        }

        let server_path = match (request.method(), request.url()) {
            (&Method::Post, "/v1/browser/activity") => "api/sensors/browser/activity",
            (&Method::Post, "/v1/browser/mobile-history") => "api/sensors/browser/mobile-history",
            _ => {
                respond_json(
                    request,
                    404,
                    r#"{"message":"Unknown desktop bridge endpoint."}"#,
                );
                return;
            }
        };

        let Some(config) = self
            .config
            .lock()
            .expect("browser bridge configuration lock")
            .clone()
        else {
            respond_json(
                request,
                503,
                r#"{"message":"The desktop app is running but is not paired with Total Log."}"#,
            );
            return;
        };

        let mut body = String::new();
        if request
            .as_reader()
            .take(2_000_000)
            .read_to_string(&mut body)
            .is_err()
        {
            respond_json(
                request,
                400,
                r#"{"message":"Could not read the extension request."}"#,
            );
            return;
        }

        let data_name = if server_path.ends_with("mobile-history") {
            "mobile browsing history"
        } else {
            "active browser data"
        };
        if server_path.ends_with("/activity")
            && !crate::activity::browser_tracking_allowed(&crate::activity::capture())
        {
            respond_json(request, 200, r#"{"skipped":true,"message":"Browser is not foreground or the computer is idle."}"#);
            return;
        }
        let result = tauri::async_runtime::block_on(forward(config, server_path, body));
        match result {
            Ok((status, response_body)) => {
                if (200..300).contains(&status) {
                    report_extension(
                        app,
                        "receiving",
                        &format!("Received and forwarded {data_name}."),
                    );
                } else {
                    report_extension(
                        app,
                        "error",
                        &format!("Extension is connected, but Total Log rejected {data_name}."),
                    );
                }
                respond_json(request, status, &response_body)
            }
            Err(message) => {
                report_extension(
                    app,
                    "error",
                    &format!(
                        "Extension is connected, but its data could not be forwarded: {message}"
                    ),
                );
                respond_json(
                    request,
                    502,
                    &serde_json::json!({"message": message}).to_string(),
                )
            }
        }
    }
}

fn report_extension(app: &tauri::AppHandle, status: &'static str, message: &str) {
    let received_at_unix_ms = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis();
    let _ = app.emit(
        "browser-extension-status",
        BrowserExtensionReport {
            status,
            message: message.into(),
            received_at_unix_ms,
        },
    );
}

async fn forward(config: BridgeConfig, path: &str, body: String) -> Result<(u16, String), String> {
    let base = url::Url::parse(&config.app_url).map_err(|error| error.to_string())?;
    let endpoint = base.join(path).map_err(|error| error.to_string())?;
    let response = reqwest::Client::new()
        .post(endpoint)
        .header("Accept", "application/json")
        .header("Content-Type", "application/json")
        .header("X-TotalLog-Key", config.pairing_key)
        .body(body)
        .send()
        .await
        .map_err(|error| format!("Desktop app could not reach Total Log: {error}"))?;
    let status = response.status().as_u16();
    let body = response.text().await.unwrap_or_default();
    Ok((status, body))
}

fn respond_json(request: Request, status: u16, body: &str) {
    let mut response = Response::from_string(body)
        .with_status_code(StatusCode(status))
        .with_header(
            Header::from_bytes("Content-Type", "application/json; charset=utf-8")
                .expect("valid header"),
        )
        .with_header(Header::from_bytes("Access-Control-Allow-Origin", "*").expect("valid header"))
        .with_header(
            Header::from_bytes("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
                .expect("valid header"),
        )
        .with_header(
            Header::from_bytes(
                "Access-Control-Allow-Headers",
                "Content-Type, X-TotalLog-Extension",
            )
            .expect("valid header"),
        );
    if status == 204 {
        response = response.with_status_code(StatusCode(204));
    }
    let _ = request.respond(response);
}
