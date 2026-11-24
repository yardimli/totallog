//! Account-scoped SQLite cache and durable, immutable mutation outbox.
use rusqlite::{params, Connection, OptionalExtension};
use serde_json::{json, Value};
use std::{
    path::Path,
    sync::Mutex,
    time::{Duration, SystemTime, UNIX_EPOCH},
};
use tauri::{Emitter, Manager};

pub struct Logging {
    db: Mutex<Connection>,
    sync_gate: tokio::sync::Mutex<()>,
    client: reqwest::Client,
}

fn err(e: impl std::fmt::Display) -> String {
    e.to_string()
}
fn now() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis() as i64
}

impl Logging {
    pub fn open(path: &Path) -> Result<Self, String> {
        let db = Connection::open(path).map_err(err)?;
        db.execute_batch("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;
            CREATE TABLE IF NOT EXISTS accounts (
                id TEXT PRIMARY KEY, server TEXT NOT NULL, user_id INTEGER NOT NULL,
                token BLOB NOT NULL, active INTEGER NOT NULL DEFAULT 0,
                cursor TEXT, last_attempt INTEGER NOT NULL DEFAULT 0,
                last_sync INTEGER NOT NULL DEFAULT 0, clock_offset INTEGER NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT '');
            CREATE TABLE IF NOT EXISTS records (
                account TEXT NOT NULL, collection TEXT NOT NULL, id TEXT NOT NULL, value TEXT NOT NULL,
                PRIMARY KEY(account, collection, id));
            CREATE TABLE IF NOT EXISTS outbox (
                seq INTEGER PRIMARY KEY AUTOINCREMENT, account TEXT NOT NULL,
                id TEXT NOT NULL UNIQUE, operation TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending', receipt TEXT);
            CREATE INDEX IF NOT EXISTS outbox_account ON outbox(account, seq);
            CREATE TABLE IF NOT EXISTS attachment_cache (account TEXT NOT NULL,id INTEGER NOT NULL,mime TEXT NOT NULL,data BLOB NOT NULL,PRIMARY KEY(account,id));
            CREATE TABLE IF NOT EXISTS aliases (account TEXT NOT NULL,operation_id TEXT NOT NULL,entity_id INTEGER NOT NULL,PRIMARY KEY(account,operation_id));
            PRAGMA user_version=1;").map_err(err)?;
        Ok(Self {
            db: Mutex::new(db),
            sync_gate: tokio::sync::Mutex::new(()),
            client: reqwest::Client::builder()
                .timeout(Duration::from_secs(60))
                .redirect(reqwest::redirect::Policy::none())
                .build()
                .map_err(err)?,
        })
    }
    fn session(&self) -> Result<(String, String, String), String> {
        let db = self.db.lock().map_err(err)?;
        let (id, server, token): (String, String, Vec<u8>) = db
            .query_row(
                "SELECT id,server,token FROM accounts WHERE active=1",
                [],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)),
            )
            .map_err(|_| "Sign in to your Total Log account first.")?;
        Ok((
            id,
            server,
            String::from_utf8(unprotect(&token)?).map_err(err)?,
        ))
    }
    async fn request(
        &self,
        server: &str,
        token: Option<&str>,
        path: &str,
        data: Option<&Value>,
    ) -> Result<Value, String> {
        let endpoint = format!("{server}api/mobile/{path}");
        let mut request = if let Some(body) = data {
            self.client.post(endpoint).json(body)
        } else {
            self.client.get(endpoint)
        };
        request = request.header("Accept", "application/json");
        if let Some(token) = token {
            request = request.bearer_auth(token);
        }
        let response = request
            .send()
            .await
            .map_err(|_| "Cannot reach the server. Your edits are saved on this PC.".to_string())?;
        let status = response.status();
        let body: Value = response.json().await.map_err(|_| {
            format!("Server returned {status}. Deploy the desktop sync API before connecting.")
        })?;
        if !status.is_success() {
            return Err(format!(
                "{}: {}",
                status.as_u16(),
                body["message"].as_str().unwrap_or("Request failed")
            ));
        }
        Ok(body)
    }
    fn view(&self) -> Result<Value, String> {
        let db = self.db.lock().map_err(err)?;
        let account: Option<(String, String, i64, i64, i64, String)> = db.query_row(
            "SELECT id,server,last_sync,clock_offset,user_id,error FROM accounts WHERE active=1", [],
            |r| Ok((r.get(0)?,r.get(1)?,r.get(2)?,r.get(3)?,r.get(4)?,r.get(5)?))).optional().map_err(err)?;
        let Some((id, server, last_sync, clock_offset, user_id, error)) = account else {
            return Ok(json!({"account":null}));
        };
        let mut aliases=serde_json::Map::new();
        let mut alias_statement=db.prepare("SELECT operation_id,entity_id FROM aliases WHERE account=?").map_err(err)?;
        for alias in alias_statement.query_map([&id],|r|Ok((r.get::<_,String>(0)?,r.get::<_,i64>(1)?))).map_err(err)? {
            let (key,value)=alias.map_err(err)?;aliases.insert(key,json!(value));
        }
        let mut snapshot = serde_json::Map::new();
        let mut stmt = db
            .prepare("SELECT collection,value FROM records WHERE account=? ORDER BY collection,id")
            .map_err(err)?;
        for row in stmt
            .query_map([&id], |r| {
                Ok((r.get::<_, String>(0)?, r.get::<_, String>(1)?))
            })
            .map_err(err)?
        {
            let (collection, value) = row.map_err(err)?;
            let value: Value = serde_json::from_str(&value).map_err(err)?;
            if ["user", "screensaver_logo", "schema_version"].contains(&collection.as_str()) {
                snapshot.insert(collection, value);
            } else {
                snapshot
                    .entry(collection)
                    .or_insert(json!([]))
                    .as_array_mut()
                    .unwrap()
                    .push(value);
            }
        }
        let mut stmt = db
            .prepare("SELECT operation,status,receipt FROM outbox WHERE account=? ORDER BY seq")
            .map_err(err)?;
        let mut operations = vec![];
        for row in stmt
            .query_map([&id], |r| {
                Ok((
                    r.get::<_, String>(0)?,
                    r.get::<_, String>(1)?,
                    r.get::<_, Option<String>>(2)?,
                ))
            })
            .map_err(err)?
        {
            let (op, status, receipt) = row.map_err(err)?;
            operations.push(
                json!({"operation":serde_json::from_str::<Value>(&op).map_err(err)?,"status":status,
                "receipt":receipt.and_then(|v| serde_json::from_str::<Value>(&v).ok())}),
            );
        }
        Ok(
            json!({"account":{"id":id,"server":server,"user_id":user_id},"snapshot":snapshot,
            "operations":operations,"aliases":aliases,"last_sync":last_sync,"clock_offset":clock_offset,"error":error}),
        )
    }
    async fn sync(&self) -> Result<(), String> {
        // A user edit arriving during a pull must run immediately after it, not wait for a timer.
        let _gate = self.sync_gate.lock().await;
        let (account, server, token) = self.session()?;
        self.db
            .lock()
            .map_err(err)?
            .execute(
                "UPDATE accounts SET last_attempt=? WHERE id=?",
                params![now(), account],
            )
            .map_err(err)?;
        let result = self.sync_account(&account, &server, &token).await;
        self.db
            .lock()
            .map_err(err)?
            .execute(
                "UPDATE accounts SET error=? WHERE id=?",
                params![result.as_ref().err().cloned().unwrap_or_default(), account],
            )
            .map_err(err)?;
        result
    }
    async fn sync_account(&self, account: &str, server: &str, token: &str) -> Result<(), String> {
        // Bound each request, preserve creation/dependency order, and stop at a rejected dependency.
        loop {
            let operations = {
                let db = self.db.lock().map_err(err)?;
                let mut stmt = db.prepare("SELECT operation FROM outbox WHERE account=? AND status='pending' ORDER BY seq LIMIT 100").map_err(err)?;
                let values = stmt
                    .query_map([account], |r| r.get::<_, String>(0))
                    .map_err(err)?
                    .map(|v| serde_json::from_str::<Value>(&v.map_err(err)?).map_err(err))
                    .collect::<Result<Vec<_>, _>>()?;
                values
            };
            if operations.is_empty() {
                break;
            }
            let response = self
                .request(
                    server,
                    Some(token),
                    "sync",
                    Some(&json!({"operations":operations})),
                )
                .await?;
            let receipts = response["results"]
                .as_array()
                .ok_or("Invalid sync receipt.")?;
            if receipts.len() != operations.len() {
                return Err("Incomplete sync receipts; edits retained for retry.".into());
            }
            let mut db = self.db.lock().map_err(err)?;
            let tx = db.transaction().map_err(err)?;
            for receipt in receipts {
                let id = receipt["id"].as_str().ok_or("Missing receipt ID")?;
                let status = receipt["status"].as_str().ok_or("Missing receipt status")?;
                if !["applied", "conflict", "rejected"].contains(&status)
                    || !operations.iter().any(|op| op["id"] == id)
                {
                    return Err("Unexpected sync receipt; edits retained.".into());
                }
                tx.execute(
                    "UPDATE outbox SET status=?,receipt=? WHERE account=? AND id=?",
                    params![status, receipt.to_string(), account, id],
                )
                .map_err(err)?;
                if status=="applied" && operations.iter().any(|op|op["id"]==id && op["kind"].as_str().is_some_and(|kind|kind.ends_with(".create"))) {
                    if let Some(entity_id)=receipt["entity_id"].as_i64() {
                        tx.execute("INSERT OR REPLACE INTO aliases VALUES(?,?,?)",params![account,id,entity_id]).map_err(err)?;
                    }
                }
            }
            tx.commit().map_err(err)?;
        }
        let cursor: Option<String> = self
            .db
            .lock()
            .map_err(err)?
            .query_row("SELECT cursor FROM accounts WHERE id=?", [account], |r| {
                r.get(0)
            })
            .map_err(err)?;
        let started = now();
        let delta = self
            .request(
                server,
                Some(token),
                "desktop/pull",
                Some(&json!({"cursor":cursor})),
            )
            .await?;
        let expected: i64 = self
            .db
            .lock()
            .map_err(err)?
            .query_row("SELECT user_id FROM accounts WHERE id=?", [account], |r| {
                r.get(0)
            })
            .map_err(err)?;
        if delta["user_id"].as_i64() != Some(expected) || delta["schema_version"] != 1 {
            return Err(
                "The server returned a different account or unsupported sync format.".into(),
            );
        }
        let cursor = delta["cursor"].as_str().ok_or("Missing sync cursor")?;
        let changes = delta["changes"].as_array().ok_or("Missing sync changes")?;
        let deleted = delta["deleted"]
            .as_array()
            .ok_or("Missing sync deletions")?;
        let mut db = self.db.lock().map_err(err)?;
        let tx = db.transaction().map_err(err)?;
        if delta["reset"] == true {
            tx.execute("DELETE FROM records WHERE account=?", [account])
                .map_err(err)?;
        }
        for row in changes {
            tx.execute("INSERT INTO records(account,collection,id,value) VALUES(?,?,?,?) ON CONFLICT(account,collection,id) DO UPDATE SET value=excluded.value",
                params![account,row["collection"].as_str().ok_or("Missing collection")?,row["id"].as_str().ok_or("Missing ID")?,row["value"].to_string()]).map_err(err)?;
        }
        for row in deleted {
            tx.execute(
                "DELETE FROM records WHERE account=? AND collection=? AND id=?",
                params![account, row["collection"].as_str(), row["id"].as_str()],
            )
            .map_err(err)?;
        }
        // Receipt removal and the downloaded view commit together. A crash cannot lose an edit.
        tx.execute(
            "DELETE FROM outbox WHERE account=? AND status='applied'",
            [account],
        )
        .map_err(err)?;
        tx.execute(
            "UPDATE accounts SET cursor=?,last_sync=?,error='' WHERE id=?",
            params![cursor, now(), account],
        )
        .map_err(err)?;
        // SQLite parses ISO UTC timestamps without relying on the PC's timezone.
        if let Some(time) = delta["server_time"].as_str() {
            tx.execute("UPDATE accounts SET clock_offset=CAST((julianday(?) - 2440587.5)*86400000 AS INTEGER)-? WHERE id=?",params![time,(started+now())/2,account]).map_err(err)?;
        }
        tx.commit().map_err(err)?;
        Ok(())
    }
}

