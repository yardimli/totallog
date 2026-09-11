import SwiftUI

extension Color {
    init(logHex: String) {
        let legacy = ["indigo": "#4f46e5", "emerald": "#059669", "amber": "#d97706", "rose": "#e11d48", "sky": "#0284c7"]
        let value = UInt64((legacy[logHex.lowercased()] ?? logHex).trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) ?? 0x4f46e5
        self.init(red: Double((value >> 16) & 255) / 255, green: Double((value >> 8) & 255) / 255, blue: Double(value & 255) / 255)
    }
    var logHex: String {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return String(format: "#%02X%02X%02X", Int(r * 255), Int(g * 255), Int(b * 255))
    }
    static let logAccent = Color(logHex: "#6558ED")
}
extension View {
    func logCard() -> some View {
        padding(16).background(Color(uiColor: .secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 18))
            .overlay(RoundedRectangle(cornerRadius: 18).stroke(Color.primary.opacity(0.08), lineWidth: 1))
    }
    func plannerBackground() -> some View { background(Color(uiColor: .systemGroupedBackground)) }
}
struct LogBadge: View {
    var title: String
    var color: Color = .logAccent
    var body: some View { Text(title).font(.caption.weight(.semibold)).padding(.horizontal, 9).padding(.vertical, 5).foregroundStyle(color).background(color.opacity(0.14), in: Capsule()) }
}
struct RecordPill: View {
    var record: Record
    var subtitle: String
    var body: some View {
        let color = Color(logHex: record.text("color"))
        let components = UIColor(color).cgColor.components ?? []
        let light = components.count >= 3 && components[0] * 0.299 + components[1] * 0.587 + components[2] * 0.114 > 0.6
        HStack(spacing: 8) {
            RecordIcon(record: record)
            VStack(alignment: .leading, spacing: 2) { Text(record.text("name")).font(.subheadline.bold()); Text(subtitle).font(.caption) }
        }.padding(.horizontal, 14).padding(.vertical, 9).foregroundStyle(light ? Color.black : Color.white).background(color, in: Capsule())
    }
}
struct OptionalLogDate: View {
    var title: String
    @Binding var value: String
    var body: some View {
        Toggle(title, isOn: Binding(get: { !value.isEmpty }, set: { value = $0 ? Dates.day(Date()) : "" }))
        if !value.isEmpty { DatePicker(title, selection: Binding(get: { Self.parse(value) }, set: { value = Dates.day($0) }), displayedComponents: .date) }
    }
    static func parse(_ value: String) -> Date {
        let formatter = DateFormatter(); formatter.dateFormat = "yyyy-MM-dd"; formatter.locale = Locale(identifier: "en_US_POSIX")
        return formatter.date(from: String(value.prefix(10))) ?? Date()
    }
}
struct LogTimeSlots: View {
    @Binding var value: String
    var slots: [String] { value.split(separator: ",").map(String.init) }
    var body: some View {
        ForEach(slots.indices, id: \.self) { index in
            HStack {
                DatePicker("Time slot \(index + 1)", selection: Binding(get: { Self.parse(slots[index]) }, set: { date in var items = slots; items[index] = Dates.time(date); value = items.joined(separator: ",") }), displayedComponents: .hourAndMinute)
                Button(role: .destructive) { var items = slots; items.remove(at: index); value = items.joined(separator: ",") } label: { Image(systemName: "minus.circle") }.buttonStyle(.borderless).accessibilityLabel("Remove time slot")
            }
        }
        Button("Add time slot", systemImage: "plus") { value = (slots + [Dates.time(Date())]).joined(separator: ",") }
    }
    static func parse(_ value: String) -> Date {
        let parts = value.split(separator: ":").compactMap { Int($0) }
        return Calendar.current.date(bySettingHour: parts.first ?? 0, minute: parts.dropFirst().first ?? 0, second: 0, of: Date()) ?? Date()
    }
}
