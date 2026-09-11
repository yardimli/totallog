import SwiftUI
import QuickLook
import CryptoKit

struct MoreView: View {
    @EnvironmentObject var store: Store
    @State private var signOut = false
    var body: some View {
        List {
            Section { Text(store.user.text("name")).font(.headline); Text(store.user.text("email")).foregroundStyle(.secondary) }
            Section {
                NavigationLink { SyncView() } label: { Label("Sync & offline changes", systemImage: "arrow.triangle.2.circlepath") }
                NavigationLink { SensorsView() } label: { Label("Sensors", systemImage: "sensor.tag.radiowaves.forward") }
                NavigationLink { ProfileView() } label: { Label("Profile", systemImage: "person.crop.circle") }
                if store.user.flag("is_admin") { NavigationLink { AdminView() } label: { Label("Administration", systemImage: "person.3") } }
                NavigationLink { SettingsView() } label: { Label("Settings", systemImage: "gearshape") }
                NavigationLink { UsageView() } label: { Label("API usage", systemImage: "chart.bar") }
            }
            Section { Button("Sign out", role: .destructive) { signOut = true } }
        }.navigationTitle("More").confirmationDialog("Sign out of TotalLog?", isPresented: $signOut) { Button("Sign out", role: .destructive) { Task { await store.logout() } } }
    }
}
struct SyncView: View {
    @EnvironmentObject var store: Store
    @State private var discardID: String?
    @State private var reauthenticate = false
    @State private var editing: Operation?
    var body: some View {
        List {
            Section("Connection") { Text(store.disk.server); Text(store.online ? "Network available" : "Offline"); if let date = store.disk.lastSync { LabeledContent("Last sync") { Text(date, style: .relative) } }; Button("Sync now") { Task { await store.sync() } }.disabled(store.busy || !store.online) }
            Section { Button("Sign in again") { reauthenticate = true } }
            Section("Pending changes") {
                ForEach(store.disk.operations) { op in
                    VStack(alignment: .leading, spacing: 8) {
                        Text(op.kind).font(.headline)
                        Text(op.edited_at).font(.caption).foregroundStyle(.secondary)
                        JSONDetails(value: .object(op.data))
                        if let error = op.error { Text(error).foregroundStyle(.red); Button("Retry") { store.retry(op.id) }; Button("Correct change") { editing = op } }
                        Button("Discard local change", role: .destructive) { discardID = op.id }
                    }
                }
                if store.disk.operations.isEmpty { Text("Everything is synced.").foregroundStyle(.secondary) }
            }
            Section("Resolved conflicts") { ForEach(Array(store.disk.messages.enumerated()), id: \.offset) { _, message in Text(message).textSelection(.enabled) } }
        }.navigationTitle("Sync").sheet(item: $editing) { op in NavigationStack { PendingChangeEditor(operation: op) } }.sheet(isPresented: $reauthenticate) { LoginView() }
            .confirmationDialog("Discard this local change?", isPresented: Binding(get: { discardID != nil }, set: { if !$0 { discardID = nil } })) { Button("Discard", role: .destructive) { if let id = discardID { store.discard(id) }; discardID = nil } }
    }
}
struct SettingsView: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    @State private var timeFormat = "24"
    @State private var weekStart = 1
    @State private var model = ""
    @State private var apiKey = ""
    @State private var removeKey = false
    var body: some View {
        Form {
            Section("Display") {
                Picker("Clock", selection: $timeFormat) { Text("12 hour").tag("12"); Text("24 hour").tag("24") }
                Picker("Week begins", selection: $weekStart) { ForEach(0..<7, id: \.self) { Text(["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"][$0]).tag($0) } }
                LabeledContent("Appearance", value: "Follows iPhone settings")
            }
            Section("OpenRouter") { TextField("Default model ID", text: $model).textInputAutocapitalization(.never).autocorrectionDisabled(); SecureField("Replace API key", text: $apiKey); Toggle("Remove API key", isOn: $removeKey) }
            Section { Button("Save settings") {
                // Secrets are sent directly and never retained in the offline queue.
                let data: Record = ["time_format": .string(timeFormat), "week_starts_on": .number(Double(weekStart)), "default_chat_model": .string(model)]
                if !apiKey.isEmpty || removeKey {
                    Task { do { var onlineData = data; onlineData["openrouter_api_key"] = .string(apiKey); onlineData["remove_api_key"] = .bool(removeKey); _ = try await store.call("settings", data: onlineData); apiKey = ""; await store.sync(); dismiss() } catch { store.error = error.localizedDescription } }
                } else if store.enqueue("settings.update", data: data) { dismiss() }
            } }
            Section { NavigationLink("Screensaver") { ScreensaverSettings() } }
        }.navigationTitle("Settings").onAppear { timeFormat = store.user.text("time_format"); weekStart = Int(store.user.text("week_starts_on")) ?? 1; model = store.user.text("default_chat_model") }
    }
}
struct SensorsView: View {
    @Environment(\.openURL) private var openURL
    @State private var pairingKey = ""
    @State private var pairingType = "browser"
    @EnvironmentObject var store: Store
    @State private var username = ""
    @State private var githubToken = ""
    @State private var sending = false
    @State private var deleting: String?
    var body: some View {
        List {
            ForEach(store.records("sensors"), id: \.recordID) { sensor in
                Section(sensor.text("type").replacingOccurrences(of: "_", with: " ").capitalized) {
                    Text(sensor.text("username"))
                    Toggle("Enabled", isOn: Binding(get: { sensor.flag("enabled") }, set: { _ = store.enqueue("sensors.update", target: sensor.recordID, data: ["enabled": .bool($0)]) }))
                    if !sensor.text("last_error").isEmpty { Text(sensor.text("last_error")).foregroundStyle(.red) }
                    Text("Last checked: " + sensor.text("last_checked_at")).font(.caption)
                    if sensor.text("type") == "google_calendar" { Button("Sync Google Calendar now") { Task { await action("sensors/google-calendar/sync", data: [:]) } }.disabled(!store.online || sending) }
                    Button("Unlink", role: .destructive) { deleting = sensor.recordID }
                }
            }
            Section("Connect GitHub") { TextField("GitHub username", text: $username).textInputAutocapitalization(.never).autocorrectionDisabled(); SecureField("Personal access token", text: $githubToken); Button("Connect") { Task { await action("sensors/github", data: ["github_username": .string(username), "github_token": .string(githubToken)]); githubToken = "" } }.disabled(!store.online || sending || username.isEmpty || githubToken.isEmpty) }
            Section("Connect Google Calendar") {
                Button("Authorize with Google") { Task { do { let reply = try await store.call("google/connect", data: [:]); if let url = URL(string: reply.text("url")) { openURL(url) } } catch { store.error = error.localizedDescription } } }.disabled(!store.online)
                Text("Google authorization opens in your browser and returns to TotalLog.").font(.caption).foregroundStyle(.secondary)
            }
            Section("Pair a client") {
                Picker("Client", selection: $pairingType) { Text("Chrome extension").tag("browser"); Text("Desktop app").tag("desktop") }
                SecureField("Pairing key from client", text: $pairingKey)
                Button("Pair") { Task { await action("sensors/pair", data: ["type": .string(pairingType), "key": .string(pairingKey)]); pairingKey = "" } }.disabled(!store.online || pairingKey.isEmpty)
            }
            Section { Text("Your Chrome, desktop, Kindle and mobile browser sensor history appears in the Journal after syncing.").foregroundStyle(.secondary) }
        }.navigationTitle("Sensors")
            .confirmationDialog("Unlink this sensor? Existing logs will be kept.", isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } })) { Button("Unlink", role: .destructive) { if let id = deleting { _ = store.enqueue("sensors.delete", target: id, data: [:]) }; deleting = nil } }
    }
    func action(_ path: String, data: Record) async { sending = true; defer { sending = false }; do { _ = try await store.call(path, data: data); await store.sync() } catch { store.error = error.localizedDescription } }
}
struct UsageView: View {
    @EnvironmentObject var store: Store
    var calls: [Record] { store.records("api_calls") }
    var body: some View {
        List {
            Section("Totals") { LabeledContent("Tokens", value: String(calls.reduce(0) { $0 + (Int($1.text("total_tokens")) ?? 0) })); LabeledContent("Cost (USD)", value: calls.reduce(0.0) { $0 + (Double($1.text("cost")) ?? 0) }.formatted(.currency(code: "USD"))) }
            ForEach(calls, id: \.recordID) { call in VStack(alignment: .leading) { Text(call.text("operation") + " · " + call.text("model")).font(.headline); Text("\(call.text("total_tokens")) tokens · $\(call.text("cost"))"); Text(call.text("created_at")).font(.caption).foregroundStyle(.secondary); if !call.text("error").isEmpty { Text(call.text("error")).foregroundStyle(.red) } } }
        }.navigationTitle("API usage")
    }
}
struct ChatView: View {
    @EnvironmentObject var store: Store
    var day: Date
    @State private var message = ""
    @State private var model = ""
    @State private var models: [Record] = []
    @State private var result: Record = [:]
    @State private var sending = false
    var body: some View {
        List {
            Section("Model") { TextField("Model ID", text: $model).textInputAutocapitalization(.never).autocorrectionDisabled(); if !models.isEmpty { Picker("Available models", selection: $model) { ForEach(models, id: \.recordID) { Text($0.text("name")).tag($0.recordID) } } }; Button("Load models") { Task { do { models = try await store.call("models")["data"]?.array.map(\.object) ?? [] } catch { store.error = error.localizedDescription } } } }
            Section("Ask about your logs or propose an action") { TextEditor(text: $message).frame(minHeight: 120); Button { Task { await send() } } label: { HStack { Text("Send"); if sending { ProgressView() } } }.disabled(sending || !store.online || message.isEmpty || model.isEmpty || !store.disk.operations.isEmpty); if !store.disk.operations.isEmpty { Text("Sync pending edits before chatting so the assistant sees your latest changes.").font(.caption) } }
            if !result.isEmpty { Section("Response") { Text(result.text("answer") + result.text("summary")).textSelection(.enabled); if result.text("kind") == "action" { Button("Confirm proposed actions") { Task { do { result = try await store.call("chat-actions/\(result.text("proposal_id"))/confirm", data: [:]); await store.sync() } catch { store.error = error.localizedDescription } } } } } }
            Section("Pending action confirmations") {
                ForEach(store.records("chat_proposals").filter { proposal in store.records("logs").contains { $0.recordID == proposal.text("daily_log_id") && $0.text("log_date").hasPrefix(Dates.day(day)) } }, id: \.recordID) { proposal in
                    Text(proposal.text("summary"))
                    Button("Confirm these actions") { Task { do { _ = try await store.call("chat-actions/\(proposal.recordID)/confirm", data: [:]); await store.sync() } catch { store.error = error.localizedDescription } } }.disabled(!store.online || !store.disk.operations.isEmpty)
                }
            }
            Section("Conversation") { ForEach(store.blocks(day: Dates.day(day)).filter { $0.text("type").hasPrefix("chat_") }, id: \.recordID) { BlockRow(block: $0) } }
        }.navigationTitle("Chat").onAppear { model = store.user.text("default_chat_model") }
    }
    func send() async { sending = true; defer { sending = false }; do { result = try await store.call("chat/\(Dates.day(day))", data: ["message": .string(message), "model": .string(model)]); message = ""; await store.sync() } catch { store.error = error.localizedDescription } }
}
struct AttachmentView: View {
    @EnvironmentObject var store: Store
    var attachment: Record
    @State private var file: URL?
    @State private var failure: String?
    var body: some View {
        VStack { if let file { ShareLink(item: file); Button("Preview attachment") { preview = file } } else if let failure { Text(failure) } else { ProgressView("Loading attachment…") } }.padding().navigationTitle(attachment.text("original_name")).task { await download() }.quickLookPreview($preview)
    }
    @State private var preview: URL?
    func download() async {
        do {
            let namespace = SHA256.hash(data: Data((store.disk.server + ":" + store.disk.userID).utf8)).map { String(format: "%02x", $0) }.joined()
            let folder = URL.applicationSupportDirectory.appendingPathComponent("TotalLog/attachments/\(namespace)")
            let safeName = URL(fileURLWithPath: attachment.text("original_name").isEmpty ? "attachment" : attachment.text("original_name")).lastPathComponent
            let local = folder.appendingPathComponent(attachment.recordID + "-" + safeName)
            if FileManager.default.fileExists(atPath: local.path) { file = local; preview = local; return }
            guard let remote = URL(string: store.disk.server + "/api/mobile/attachments/" + attachment.recordID) else { return }
            var request = URLRequest(url: remote); request.setValue("Bearer \(Keychain.read() ?? "")", forHTTPHeaderField: "Authorization")
            let (data, response) = try await URLSession.shared.data(for: request)
            guard (response as? HTTPURLResponse)?.statusCode == 200 else { throw AppFailure(message: "Could not download attachment.") }
            try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
            try data.write(to: local, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication]); file = local; preview = local
        } catch { failure = error.localizedDescription }
    }
}
struct ScreensaverSettings: View {
    @EnvironmentObject var store: Store
    @State private var enabled = false
    @State private var style = "starry-night"
    @State private var wait = 5
    @State private var speed = 1.0
    @State private var message = "OUT TO LUNCH"
    @State private var preview = false
    @State private var logo = ""
    @State private var removeLogo = false
    let styles = ["bouncing-ball", "fade-out", "fish", "flying-toasters", "globe", "hard-rain", "logo", "messages", "messages2", "rainstorm", "spotlight", "starry-night", "warp"]
    var body: some View {
        Form {
            Toggle("Enabled", isOn: $enabled)
            Picker("Style", selection: $style) { ForEach(styles, id: \.self) { Text($0.replacingOccurrences(of: "-", with: " ").capitalized).tag($0) } }
            Picker("Wait (minutes)", selection: $wait) { ForEach([1,2,5,10,15,30,60], id: \.self) { Text(String($0)).tag($0) } }
            Picker("Speed", selection: $speed) { ForEach([0.5,0.75,1,1.25,1.5,2], id: \.self) { Text(String($0)).tag($0) } }
            TextField("Message", text: $message)
            IconPicker(icon: $logo, remove: $removeLogo)
            Button("Save custom logo") { _ = store.enqueue("settings.logo", data: ["logo_data": logo.isEmpty ? .null : .string(logo)]) }
            Button("Preview on iPhone") { preview = true }
            Button("Save") { _ = store.enqueue("settings.screensaver", data: ["screensaver_enabled": .bool(enabled), "screensaver_style": .string(style), "screensaver_wait_minutes": .number(Double(wait)), "screensaver_speed": .number(speed), "screensaver_message": .string(message)]) }
        }.navigationTitle("Screensaver").onAppear { logo = store.disk.snapshot.text("screensaver_logo"); enabled = store.user.flag("screensaver_enabled"); style = store.user.text("screensaver_style").isEmpty ? "starry-night" : store.user.text("screensaver_style"); wait = Int(store.user.text("screensaver_wait_minutes")) ?? 5; speed = Double(store.user.text("screensaver_speed")) ?? 1; message = store.user.text("screensaver_message") }
            .fullScreenCover(isPresented: $preview) { NativeScreensaver(style: style, speed: speed, message: message, logo: logo).onTapGesture { preview = false } }
    }
}
struct NativeScreensaver: View {
    var style: String
    var speed: Double
    var message: String
    var logo: String = ""
    var body: some View {
        TimelineView(.animation) { timeline in
            Canvas { context, size in
                context.fill(Path(CGRect(origin: .zero, size: size)), with: .color(.black))
                let t = timeline.date.timeIntervalSinceReferenceDate * speed
                for i in 0..<60 {
                    let x = (Double(i * 71) + t * Double(i % 5 + 1) * 8).truncatingRemainder(dividingBy: max(1, size.width))
                    let y = (Double(i * 113) + t * 18).truncatingRemainder(dividingBy: max(1, size.height))
                    if ["fish", "flying-toasters", "globe", "logo", "messages", "messages2"].contains(style) {
                        if style == "logo", i < 8, let encoded = logo.split(separator: ",").last, let bytes = Data(base64Encoded: String(encoded)), let image = UIImage(data: bytes) {
                            context.draw(Image(uiImage: image), in: CGRect(x: x, y: y, width: 80, height: 80))
                        } else if i < 8 { context.draw(Text(style == "fish" ? "🐠" : style == "flying-toasters" ? "🍞" : style == "globe" ? "🌎" : style == "logo" ? "TotalLog" : message).font(.title).foregroundStyle(.white), at: CGPoint(x: x, y: y)) }
                    } else { context.fill(Path(ellipseIn: CGRect(x: x, y: y, width: style == "bouncing-ball" ? 24 : 3, height: style.contains("rain") ? 18 : 3)), with: .color(.white.opacity(style == "fade-out" ? (sin(t) + 1) / 2 : 0.8))) }
                }
            }
        }.ignoresSafeArea().statusBarHidden()
    }
}