fn server_url(value: &str) -> Result<String, String> {
    let mut url = url::Url::parse(value).map_err(err)?;
    if url.scheme() != "https"
        && !(url.scheme() == "http"
            && matches!(url.host_str(), Some("localhost" | "127.0.0.1" | "[::1]")))
    {
        return Err("Use HTTPS for your server, or HTTP for localhost.".into());
    }
    if !url.username().is_empty() || url.password().is_some() {
        return Err("Do not put credentials in the server URL.".into());
    }
    url.set_query(None);
    url.set_fragment(None);
    if !url.path().ends_with('/') {
        url.set_path(&format!("{}/", url.path()));
    }
    Ok(url.to_string())
}

#[tauri::command]
pub fn logging_state(state: tauri::State<'_, Logging>) -> Result<Value, String> {
    state.view()
}

#[tauri::command]
pub async fn logging_login(
    state: tauri::State<'_, Logging>,
    server: String,
    email: String,
    password: String,
) -> Result<Value, String> {
    let _gate = state.sync_gate.lock().await;
    let server = server_url(&server)?;
    let response = state
        .request(
            &server,
            None,
            "login",
            Some(&json!({"email":email,"password":password,"device_name":"Total Log Desktop"})),
        )
        .await?;
    let user_id = response["user"]["id"].as_i64().ok_or("Invalid account")?;
    let token = response["token"].as_str().ok_or("Missing account token")?;
    let account = format!("{server}#{user_id}");
    let protected = protect(token.as_bytes())?;
    {
        let mut db = state.db.lock().map_err(err)?;
        let tx = db.transaction().map_err(err)?;
        let pending: i64 = tx.query_row("SELECT COUNT(*) FROM outbox WHERE account IN (SELECT id FROM accounts WHERE active=1 AND id<>?)",[&account],|r| r.get(0)).map_err(err)?;
        if pending > 0 {
            return Err(
                "Sync or resolve the current account's local edits before switching accounts."
                    .into(),
            );
        }
        tx.execute("UPDATE accounts SET active=0", [])
            .map_err(err)?;
        tx.execute("INSERT INTO accounts(id,server,user_id,token,active) VALUES(?,?,?,?,1) ON CONFLICT(id) DO UPDATE SET token=excluded.token,active=1,error=''",params![account,server,user_id,protected]).map_err(err)?;
        tx.commit().map_err(err)?;
    }
    state.view()
}

