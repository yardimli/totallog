import SwiftUI

struct DefinitionsView: View {
    @EnvironmentObject var store: Store
    @State private var adding = false
    var body: some View {
        List {
            ForEach(store.records("tasks"), id: \.recordID) { task in
                NavigationLink { DefinitionEditor(record: task) } label: {
                    HStack { RecordIcon(record: task); VStack(alignment: .leading) { Text(task.text("name")).font(.headline); Text(task.text("recurrence_type").capitalized + " · " + task.list("scheduled_times").joined(separator: ", ")).font(.caption).foregroundStyle(.secondary) }; if task.flag("pending") { Image(systemName: "clock") } }
                }
            }.onMove { source, destination in
                var tasks = store.records("tasks"); tasks.move(fromOffsets: source, toOffset: destination)
                for (position, task) in tasks.enumerated() { _ = store.enqueue("tasks.position", target: task.recordID, data: ["position": .number(Double(position))]) }
            }
        }.navigationTitle("Events").toolbar { EditButton() }.toolbar { Button { adding = true } label: { Image(systemName: "plus") } }
            .sheet(isPresented: $adding) { NavigationStack { DefinitionEditor() } }
            .refreshable { await store.sync() }
    }
}
struct DefinitionEditor: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var record: Record = [:]
    @State private var name = ""
    @State private var emoji = "✅"
    @State private var color = "#4f46e5"
    @State private var icon = ""
    @State private var removeIcon = false
    @State private var options = ""
    @State private var recurrence = "daily"
    @State private var weekdays: Set<Int> = []
    @State private var monthDays = ""
    @State private var times = ""
    @State private var sticky = false
    @State private var visibleAfter = ""
    @State private var count = 1
    @State private var deleting = false
    var body: some View {
        Form {
            Section("Custom icon") { IconPicker(icon: $icon, remove: $removeIcon) }
            Section("Appearance") { TextField("Name", text: $name); TextField("Emoji", text: $emoji); TextField("Color (#RRGGBB)", text: $color).autocorrectionDisabled() }
            Section("Values") { TextField("Options separated by commas", text: $options); Stepper("Daily count: \(count)", value: $count, in: 1...999) }
            Section("Schedule") {
                Picker("Repeat", selection: $recurrence) { Text("Daily").tag("daily"); Text("Weekly").tag("weekly"); Text("Monthly").tag("monthly") }
                if recurrence == "weekly" { ForEach(1...7, id: \.self) { day in Toggle(["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"][day - 1], isOn: Binding(get: { weekdays.contains(day) }, set: { if $0 { weekdays.insert(day) } else { weekdays.remove(day) } })) } }
                if recurrence == "monthly" { TextField("Days, e.g. 1,15,28", text: $monthDays).keyboardType(.numbersAndPunctuation) }
                TextField("Times, e.g. 08:30,17:00", text: $times).keyboardType(.numbersAndPunctuation)
                Toggle("Keep in planner", isOn: $sticky)
                if sticky { TextField("Visible after (HH:mm, optional)", text: $visibleAfter).keyboardType(.numbersAndPunctuation) }
            }
            if !record.isEmpty { Section { Button("Delete event definition", role: .destructive) { deleting = true }; Text("Recorded entries are preserved as text logs.").font(.caption) } }
        }.navigationTitle(record.isEmpty ? "New event" : "Edit event")
            .onAppear {
                guard !record.isEmpty else { return }
                icon = record.text("icon_data"); name = record.text("name"); emoji = record.text("emoji"); color = record.text("color").hasPrefix("#") ? record.text("color") : "#4f46e5"
                options = record.list("options").joined(separator: ","); recurrence = record.text("recurrence_type"); weekdays = Set(record.list("recurrence_days").compactMap(Int.init)); monthDays = record.list("recurrence_days").joined(separator: ","); times = record.list("scheduled_times").joined(separator: ","); sticky = record.flag("is_sticky"); visibleAfter = record.text("visible_after"); count = Int(record.text("daily_default_count")) ?? 1
            }
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Save") { save() }.disabled(name.trimmingCharacters(in: .whitespaces).isEmpty) } }
            .confirmationDialog("Delete event definition?", isPresented: $deleting) { Button("Delete", role: .destructive) { if store.enqueue("tasks.delete", target: record.recordID, data: [:]) { dismiss() } } }
    }
    func save() {
        let data: Record = ["icon_data": icon.isEmpty ? .null : .string(icon), "remove_icon": .bool(removeIcon), "name": .string(name), "emoji": .string(emoji), "color": .string(color), "options_text": .string(options), "recurrence_type": .string(recurrence), "weekdays": .array(weekdays.sorted().map { .number(Double($0)) }), "month_days_text": .string(monthDays), "scheduled_times_text": .string(times), "is_sticky": .bool(sticky), "visible_after": visibleAfter.isEmpty ? .null : .string(visibleAfter), "daily_default_count": .number(Double(count))]
        if store.enqueue(record.isEmpty ? "tasks.create" : "tasks.update", target: record.isEmpty ? nil : record.recordID, data: data) { dismiss() }
    }
}
struct GoalsView: View {
    @EnvironmentObject var store: Store
    @State private var adding = false
    var body: some View {
        List {
            ForEach(store.records("goals"), id: \.recordID) { goal in
                NavigationLink { GoalDetail(goalID: goal.recordID) } label: {
                    HStack { RecordIcon(record: goal); VStack(alignment: .leading) { Text(goal.text("name")).font(.headline); Text("\(goal.text("target_points")) points · \(goal.text("period"))").foregroundStyle(.secondary).font(.caption) } }
                }
            }
        }.navigationTitle("Goals").toolbar { Button { adding = true } label: { Image(systemName: "plus") } }
            .sheet(isPresented: $adding) { NavigationStack { GoalEditor() } }.refreshable { await store.sync() }
    }
}
struct GoalEditor: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    var record: Record = [:]
    @State private var name = ""
    @State private var emoji = "🎯"
    @State private var color = "#4f46e5"
    @State private var icon = ""
    @State private var removeIcon = false
    @State private var target = 10
    @State private var period = "daily"
    @State private var manual = true
    @State private var taskID = ""
    @State private var projects = ""
    @State private var start = ""
    @State private var end = ""
    var body: some View {
        Form {
            Section("Custom icon") { IconPicker(icon: $icon, remove: $removeIcon) }
            Section("Goal") { TextField("Name", text: $name); TextField("Emoji", text: $emoji); TextField("Color (#RRGGBB)", text: $color); TextField("Target points", value: $target, format: .number).keyboardType(.numberPad); Picker("Period", selection: $period) { ForEach(["daily", "weekly", "monthly", "none"], id: \.self) { Text($0.capitalized).tag($0) } } }
            Section("Dates (optional)") { TextField("Start YYYY-MM-DD", text: $start); TextField("End YYYY-MM-DD", text: $end) }
            Section("Progress sources") {
                Toggle("Allow manual progress", isOn: $manual)
                Picker("Event", selection: $taskID) { Text("None").tag(""); ForEach(store.records("tasks"), id: \.recordID) { Text($0.text("name")).tag($0.recordID) } }
                TextField("GitHub projects separated by commas", text: $projects)
            }
        }.navigationTitle(record.isEmpty ? "New goal" : "Edit goal")
            .onAppear { guard !record.isEmpty else { return }; icon = record.text("icon_data"); name = record.text("name"); emoji = record.text("emoji"); color = record.text("color"); target = Int(record.text("target_points")) ?? 10; period = record.text("period"); manual = record.flag("manual_enabled"); start = String(record.text("start_date").prefix(10)); end = String(record.text("end_date").prefix(10)); let sources = record["sources"]?.array.map(\.object) ?? []; taskID = sources.first { $0.text("type") == "event" }?.text("task_definition_id") ?? ""; projects = sources.filter { $0.text("type") == "github" }.map { $0.text("github_project") }.joined(separator: ",") }
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Save") {
                let data: Record = ["icon_data": icon.isEmpty ? .null : .string(icon), "remove_icon": .bool(removeIcon), "name": .string(name), "emoji": .string(emoji), "color": .string(color), "target_points": .number(Double(target)), "period": .string(period), "manual_enabled": .bool(manual), "task_definition_id": taskID.isEmpty ? .null : .string(taskID), "github_projects_text": .string(projects), "start_date": start.isEmpty ? .null : .string(start), "end_date": end.isEmpty ? .null : .string(end)]
                if store.enqueue(record.isEmpty ? "goals.create" : "goals.update", target: record.isEmpty ? nil : record.recordID, data: data) { dismiss() }
            }.disabled(name.isEmpty || target < 1) } }
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
    var entries: [Record] {
        var calendar = Calendar.current; calendar.firstWeekday = (Int(store.user.text("week_starts_on")) ?? 1) + 1
        let component: Calendar.Component = goal.text("period") == "weekly" ? .weekOfYear : goal.text("period") == "monthly" ? .month : .day
        let interval = calendar.dateInterval(of: component, for: day)
        var values = goal["entries"]?.array.map(\.object) ?? []
        for op in store.disk.operations where op.kind == "goals.progress" && store.resolved(op.target_id) == goalID { values.append(["id": .string(op.id), "points": op.data["points"] ?? .null, "note": op.data["note"] ?? .null, "occurred_at": .string(op.data.text("occurred_on") + "T12:00:00Z")]) }
        let linkedTasks = Set((goal["sources"]?.array.map(\.object) ?? []).filter { $0.text("type") == "event" }.map { $0.text("task_definition_id") })
        for op in store.disk.operations where op.kind == "events.create" && linkedTasks.contains(store.resolved(op.data.text("task_definition_id"))) {
            values.append(["id": .string(op.id), "points": .number(1), "note": .string("Pending event"), "occurred_at": .string(op.data.text("log_date") + "T12:00:00Z")])
        }
        return values.filter { entry in guard let date = Dates.parse(entry.text("occurred_at")) else { return false }; return (goal.text("period") == "none" || interval?.contains(date) == true) && date < calendar.startOfDay(for: day).addingTimeInterval(86400) }
    }
    var total: Int { entries.reduce(0) { $0 + (Int($1.text("points")) ?? 0) } }
    var body: some View {
        List {
            Section { HStack { RecordIcon(record: goal); Text(goal.text("name")).font(.title2) }; DatePicker("As of", selection: $day, displayedComponents: .date); ProgressView(value: min(Double(total), Double(goal.text("target_points")) ?? 1), total: max(1, Double(goal.text("target_points")) ?? 1)); Text("\(total) / \(goal.text("target_points")) points") }
            if goal.flag("manual_enabled") { Section("Add progress") { TextField("Points", value: $points, format: .number).keyboardType(.numberPad); TextField("Comment", text: $note); Button("Record progress") { _ = store.enqueue("goals.progress", target: goalID, data: ["points": .number(Double(points)), "note": .string(note), "occurred_on": .string(Dates.day(day))]); note = "" }.disabled(points < 1) } }
            Section("History for this period") { ForEach(entries, id: \.recordID) { entry in VStack(alignment: .leading) { Text("+\(entry.text("points")) · \(entry.text("note"))"); Text(entry.text("occurred_at")).font(.caption).foregroundStyle(.secondary) } } }
            Section { Button("Edit goal") { edit = true }; Button("Delete goal", role: .destructive) { deleting = true } }
        }.navigationTitle("Goal").sheet(isPresented: $edit) { NavigationStack { GoalEditor(record: goal) } }
            .confirmationDialog("Delete this goal?", isPresented: $deleting) { Button("Delete", role: .destructive) { if store.enqueue("goals.delete", target: goalID, data: [:]) { dismiss() } } }
    }
}
