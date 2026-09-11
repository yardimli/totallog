import SwiftUI
import Network
import Security
import CryptoKit

struct AppFailure: LocalizedError { var message: String; var code: String? = nil; var errorDescription: String? { message } }

enum Keychain {
    static func key(_ account: String) -> [String: Any] { [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: "TotalLog", kSecAttrAccount as String: account] }
    static func read(account: String = "session") -> String? {
        var q = key(account); q[kSecReturnData as String] = true
        var result: CFTypeRef?
        guard SecItemCopyMatching(q as CFDictionary, &result) == errSecSuccess, let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }
    static func save(_ token: String, account: String = "session") throws {
        SecItemDelete(key(account) as CFDictionary)
        var q = key(account); q[kSecValueData as String] = Data(token.utf8); q[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        guard SecItemAdd(q as CFDictionary, nil) == errSecSuccess else { throw AppFailure(message: "Could not save the sign-in token securely.") }
    }
    static func clear(account: String = "session") { SecItemDelete(key(account) as CFDictionary) }
}

@MainActor final class Store: ObservableObject {
    @Published var disk = DiskState()
    @Published var signedIn = false
    @Published var browserSigningIn = false
    @Published var busy = false
    @Published var online = true
    @Published var error: String?
    private var token = ""
    private var browserLogin: BrowserLoginPending?
    private var exchangingBrowserRequest: String?
    private let monitor = NWPathMonitor()
    private let file: URL
    private var healthy = true
    init() {
        file = URL.applicationSupportDirectory.appendingPathComponent("TotalLog/state.json")
        do {
            if FileManager.default.fileExists(atPath: file.path) { disk = try JSONDecoder().decode(DiskState.self, from: Data(contentsOf: file)) }
            if disk.server.isEmpty { disk.server = "https://total-log.com" }
            if let saved = Keychain.read(account: "browser-login"), let bytes = saved.data(using: .utf8) {
                browserLogin = try? JSONDecoder().decode(BrowserLoginPending.self, from: bytes)
                browserSigningIn = browserLogin != nil
            }
            token = Keychain.read() ?? ""; signedIn = !token.isEmpty && !disk.userID.isEmpty
        } catch { healthy = false; self.error = "Offline data could not be read. It has been preserved: \(error.localizedDescription)" }
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor in self?.online = path.status == .satisfied; if path.status == .satisfied { await self?.sync() } }
        }
        monitor.start(queue: DispatchQueue(label: "TotalLog.network"))
    }
    func persist() throws {
        guard healthy else { throw AppFailure(message: "Offline storage needs recovery before it can be changed.") }
        try FileManager.default.createDirectory(at: file.deletingLastPathComponent(), withIntermediateDirectories: true)
        try JSONEncoder().encode(disk).write(to: file, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }
    func call(_ path: String, data: Record? = nil) async throws -> Record {
        guard let url = URL(string: disk.server + "/api/mobile/" + path) else { throw AppFailure(message: "Invalid server address.") }
        var req = URLRequest(url: url); req.timeoutInterval = 90
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if !token.isEmpty { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let data { req.httpMethod = "POST"; req.setValue("application/json", forHTTPHeaderField: "Content-Type"); req.httpBody = try JSONEncoder().encode(data) }
        let (bytes, response) = try await URLSession.shared.data(for: req)
        guard let http = response as? HTTPURLResponse else { throw AppFailure(message: "Invalid server response.") }
        let json = (try? JSONDecoder().decode(Record.self, from: bytes)) ?? [:]
        guard (200..<300).contains(http.statusCode) else {
            if http.statusCode == 401 { throw AppFailure(message: "Your sign-in expired. Your offline edits are safe; sign in again to sync.") }
            throw AppFailure(message: json.text("message").isEmpty ? "Server returned HTTP \(http.statusCode)." : json.text("message"))
        }
        guard !json.isEmpty else { throw AppFailure(message: "The server did not return mobile API JSON. Check the address and server deployment.") }
        return json
    }
    func publicAccountAction(server: String, path: String, data: Record) async throws -> Record {
        let address = server.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let base = URL(string: address), base.scheme == "https", base.host != nil, base.user == nil, base.query == nil, base.fragment == nil,
              let url = URL(string: address + "/api/mobile/" + path) else { throw AppFailure(message: "Enter your HTTPS server address first.") }
        var request = URLRequest(url: url); request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type"); request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try JSONEncoder().encode(data)
        let (bytes, response) = try await URLSession.shared.data(for: request)
        let reply = try JSONDecoder().decode(Record.self, from: bytes)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else { throw AppFailure(message: reply.text("message"), code: reply.text("error_code")) }
        return reply
    }
    func beginBrowserLogin() async -> URL? {
        if let pending = browserLogin,
           let url = URL(string: pending.server + "/mobile/sign-in/" + pending.requestID) {
            return url
        }
        guard !busy else { return nil }; busy = true; defer { busy = false }
        do {
            func randomSecret() throws -> String {
                var bytes = [UInt8](repeating: 0, count: 32)
                guard SecRandomCopyBytes(kSecRandomDefault, bytes.count, &bytes) == errSecSuccess else { throw AppFailure(message: "Could not start secure sign-in.") }
                return bytes.map { String(format: "%02x", $0) }.joined()
            }
            let verifier = try randomSecret(); let state = try randomSecret()
            let server = disk.server.isEmpty ? "https://total-log.com" : disk.server
            let challenge = SHA256.hash(data: Data(verifier.utf8)).map { String(format: "%02x", $0) }.joined()
            var data: Record = ["challenge": .string(challenge), "state": .string(state), "device_name": .string(UIDevice.current.name)]
            if !disk.operations.isEmpty { data["expected_user_id"] = .string(disk.userID) }
            let reply = try await publicAccountAction(server: server, path: "browser-login/start", data: data)
            guard let url = URL(string: reply.text("url")), url.scheme == "https", url.host == URL(string: server)?.host, !reply.text("request_id").isEmpty else { throw AppFailure(message: "The server returned an invalid sign-in address.") }
            let pending = BrowserLoginPending(requestID: reply.text("request_id"), state: state, verifier: verifier, server: server, startedAt: Date())
            let saved = String(decoding: try JSONEncoder().encode(pending), as: UTF8.self)
            try Keychain.save(saved, account: "browser-login")
            browserLogin = pending; browserSigningIn = true; error = nil
            return url
        } catch { self.error = error.localizedDescription; return nil }
    }
    func cancelBrowserLogin() {
        let pending = browserLogin
        browserLogin = nil; browserSigningIn = false; Keychain.clear(account: "browser-login")
        if let pending {
            Task {
                _ = try? await publicAccountAction(server: pending.server, path: "browser-login/cancel", data: ["request_id": .string(pending.requestID), "verifier": .string(pending.verifier)])
            }
        }
    }
    func handleBrowserCallback(_ url: URL) async {
        guard url.scheme == "totallog", url.host == "signin", let pending = browserLogin,
              let parts = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return }
        let items = parts.queryItems ?? []
        func value(_ key: String) -> String? { let matches = items.filter { $0.name == key }; return matches.count == 1 ? matches.first?.value : nil }
        guard value("state") == pending.state, value("request_id") == pending.requestID, let code = value("code") else { error = "This sign-in does not match the request from this iPhone."; return }
        // iOS may deliver the same deep link more than once while resuming the scene.
        guard exchangingBrowserRequest != pending.requestID else { return }
        guard !busy else { error = "Please finish the current sync and sign in again."; return }
        exchangingBrowserRequest = pending.requestID
        busy = true; defer { busy = false; exchangingBrowserRequest = nil }
        do {
            let reply = try await publicAccountAction(server: pending.server, path: "browser-login/exchange", data: ["request_id": .string(pending.requestID), "verifier": .string(pending.verifier), "code": .string(code)])
            guard browserLogin?.requestID == pending.requestID else { return }
            let uid = reply["user"]?.object.recordID ?? ""; let newToken = reply.text("token")
            guard !uid.isEmpty, !newToken.isEmpty else { throw AppFailure(message: "The server returned an invalid sign-in response.") }
            guard disk.operations.isEmpty || (disk.userID == uid && disk.server == pending.server) else { throw AppFailure(message: "Sign in to the account that owns your pending edits.") }
            let before = disk
            if disk.userID != uid || disk.server != pending.server { disk = DiskState(server: pending.server, userID: uid) }
            disk.userID = uid
            do { try persist(); try Keychain.save(newToken) } catch { disk = before; try? persist(); throw error }
            token = newToken; signedIn = true; error = nil
            browserLogin = nil; browserSigningIn = false; Keychain.clear(account: "browser-login")
            busy = false; await sync()
        } catch {
            if let failure = error as? AppFailure, ["sign_in_missing", "sign_in_expired", "sign_in_used"].contains(failure.code ?? "") { cancelBrowserLogin() }
            self.error = error.localizedDescription
        }
    }
    func clearAccount() throws {
        guard disk.operations.isEmpty else { throw AppFailure(message: "Sync or discard pending edits first.") }
        let before = disk; disk = DiskState()
        do { try persist() } catch { disk = before; throw error }
        Keychain.clear(); token = ""; signedIn = false
    }
    func logout() async {
        guard disk.operations.isEmpty else { error = "Sync or discard queued edits before signing out."; return }
        do {
            _ = try await call("logout", data: [:]); try clearAccount()
        } catch { self.error = error.localizedDescription }
    }
    @discardableResult func enqueue(_ kind: String, target: String? = nil, data: Record) -> Bool {
        let before = disk
        disk.operations.append(Operation(kind: kind, target_id: target, edited_at: Dates.stamp(Date().addingTimeInterval(disk.clockOffset)), data: data))
        do { try persist(); Task { await sync() }; return true }
        catch { disk = before; self.error = error.localizedDescription; return false }
    }
    func discard(_ id: String) {
        let before = disk; disk.operations.removeAll { $0.id == id }
        do { try persist() } catch { disk = before; self.error = error.localizedDescription }
    }
    func correct(_ operation: Operation, data: Record) -> Bool {
        guard let i = disk.operations.firstIndex(where: { $0.id == operation.id }), disk.operations[i].error != nil else { return false }
        let before = disk
        // Rejected operations are never receipted, so their UUID can be retained for dependent edits.
        disk.operations[i].data = data; disk.operations[i].edited_at = Dates.stamp(Date().addingTimeInterval(disk.clockOffset)); disk.operations[i].error = nil
        do { try persist(); Task { await sync() }; return true } catch { disk = before; self.error = error.localizedDescription; return false }
    }
    func retry(_ id: String) {
        if let i = disk.operations.firstIndex(where: { $0.id == id }) { disk.operations[i].error = nil }
        do { try persist(); Task { await sync() } } catch { self.error = error.localizedDescription }
    }
    func sync() async {
        guard signedIn, online, !busy, !browserSigningIn, healthy else { return }
        busy = true; defer { busy = false }
        do {
            let batch = Array(disk.operations.filter { $0.error == nil }.prefix(100))
            var reply: Record = [:]
            if !batch.isEmpty { reply = try await call("sync", data: ["operations": .array(batch.map { .object($0.wire) })]) }
            let started = Date()
            let snapshot = try await call("snapshot")
            guard snapshot.text("schema_version") == "1", snapshot["user"]?.object.recordID == disk.userID else { throw AppFailure(message: "Server snapshot is incompatible or belongs to another account.") }
            let before = disk
            do {
                SyncMerge.apply(to: &disk, results: reply["results"]?.array ?? [], submitted: batch, snapshot: snapshot)
            if let serverTime = Dates.parse(snapshot.text("server_time")) { disk.clockOffset = serverTime.timeIntervalSince(started.addingTimeInterval(Date().timeIntervalSince(started) / 2)) }
            disk.lastSync = Date(); try persist()
            } catch { disk = before; throw error }
        } catch { self.error = error.localizedDescription }
    }
    var calendar: Calendar { var value = Calendar.current; value.firstWeekday = (Int(user.text("week_starts_on")) ?? 1) + 1; return value }
    func clock(_ date: Date) -> String { let formatter = DateFormatter(); formatter.dateFormat = user.text("time_format") == "12" ? "h:mm a" : "HH:mm"; return formatter.string(from: date) }
    func resolved(_ id: String?) -> String { guard let id else { return "" }; return disk.aliases[id] ?? id }
    var user: Record {
        var user = disk.snapshot["user"]?.object ?? [:]
        for op in disk.operations where op.kind.hasPrefix("settings.") { user.merge(op.data) { _, new in new } }
        return user
    }
    func records(_ entity: String) -> [Record] {
        var records = disk.snapshot[entity]?.array.map(\.object) ?? []
        for op in disk.operations where op.kind.hasPrefix(entity + ".") {
            let action = op.kind.components(separatedBy: ".").last ?? ""
            if action == "create" {
                var record = op.data; record["id"] = .string(op.id); record["pending"] = .bool(true)
                if entity == "tasks" { record["options"] = .array(op.data.text("options_text").split(separator: ",").map { .string($0.trimmingCharacters(in: .whitespaces)) }); record["recurrence_days"] = op.data["weekdays"] ?? .array(op.data.text("month_days_text").split(separator: ",").map { .string(String($0)) }); record["scheduled_times"] = .array(op.data.text("scheduled_times_text").split(separator: ",").map { .string(String($0)) }) }
                records.append(record)
            } else if action == "delete" { records.removeAll { $0.recordID == resolved(op.target_id) } }
            else if let i = records.firstIndex(where: { $0.recordID == resolved(op.target_id) }) { records[i].merge(op.data) { _, new in new }; records[i]["pending"] = .bool(true) }
        }
        for i in records.indices where records[i].flag("pending") && entity == "tasks" {
            let r = records[i]
            if r["options_text"] != nil { records[i]["options"] = .array(r.text("options_text").split(separator: ",").map { .string($0.trimmingCharacters(in: .whitespaces)) }) }
            if r["scheduled_times_text"] != nil { records[i]["scheduled_times"] = .array(r.text("scheduled_times_text").split(separator: ",").map { .string($0.trimmingCharacters(in: .whitespaces)) }) }
            if r["weekdays"] != nil || r["month_days_text"] != nil {
                records[i]["recurrence_days"] = r.text("recurrence_type") == "weekly" ? r["weekdays"] : .array(r.text("month_days_text").split(separator: ",").map { .string($0.trimmingCharacters(in: .whitespaces)) })
            }
        }
        if entity == "tasks" { records.sort { (Int($0.text("position")) ?? Int.max) < (Int($1.text("position")) ?? Int.max) } }
        return records
    }
    func blocks(day: String? = nil) -> [Record] {
        let logs = records("logs")
        var blocks = records("blocks").map { block in
            var b = block
            if b.text("log_date").isEmpty { b["log_date"] = .string(String(logs.first { $0.recordID == b.text("daily_log_id") }?.text("log_date").prefix(10) ?? "")) }
            return b
        }
        for op in disk.operations where op.kind == "events.create" {
            let definition = records("tasks").first { $0.recordID == resolved(op.data.text("task_definition_id")) } ?? [:]
            blocks.append(["id": .string(op.id), "type": .string("event"), "emoji": .string(definition.text("emoji")), "log_date": op.data["log_date"] ?? .null, "pending": .bool(true), "task_event": .object(["task_definition_id": .string(resolved(op.data.text("task_definition_id"))), "scheduled_time": op.data["scheduled_time"] ?? .null, "task_name": .string(definition.text("name")), "selected_value": op.data["value"] ?? .null])])
        }
        for op in disk.operations where op.kind == "events.update" {
            if let i = blocks.firstIndex(where: { $0["task_event"]?.object.recordID == resolved(op.target_id) }) { blocks[i]["content"] = op.data["notes"]; blocks[i]["emoji"] = op.data["emoji"]; blocks[i]["pending"] = .bool(true) }
        }
        return blocks.filter { day == nil || $0.text("log_date") == day }.sorted { $0.text("occurred_at") < $1.text("occurred_at") }
    }
}