#[tauri::command]
pub async fn logging_logout(state: tauri::State<'_, Logging>) -> Result<(), String> {
    let _gate = state.sync_gate.lock().await;
    let (account, server, token) = state.session()?;
    let count: i64 = state
        .db
        .lock()
        .map_err(err)?
        .query_row(
            "SELECT COUNT(*) FROM outbox WHERE account=?",
            [&account],
            |r| r.get(0),
        )
        .map_err(err)?;
    if count > 0 {
        return Err("Sync or resolve all saved edits before signing out.".into());
    }
    state
        .request(&server, Some(&token), "logout", Some(&json!({})))
        .await?;
    state
        .db
        .lock()
        .map_err(err)?
        .execute(
            "UPDATE accounts SET active=0,token=X'' WHERE id=?",
            [account],
        )
        .map_err(err)?;
    Ok(())
}

#[tauri::command]
pub fn logging_enqueue(
    state: tauri::State<'_, Logging>,
    account: String,
    operation: Value,
) -> Result<Value, String> {
    let (current, _, _) = state.session()?;
    if current != account {
        return Err("Account changed. Reopen this editor before saving.".into());
    }
    let kind = operation["kind"].as_str().ok_or("Missing operation kind")?;
    if ![
        "blocks.create",
        "blocks.update",
        "blocks.delete",
        "blocks.visibility",
        "events.create",
        "events.update",
        "events.location",
        "tasks.create",
        "tasks.update",
        "tasks.delete",
        "tasks.position",
        "goals.create",
        "goals.update",
        "goals.delete",
        "goals.progress",
        "settings.update",
        "settings.screensaver",
        "settings.logo",
        "sensors.update",
        "sensors.delete",
    ]
    .contains(&kind)
    {
        return Err("Unsupported logging operation".into());
    }
    let id = operation["id"]
        .as_str()
        .filter(|id| id.len() == 36)
        .ok_or("Invalid operation ID")?;
    if operation["edited_at"].as_str().is_none() || !operation["data"].is_object() {
        return Err("Invalid operation".into());
    }
    state
        .db
        .lock()
        .map_err(err)?
        .execute(
            "INSERT INTO outbox(account,id,operation) VALUES(?,?,?)",
            params![account, id, operation.to_string()],
        )
        .map_err(err)?;
    state.view()
}

