import SwiftUI

@main struct TotalLogApp: App {
    @StateObject private var store = Store()
    @Environment(\.scenePhase) private var phase
    var body: some Scene {
        WindowGroup {
            Group { if store.signedIn { RootView() } else { LoginView() } }
                .environmentObject(store)
                .environment(\.calendar, store.calendar)
                .tint(.indigo)
                .alert("TotalLog", isPresented: Binding(get: { store.error != nil }, set: { if !$0 { store.error = nil } })) { Button("OK") { store.error = nil } } message: { Text(store.error ?? "") }
                .onOpenURL { url in if url.host == "signin" { Task { await store.handleBrowserCallback(url) } } else if url.scheme == "totallog" { Task { await store.sync() } } }
                .onChange(of: phase) { _, value in if value == .active { Task { await store.sync() } } }
        }
    }
}
struct LoginView: View {
    @EnvironmentObject var store: Store
    @Environment(\.openURL) private var openURL
    @Environment(\.dismiss) private var dismiss
    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                Spacer()
                Image(systemName: "book.closed.fill").font(.system(size: 64)).foregroundStyle(.indigo)
                Text("TotalLog").font(.largeTitle.bold())
                Text("Your days, together.").font(.title3)
                Text("Sign in securely in your browser. You’ll return here automatically when you’re done.").multilineTextAlignment(.center).foregroundStyle(.secondary)
                Button {
                    Task { if let url = await store.beginBrowserLogin() { openURL(url) } }
                } label: {
                    HStack { if store.busy { ProgressView() }; Label(store.browserSigningIn ? "Open browser again" : "Sign in with browser", systemImage: "arrow.up.forward.app") }.frame(maxWidth: .infinity).padding(.vertical, 8)
                }.buttonStyle(.borderedProminent).disabled(store.busy || !store.online)
                if store.browserSigningIn { Text("Finish signing in in your browser, then allow it to open TotalLog.").font(.footnote).foregroundStyle(.secondary).multilineTextAlignment(.center) }
                if store.browserSigningIn { Button("Cancel sign-in") { store.cancelBrowserLogin() }.disabled(store.busy) }
                Text(URL(string: store.disk.server)?.host ?? "total-log.com").font(.footnote).foregroundStyle(.secondary)
                Spacer()
            }.padding(32)
                .onChange(of: store.browserSigningIn) { _, waiting in if !waiting && store.signedIn { dismiss() } }
        }
    }
}
struct RootView: View {
    @EnvironmentObject var store: Store
    @State private var lastInteraction = Date()
    @State private var screensaver = false
    private let idleTimer = Timer.publish(every: 15, on: .main, in: .common).autoconnect()
    var body: some View {
        TabView {
            NavigationStack { JournalView() }.tabItem { Label("Journal", systemImage: "book") }
            NavigationStack { DefinitionsView() }.tabItem { Label("Events", systemImage: "checkmark.circle") }
            NavigationStack { GoalsView() }.tabItem { Label("Goals", systemImage: "target") }
            NavigationStack { SearchView() }.tabItem { Label("Search", systemImage: "magnifyingglass") }
            NavigationStack { MoreView() }.tabItem { Label("More", systemImage: "ellipsis.circle") }
        }
        .safeAreaInset(edge: .top, spacing: 0) {
            if !store.online || !store.disk.operations.isEmpty || store.busy {
                HStack { if store.busy { ProgressView().controlSize(.small) }; Text(store.online ? "\(store.disk.operations.count) pending · \(store.busy ? "Syncing" : "Saved on iPhone")" : "Offline · \(store.disk.operations.count) pending"); Spacer() }.font(.caption).padding(.horizontal).padding(.vertical, 6).background(.thinMaterial)
            }
        }
        .simultaneousGesture(TapGesture().onEnded { lastInteraction = Date() })
        .simultaneousGesture(DragGesture(minimumDistance: 12).onChanged { _ in lastInteraction = Date() })
        .onReceive(NotificationCenter.default.publisher(for: UIApplication.didBecomeActiveNotification)) { _ in lastInteraction = Date() }
        .onReceive(idleTimer) { _ in
            if store.user.flag("screensaver_enabled") && !store.busy && Date().timeIntervalSince(lastInteraction) > (Double(store.user.text("screensaver_wait_minutes")) ?? 5) * 60 { screensaver = true }
        }
        .fullScreenCover(isPresented: $screensaver) {
            NativeScreensaver(style: store.user.text("screensaver_style"), speed: Double(store.user.text("screensaver_speed")) ?? 1, message: store.user.text("screensaver_message"), logo: store.disk.snapshot.text("screensaver_logo"))
                .onTapGesture { lastInteraction = Date(); screensaver = false }
        }
        .task { while !Task.isCancelled { await store.sync(); try? await Task.sleep(for: .seconds(45)) } }
    }
}
struct RecordIcon: View {
    var record: Record
    var body: some View {
        Group {
            if let encoded = record.text("icon_data").split(separator: ",").last, let data = Data(base64Encoded: String(encoded)), let image = UIImage(data: data) { Image(uiImage: image).resizable().scaledToFit() }
            else { Text(record.text("emoji").isEmpty ? (record.text("type") == "sensor_desktop" ? "🖥️" : record.text("type") == "sensor_browsing" ? "🌐" : "📝") : record.text("emoji")).font(.title2) }
        }.frame(width: 36, height: 36)
    }
}
struct LogEditor: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var day: Date
    var block: Record = [:]
    @State private var content = ""
    @State private var emoji = "📝"
    @State private var time = Date()
    var body: some View {
        Form {
            Section("Entry time") { DatePicker("Time", selection: $time, displayedComponents: .hourAndMinute); Button("Now") { time = Date() } }
            if let event = block["task_event"]?.object, !event.isEmpty { Section { HStack { RecordIcon(record: block); Text(event.text("task_name")).font(.headline) } } }
            Section("What happened?") { TextEditor(text: $content).frame(minHeight: 220) }
            Section("Appearance") { TextField("Emoji", text: $emoji) }
        }.navigationTitle(block.isEmpty ? "New log" : "Edit entry")
            .onAppear { if !block.isEmpty { content = block["task_event"]?.object.text("notes") ?? block.text("content"); emoji = block.text("emoji"); time = Dates.parse(block.text("occurred_at")) ?? Date() } }
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) { Button(block.isEmpty ? "Save" : "Close") {
                    var data: Record = ["content": .string(content), "emoji": .string(emoji), "occurred_at": .string(Dates.time(time))]
                    let isEvent = block.text("type") == "event"
                    if isEvent { data["notes"] = data.removeValue(forKey: "content") }
                    if block.isEmpty { data["type"] = .string("text"); data["log_date"] = .string(Dates.day(day)) }
                    let target = isEvent ? block["task_event"]?.object.recordID : block.recordID
                    if store.enqueue(block.isEmpty ? "blocks.create" : isEvent ? "events.update" : "blocks.update", target: block.isEmpty ? nil : target, data: data) { dismiss() }
                }.disabled(block.isEmpty && content.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty) }
            }
    }
}
struct RecordEventView: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var definition: Record
    var day: Date
    @State private var value = ""
    @State private var slot = ""
    var body: some View {
        Form {
            Section { HStack { RecordIcon(record: definition); Text(definition.text("name")).font(.title2) }; Text(day, style: .date) }
            if !definition.list("options").isEmpty { Picker("Value", selection: $value) { Text("Choose…").tag(""); ForEach(definition.list("options"), id: \.self) { Text($0).tag($0) } } }
            if !definition.list("scheduled_times").isEmpty { Picker("Time slot", selection: $slot) { Text("Unscheduled").tag(""); ForEach(definition.list("scheduled_times"), id: \.self) { Text($0).tag($0) } } }
            LabeledContent("Recorded in this slot", value: String(recordedCount))
            Button("Record event") {
                var data: Record = ["log_date": .string(Dates.day(day)), "task_definition_id": .string(definition.recordID)]
                if !value.isEmpty { data["value"] = .string(value) }; if !slot.isEmpty { data["scheduled_time"] = .string(slot) }
                if store.enqueue("events.create", data: data) { dismiss() }
            }.disabled((!definition.list("options").isEmpty && value.isEmpty) || (!slot.isEmpty && recordedCount >= (Int(definition.text("daily_default_count")) ?? 1)))
        }.navigationTitle("Record event")
    }
    var recordedCount: Int {
        store.blocks(day: Dates.day(day)).filter { block in
            let event = block["task_event"]?.object ?? [:]
            return event.text("task_definition_id") == definition.recordID && (slot.isEmpty || event.text("scheduled_time") == slot)
        }.count
    }
}
struct BlockDetail: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var block: Record
    @State private var edit = false
    @State private var deleting = false
    var body: some View {
        List {
            Section { BlockRow(block: block); Text(block.text("content")).textSelection(.enabled) }
            if let event = block["task_event"]?.object, !event.text("city").isEmpty { Section("Location") { Text([event.text("suburb"), event.text("city")].filter { !$0.isEmpty }.joined(separator: ", ")) } }
            if let activities = block["desktop_activities"]?.array, !activities.isEmpty { Section("Applications") { SensorActivityView(records: activities.map(\.object), desktop: true) } }
            if let activities = block["browsing_activities"]?.array, !activities.isEmpty { Section("Websites") { SensorActivityView(records: activities.map(\.object), desktop: false) } }
            ForEach(["metadata", "mobile_browsing_visits", "kindle_reading_progress", "google_calendar_event"], id: \.self) { key in
                if let data = block[key], data != .null && data != .array([]) && data != .object([:]) { Section(key.replacingOccurrences(of: "_", with: " ").capitalized) { JSONDetails(value: data) } }
            }
            if let attachments = block["attachments"]?.array, !attachments.isEmpty {
                Section("Attachments") { ForEach(attachments.indices, id: \.self) { i in NavigationLink(attachments[i].object.text("original_name")) { AttachmentView(attachment: attachments[i].object) } } }
            }
            if let event = block["task_event"]?.object, !event.recordID.isEmpty { Section { NavigationLink("Add or update location") { EventLocationView(eventID: event.recordID) } } }
            Section {
                Button("Edit entry") { edit = true }
                Button(block.flag("is_hidden") ? "Show in planner" : "Hide from planner") { if store.enqueue("blocks.visibility", target: block.recordID, data: ["is_hidden": .bool(!block.flag("is_hidden"))]) { dismiss() } }
                Button("Delete entry", role: .destructive) { deleting = true }
            }.disabled(block.flag("pending") && block.text("type") == "event")
        }.scrollContentBackground(.hidden).plannerBackground().navigationTitle(block.text("type") == "sensor_desktop" ? "Applications" : block.text("type") == "sensor_browsing" ? "Websites" : "Entry").sheet(isPresented: $edit) { NavigationStack { LogEditor(day: Date(), block: block) } }
            .confirmationDialog("Delete this entry?", isPresented: $deleting, titleVisibility: .visible) { Button("Delete", role: .destructive) { if store.enqueue("blocks.delete", target: block.recordID, data: [:]) { dismiss() } } }
    }
}
struct JSONDetails: View {
    var value: JSONValue
    var body: some View { Text(pretty).font(.callout).textSelection(.enabled) }
    var pretty: String {
        func render(_ v: JSONValue, depth: Int = 0) -> String {
            switch v {
            case .object(let fields): return fields.keys.sorted().filter { !["user_id", "daily_log_id", "log_block_id", "id"].contains($0) }.map { "\($0.replacingOccurrences(of: "_", with: " ").capitalized): \(render(fields[$0]!, depth: depth + 1))" }.joined(separator: "\n")
            case .array(let values): return values.map { render($0, depth: depth + 1) }.joined(separator: "\n\n")
            default: return v.text
            }
        }
        return render(value)
    }
}
struct SearchView: View {
    @EnvironmentObject var store: Store
    @State private var query = ""
    var body: some View {
        List { ForEach(store.blocks().filter { query.isEmpty || searchText($0).localizedCaseInsensitiveContains(query) }, id: \.recordID) { block in NavigationLink { BlockDetail(block: block) } label: { BlockRow(block: block) } } }
            .navigationTitle("Search").searchable(text: $query, prompt: "Logs, events, sensor history or date")
    }
    func searchText(_ record: Record) -> String { guard let data = try? JSONEncoder().encode(record) else { return "" }; return String(data: data, encoding: .utf8) ?? "" }
}
