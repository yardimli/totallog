import SwiftUI

extension Store {
    func goalPeriod(_ goal: Record, day: Date) -> DateInterval? {
        if goal.text("period") == "none" { return nil }
        return calendar.dateInterval(of: goal.text("period") == "monthly" ? .month : goal.text("period") == "weekly" ? .weekOfYear : .day, for: day)
    }
    func goalEntries(_ goal: Record, day: Date) -> [Record] {
        var values = goal["entries"]?.array.map(\.object) ?? []
        let sources = goal["sources"]?.array.map(\.object) ?? []
        let linked = Set(sources.filter { $0.text("type") == "event" }.map { $0.text("task_definition_id") })
        for op in disk.operations {
            let manual = op.kind == "goals.progress" && resolved(op.target_id) == goal.recordID
            let event = op.kind == "events.create" && linked.contains(resolved(op.data.text("task_definition_id")))
            guard manual || event else { continue }
            let date = OptionalLogDate.parse(op.data.text(manual ? "occurred_on" : "log_date"))
            values.append(["id": .string(op.id), "points": manual ? op.data["points"] ?? .number(0) : .number(1), "note": .string(manual ? op.data.text("note") : "Pending event"), "occurred_at": .string(Dates.stamp(date)), "pending": .bool(true)])
        }
        let interval = goalPeriod(goal, day: day)
        let start = goal.text("start_date").isEmpty ? Date.distantPast : OptionalLogDate.parse(goal.text("start_date"))
        let end = goal.text("end_date").isEmpty ? Date.distantFuture : calendar.date(byAdding: .day, value: 1, to: OptionalLogDate.parse(goal.text("end_date")))!
        let asOf = calendar.date(byAdding: .day, value: 1, to: calendar.startOfDay(for: day))!
        return values.filter {
            guard let date = Dates.parse($0.text("occurred_at")) else { return false }
            return date >= start && date < end && date < asOf && (interval == nil || (date >= interval!.start && date < interval!.end))
        }.sorted { $0.text("occurred_at") > $1.text("occurred_at") }
    }
    func goalTotal(_ goal: Record, day: Date) -> Int { goalEntries(goal, day: day).reduce(0) { $0 + (Int($1.text("points")) ?? 0) } }
    func goalLastActivity(_ goal: Record) -> String {
        guard let date = goalEntries(goal, day: Date()).first.flatMap({ Dates.parse($0.text("occurred_at")) }) else { return "No activity" }
        let formatter = RelativeDateTimeFormatter(); formatter.unitsStyle = .short
        return formatter.localizedString(for: date, relativeTo: Date())
    }
}
struct GoalDetail: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var goalID: String
    @State private var day = Date()
    @State private var points = 1
    @State private var note = ""
    @State private var edit = false
    @State private var deleting = false
    var goal: Record { store.records("goals").first { $0.recordID == goalID } ?? [:] }
    var total: Int { store.goalTotal(goal, day: day) }
    var component: Calendar.Component { goal.text("period") == "monthly" ? .month : goal.text("period") == "weekly" ? .weekOfYear : .day }
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                HStack { RecordIcon(record: goal); Text(goal.text("name")).font(.title2.bold()); Spacer() }
                if goal.text("period") != "none" {
                    HStack { Button { move(-1) } label: { Image(systemName: "chevron.left").frame(width: 44, height: 32) }.accessibilityLabel("Previous period"); Spacer(); Button("Current") { day = Date() }; Spacer(); Button { move(1) } label: { Image(systemName: "chevron.right").frame(width: 44, height: 32) }.accessibilityLabel("Next period") }.buttonStyle(.bordered)
                }
                VStack(alignment: .leading, spacing: 16) {
                    Text(periodLabel(day)).font(.subheadline).foregroundStyle(.secondary)
                    HStack(alignment: .firstTextBaseline) { Text("\(total)").font(.largeTitle.bold()); Text("/ \(goal.text("target_points")) points").foregroundStyle(.secondary); Spacer(); LogBadge(title: "\(min(100, Int(Double(total) / max(1, Double(goal.text("target_points")) ?? 1) * 100)))%") }
                    ProgressView(value: min(max(0, Double(total)), max(1, Double(goal.text("target_points")) ?? 1)), total: max(1, Double(goal.text("target_points")) ?? 1)).tint(Color(logHex: goal.text("color")))
                    Text("\(goal.text("period").capitalized) goal · \(store.goalLastActivity(goal))").font(.caption).foregroundStyle(.secondary)
                }.logCard()
                VStack(alignment: .leading, spacing: 12) {
                    Text("Progress sources").font(.headline)
                    ForEach(goal["sources"]?.array.map(\.object) ?? [], id: \.recordID) { source in
                        Label(source.text("type") == "github" ? "GitHub: \(source.text("github_project"))" : store.records("tasks").first { $0.recordID == source.text("task_definition_id") }?.text("name") ?? "Event", systemImage: source.text("type") == "github" ? "laptopcomputer" : "checkmark.circle").font(.subheadline)
                    }
                    if goal.flag("manual_enabled") { Label("Manual input", systemImage: "plus.circle").font(.subheadline) }
                }.frame(maxWidth: .infinity, alignment: .leading).logCard()
                if goal.flag("manual_enabled") {
                    VStack(alignment: .leading, spacing: 12) { Text("Add progress").font(.headline); DatePicker("Date", selection: $day, displayedComponents: .date); TextField("Points", value: $points, format: .number).keyboardType(.numberPad); TextField("Comment", text: $note); Button("Record progress") { if store.enqueue("goals.progress", target: goalID, data: ["points": .number(Double(points)), "note": .string(note), "occurred_on": .string(Dates.day(day))]) { note = "" } }.buttonStyle(.borderedProminent).disabled(points < 1) }.logCard()
                }
                VStack(alignment: .leading, spacing: 14) {
                    Text("Activity in this period").font(.headline)
                    if store.goalEntries(goal, day: day).isEmpty { Text("No activity in this period.").foregroundStyle(.secondary) }
                    ForEach(store.goalEntries(goal, day: day), id: \.recordID) { entry in
                        HStack(alignment: .top) { LogBadge(title: "+\(entry.text("points"))"); VStack(alignment: .leading, spacing: 4) { Text(entry.text("note").isEmpty ? "Progress" : entry.text("note")).font(.subheadline.bold()); if let date = Dates.parse(entry.text("occurred_at")) { Text(date.formatted(date: .abbreviated, time: .shortened)).font(.caption).foregroundStyle(.secondary) }; if entry.flag("pending") { Text("Pending sync").font(.caption).foregroundStyle(.secondary) } } }; Divider()
                    }
                }.frame(maxWidth: .infinity, alignment: .leading).logCard()
                if goal.text("period") != "none" {
                    VStack(alignment: .leading, spacing: 12) {
                        Text("Period history").font(.headline)
                        ForEach(0..<12) { index in
                            let date = store.calendar.date(byAdding: component, value: -index, to: day) ?? day
                            Button { day = date } label: { HStack { Text(periodLabel(date)).font(.subheadline); Spacer(); Text("\(store.goalTotal(goal, day: date))/\(goal.text("target_points"))").bold() }.padding(12).background(Color.primary.opacity(0.04), in: RoundedRectangle(cornerRadius: 12)) }.buttonStyle(.plain)
                        }
                    }.logCard()
                }
                Button("Delete goal", role: .destructive) { deleting = true }.padding()
            }.padding(16)
        }.plannerBackground().navigationTitle("Goal details").navigationBarTitleDisplayMode(.inline)
            .toolbar { Button("Goal setup") { edit = true } }
            .sheet(isPresented: $edit) { NavigationStack { GoalEditor(record: goal) } }
            .confirmationDialog("Delete this goal?", isPresented: $deleting) { Button("Delete", role: .destructive) { if store.enqueue("goals.delete", target: goalID, data: [:]) { dismiss() } } }
    }
    func move(_ value: Int) { day = store.calendar.date(byAdding: component, value: value, to: day) ?? day }
    func periodLabel(_ date: Date) -> String {
        guard let interval = store.goalPeriod(goal, day: date), let end = store.calendar.date(byAdding: .day, value: -1, to: interval.end) else { return "\(goal.text("start_date").isEmpty ? "Open start" : String(goal.text("start_date").prefix(10))) → \(goal.text("end_date").isEmpty ? "Open end" : String(goal.text("end_date").prefix(10)))" }
        return interval.start.formatted(.dateTime.month(.abbreviated).day()) + " – " + end.formatted(.dateTime.month(.abbreviated).day().year())
    }
}