#[tauri::command]
pub fn logging_resolve(
    state: tauri::State<'_, Logging>,
    id: String,
    retry: bool,
) -> Result<Value, String> {
    let (account, _, _) = state.session()?;
    let db = state.db.lock().map_err(err)?;
    if retry {
        // Rejected operations have no receipt server-side; conflict receipts are immutable.
        db.execute("UPDATE outbox SET status='pending',receipt=NULL WHERE account=? AND id=? AND status='rejected'",params![account,id]).map_err(err)?;
    } else {
        db.execute(
            "DELETE FROM outbox WHERE account=? AND id=? AND status IN ('rejected','conflict')",
            params![account, id],
        )
        .map_err(err)?;
    }
    drop(db);
    state.view()
}

#[tauri::command]
pub fn logging_correct(
    state: tauri::State<'_, Logging>,
    id: String,
    data: Value,
    edited_at: String,
) -> Result<Value, String> {
    let (account, _, _) = state.session()?;
    if !data.is_object() {
        return Err("Invalid saved input".into());
    }
    let db = state.db.lock().map_err(err)?;
    let raw: String = db
        .query_row(
            "SELECT operation FROM outbox WHERE account=? AND id=? AND status='rejected'",
            params![account, id],
            |r| r.get(0),
        )
        .map_err(err)?;
    let mut operation: Value = serde_json::from_str(&raw).map_err(err)?;
    operation["data"] = data;
    operation["edited_at"] = json!(edited_at);
    // Rejected requests have no durable server receipt. Keep their UUID for dependent edits.
    db.execute("UPDATE outbox SET operation=?,status='pending',receipt=NULL WHERE account=? AND id=? AND status='rejected'",params![operation.to_string(),account,id]).map_err(err)?;
    drop(db);
    state.view()
}

