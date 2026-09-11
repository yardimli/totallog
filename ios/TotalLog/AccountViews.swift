import SwiftUI
import PhotosUI

struct ProfileView: View {
    @EnvironmentObject var store: Store
    @State private var name = ""
    @State private var email = ""
    @State private var current = ""
    @State private var password = ""
    @State private var confirm = ""
    @State private var deletionPassword = ""
    @State private var deleting = false
    @State private var sending = false
    @State private var status = ""
    var body: some View {
        Form {
            if !status.isEmpty { Text(status).foregroundStyle(.green) }
            Section("Profile") {
                TextField("Name", text: $name)
                TextField("Email", text: $email).textInputAutocapitalization(.never).keyboardType(.emailAddress).autocorrectionDisabled()
                Button("Save profile") { Task { await perform("profile", data: ["name": .string(name), "email": .string(email)]) } }
            }
            Section("Change password") {
                SecureField("Current password", text: $current)
                SecureField("New password", text: $password)
                SecureField("Confirm password", text: $confirm)
                Button("Update password") { Task { await perform("password", data: ["current_password": .string(current), "password": .string(password), "password_confirmation": .string(confirm)]); current = ""; password = ""; confirm = "" } }
            }
            Section("Delete account") {
                Text("Permanently deletes your account and its server data. This affects every client.").foregroundStyle(.secondary)
                SecureField("Password to delete account", text: $deletionPassword)
                Button("Delete account", role: .destructive) { deleting = true }.disabled(deletionPassword.isEmpty || !store.disk.operations.isEmpty)
            }
        }.disabled(sending || !store.online).navigationTitle("Profile").onAppear { name = store.user.text("name"); email = store.user.text("email") }
            .confirmationDialog("Permanently delete your TotalLog account?", isPresented: $deleting, titleVisibility: .visible) { Button("Delete account permanently", role: .destructive) { Task { do { _ = try await store.call("account/delete", data: ["password": .string(deletionPassword)]); try store.clearAccount() } catch { store.error = error.localizedDescription } } } }
    }
    func perform(_ path: String, data: Record) async { sending = true; defer { sending = false }; do { status = try await store.call(path, data: data).text("message"); await store.sync() } catch { store.error = error.localizedDescription } }
}
struct AdminView: View {
    @EnvironmentObject var store: Store
    @State private var users: [Record] = []
    @State private var reset = false
    var body: some View {
        List {
            Section { Button("Reset demo data", role: .destructive) { reset = true } }
            ForEach(users, id: \.recordID) { user in VStack(alignment: .leading) { Text(user.text("name")).font(.headline); Text(user.text("email")); Text("\(user.text("daily_logs_count")) days · \(user.text("task_definitions_count")) events" + (user.flag("is_guest") ? " · Guest" : "")).font(.caption).foregroundStyle(.secondary) } }
        }.navigationTitle("Administration").task { await load() }.refreshable { await load() }
            .confirmationDialog("Reset all shared demo data?", isPresented: $reset) { Button("Reset demo", role: .destructive) { Task { do { _ = try await store.call("admin/reset-demo", data: [:]); await load() } catch { store.error = error.localizedDescription } } } }
    }
    func load() async { do { users = try await store.call("admin/users")["users"]?.array.map(\.object) ?? [] } catch { store.error = error.localizedDescription } }
}
struct IconPicker: View {
    @Binding var icon: String
    @Binding var remove: Bool
    @State private var item: PhotosPickerItem?
    @State private var failure: String?
    var body: some View {
        VStack(alignment: .leading) {
            HStack { RecordIcon(record: ["icon_data": .string(icon)]); PhotosPicker("Choose custom icon", selection: $item, matching: .images); if !icon.isEmpty { Button("Remove", role: .destructive) { icon = ""; remove = true } } }
            Text("Photo is cropped to a square icon.").font(.caption).foregroundStyle(.secondary)
            if let failure { Text(failure).foregroundStyle(.red) }
        }.onChange(of: item) { _, selected in
            Task { do {
                guard let data = try await selected?.loadTransferable(type: Data.self), let image = UIImage(data: data) else { return }
                let format = UIGraphicsImageRendererFormat(); format.scale = 1
                let renderer = UIGraphicsImageRenderer(size: CGSize(width: 128, height: 128), format: format)
                let resized = renderer.image { _ in
                    let scale = max(128 / image.size.width, 128 / image.size.height)
                    let size = CGSize(width: image.size.width * scale, height: image.size.height * scale)
                    image.draw(in: CGRect(x: (128 - size.width) / 2, y: (128 - size.height) / 2, width: size.width, height: size.height))
                }
                guard let png = resized.pngData() else { return }
                icon = "data:image/png;base64," + png.base64EncodedString(); remove = false
            } catch { failure = error.localizedDescription } }
        }
    }
}

struct PendingChangeEditor: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var operation: Operation
    @State private var values: Record = [:]
    var body: some View {
        Form {
            ForEach(values.keys.sorted(), id: \.self) { key in
                if case .bool = values[key] {
                    Toggle(key.replacingOccurrences(of: "_", with: " ").capitalized, isOn: Binding(get: { values.flag(key) }, set: { values[key] = .bool($0) }))
                } else if case .array = values[key] {
                    TextField(key.replacingOccurrences(of: "_", with: " ").capitalized, text: Binding(get: { values.list(key).joined(separator: ",") }, set: { values[key] = .array($0.split(separator: ",").map { .string($0.trimmingCharacters(in: .whitespaces)) }) }))
                } else if key == "icon_data" || key == "logo_data" {
                    Text("Custom image retained")
                } else {
                    TextField(key.replacingOccurrences(of: "_", with: " ").capitalized, text: Binding(get: { values.text(key) }, set: { values[key] = $0.isEmpty ? .null : .string($0) }), axis: .vertical)
                }
            }
        }.navigationTitle("Correct change").onAppear { values = operation.data }
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Save & retry") { if store.correct(operation, data: values) { dismiss() } } } }
    }
}