#[tauri::command]
pub async fn logging_attachment(
    state: tauri::State<'_, Logging>,
    id: i64,
) -> Result<String, String> {
    use base64::Engine;
    let (account, server, token) = state.session()?;
    let cached: Option<(String, Vec<u8>)> = state
        .db
        .lock()
        .map_err(err)?
        .query_row(
            "SELECT mime,data FROM attachment_cache WHERE account=? AND id=?",
            params![account, id],
            |r| Ok((r.get(0)?, r.get(1)?)),
        )
        .optional()
        .map_err(err)?;
    let (mime, data) = if let Some(cached) = cached {
        cached
    } else {
        let mut response = state
            .client
            .get(format!("{server}api/mobile/attachments/{id}"))
            .bearer_auth(token)
            .send()
            .await
            .map_err(|_| "Connect once to download this attachment for offline viewing.")?;
        if !response.status().is_success() {
            return Err(format!("Attachment returned {}", response.status()));
        }
        let mime = response
            .headers()
            .get("content-type")
            .and_then(|v| v.to_str().ok())
            .unwrap_or("")
            .split(';')
            .next()
            .unwrap_or("")
            .to_string();
        if !["image/png", "image/jpeg", "image/gif", "image/webp"].contains(&mime.as_str()) {
            return Err("This attachment is not a supported image.".into());
        }
        let mut data = Vec::new();
        while let Some(chunk) = response.chunk().await.map_err(err)? {
            if data.len() + chunk.len() > 20 * 1024 * 1024 {
                return Err("Attachment exceeds the 20 MB offline limit.".into());
            }
            data.extend_from_slice(&chunk);
        }
        state
            .db
            .lock()
            .map_err(err)?
            .execute(
                "INSERT OR REPLACE INTO attachment_cache VALUES(?,?,?,?)",
                params![account, id, mime, data],
            )
            .map_err(err)?;
        (mime, data)
    };
    Ok(format!(
        "data:{mime};base64,{}",
        base64::engine::general_purpose::STANDARD.encode(data)
    ))
}

#[tauri::command]
pub async fn logging_sync(
    app: tauri::AppHandle,
    state: tauri::State<'_, Logging>,
) -> Result<Value, String> {
    let result = state.sync().await;
    let _ = app.emit_to("main", "logging-updated", ());
    result?;
    state.view()
}

#[tauri::command]
pub async fn logging_online(
    state: tauri::State<'_, Logging>,
    path: String,
    data: Option<Value>,
) -> Result<Value, String> {
    // Deliberately bounded routes: neither arbitrary URLs nor bearer tokens enter the webview.
    let fixed = [
        "models",
        "profile",
        "password",
        "settings",
        "sensors/github",
        "sensors/pair",
        "sensors/google-calendar/sync",
        "admin/users",
    ];
    let dynamic = path
        .strip_prefix("refresh-day/")
        .or_else(|| path.strip_prefix("chat/"))
        .is_some_and(|p| p.len() == 10 && p.chars().all(|c| c.is_ascii_digit() || c == '-'));
    let confirm = path
        .strip_prefix("chat-actions/")
        .and_then(|p| p.strip_suffix("/confirm"))
        .is_some_and(|id| !id.is_empty() && id.chars().all(|c| c.is_ascii_digit()));
    if !fixed.contains(&path.as_str()) && !dynamic && !confirm {
        return Err("Unsupported online action".into());
    }
    let _gate = state.sync_gate.lock().await;
    let (_, server, token) = state.session()?;
    state
        .request(&server, Some(&token), &path, data.as_ref())
        .await
}

pub fn start(app: tauri::AppHandle) {
    std::thread::spawn(move || loop {
        std::thread::sleep(Duration::from_secs(10));
        let state = app.state::<Logging>();
        let foreground = app.get_webview_window("main").is_some_and(|w| {
            w.is_visible().unwrap_or(false)
                && !w.is_minimized().unwrap_or(true)
                && w.is_focused().unwrap_or(false)
        });
        let interval = if foreground { 300_000 } else { 3_600_000 };
        let due = state
            .db
            .lock()
            .ok()
            .and_then(|db| {
                db.query_row(
                    "SELECT last_attempt FROM accounts WHERE active=1",
                    [],
                    |r| r.get::<_, i64>(0),
                )
                .ok()
            })
            .is_some_and(|last| now() - last >= interval);
        if due {
            let _ = tauri::async_runtime::block_on(state.sync());
            let _ = app.emit_to("main", "logging-updated", ());
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn database_survives_restart_and_partitions_accounts() {
        let path = std::env::temp_dir().join(format!(
            "totallog-test-{}-{}.sqlite3",
            std::process::id(),
            now()
        ));
        {
            let store = Logging::open(&path).unwrap();
            let db = store.db.lock().unwrap();
            db.execute("INSERT INTO accounts(id,server,user_id,token,active) VALUES('one','https://total-log.com/',1,X'',1),('two','https://total-log.com/',2,X'',0)",[]).unwrap();
            db.execute("INSERT INTO records VALUES('one','blocks','1','{\"id\":1,\"content\":\"offline\"}'),('two','blocks','2','{\"id\":2,\"content\":\"other account\"}')",[]).unwrap();
            db.execute("INSERT INTO outbox(account,id,operation) VALUES('one','first','{\"id\":\"first\",\"kind\":\"blocks.create\",\"data\":{\"content\":\"pending\"}}')",[]).unwrap();
        }
        {
            let store = Logging::open(&path).unwrap();
            let view = store.view().unwrap();
            assert_eq!(view["snapshot"]["blocks"].as_array().unwrap().len(), 1);
            assert_eq!(view["snapshot"]["blocks"][0]["content"], "offline");
            assert_eq!(view["operations"][0]["operation"]["id"], "first");
            assert!(!view.to_string().contains("other account"));
        }
        std::fs::remove_file(path).unwrap();
    }

    #[test]
    #[cfg(windows)]
    fn sync_retains_receipts_until_pull_and_replays_no_duplicate_mutation() {
        use tiny_http::{Header, Response, Server, StatusCode};
        let server = Server::http("127.0.0.1:0").unwrap();
        let base = format!("http://{}/", server.server_addr());
        let store = Logging::open(Path::new(":memory:")).unwrap();
        let token = protect(b"test-token").unwrap();
        let operation = json!({"id":"d3c74489-98e4-49db-ae21-6a10d46e05e1","kind":"blocks.create","data":{"content":"offline"}});
        {
            let db = store.db.lock().unwrap();
            db.execute(
                "INSERT INTO accounts(id,server,user_id,token,active) VALUES('one',?,1,?,1)",
                params![base, token],
            )
            .unwrap();
            db.execute(
                "INSERT INTO outbox(account,id,operation) VALUES('one',?,?)",
                params![operation["id"].as_str(), operation.to_string()],
            )
            .unwrap();
        }
        let worker = std::thread::spawn(move || {
            let mut push = server.recv().unwrap();
            assert_eq!(push.url(), "/api/mobile/sync");
            let mut body = String::new();
            push.as_reader().read_to_string(&mut body).unwrap();
            let body: Value = serde_json::from_str(&body).unwrap();
            assert_eq!(body["operations"][0], operation);
            let header = Header::from_bytes("Content-Type", "application/json").unwrap();
            push.respond(
                Response::from_string(
                    json!({"results":[{"id":operation["id"],"status":"applied","entity_id":12}]})
                        .to_string(),
                )
                .with_header(header.clone()),
            )
            .unwrap();
            let pull = server.recv().unwrap();
            assert_eq!(pull.url(), "/api/mobile/desktop/pull");
            pull.respond(
                Response::from_string("{\"message\":\"temporary outage\"}")
                    .with_status_code(StatusCode(503))
                    .with_header(header.clone()),
            )
            .unwrap();
            let retry = server.recv().unwrap();
            assert_eq!(retry.url(), "/api/mobile/desktop/pull");
            retry.respond(Response::from_string(json!({"schema_version":1,"user_id":1,"cursor":"abc","reset":true,
                "server_time":"2026-11-23T01:00:00Z","changes":[{"collection":"blocks","id":"12","value":{"id":12,"content":"offline"}}],"deleted":[]}).to_string()).with_header(header)).unwrap();
        });
        assert!(tauri::async_runtime::block_on(store.sync()).is_err());
        assert_eq!(store.view().unwrap()["operations"][0]["status"], "applied");
        tauri::async_runtime::block_on(store.sync()).unwrap();
        let view = store.view().unwrap();
        assert_eq!(view["operations"], json!([]));
        assert_eq!(view["aliases"]["d3c74489-98e4-49db-ae21-6a10d46e05e1"],12);
        assert_eq!(view["snapshot"]["blocks"][0]["content"], "offline");
        worker.join().unwrap();
    }

    #[test]
    fn server_urls_prevent_remote_plaintext_credentials() {
        assert!(server_url("http://example.com").is_err());
        assert!(server_url("https://user:secret@example.com").is_err());
        assert_eq!(
            server_url("https://total-log.com").unwrap(),
            "https://total-log.com/"
        );
        assert_eq!(
            server_url("http://127.0.0.1:8016").unwrap(),
            "http://127.0.0.1:8016/"
        );
    }
}

#[cfg(windows)]
fn crypt(data: &[u8], encrypt: bool) -> Result<Vec<u8>, String> {
    use windows_sys::Win32::{
        Foundation::LocalFree,
        Security::Cryptography::{
            CryptProtectData, CryptUnprotectData, CRYPTPROTECT_UI_FORBIDDEN, CRYPT_INTEGER_BLOB,
        },
    };
    let input = CRYPT_INTEGER_BLOB {
        cbData: data.len() as u32,
        pbData: data.as_ptr() as *mut u8,
    };
    let mut output = CRYPT_INTEGER_BLOB {
        cbData: 0,
        pbData: std::ptr::null_mut(),
    };
    unsafe {
        let ok = if encrypt {
            CryptProtectData(
                &input,
                std::ptr::null(),
                std::ptr::null(),
                std::ptr::null_mut(),
                std::ptr::null(),
                CRYPTPROTECT_UI_FORBIDDEN,
                &mut output,
            )
        } else {
            CryptUnprotectData(
                &input,
                std::ptr::null_mut(),
                std::ptr::null(),
                std::ptr::null_mut(),
                std::ptr::null(),
                CRYPTPROTECT_UI_FORBIDDEN,
                &mut output,
            )
        };
        if ok == 0 {
            return Err("Windows could not unlock the account token. Sign in again.".into());
        }
        let result = std::slice::from_raw_parts(output.pbData, output.cbData as usize).to_vec();
        LocalFree(output.pbData as *mut _);
        Ok(result)
    }
}
#[cfg(windows)]
fn protect(data: &[u8]) -> Result<Vec<u8>, String> {
    crypt(data, true)
}
#[cfg(windows)]
fn unprotect(data: &[u8]) -> Result<Vec<u8>, String> {
    crypt(data, false)
}
#[cfg(not(windows))]
fn protect(_: &[u8]) -> Result<Vec<u8>, String> {
    Err("Account storage currently requires Windows.".into())
}
#[cfg(not(windows))]
fn unprotect(data: &[u8]) -> Result<Vec<u8>, String> {
    protect(data)
}
