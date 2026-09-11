import SwiftUI

struct DefinitionsView: View {
    @EnvironmentObject var store: Store
    @State private var adding = false
    @State private var selected: Record = [:]
    var body: some View {
        List {
            VStack(alignment: .leading, spacing: 8) { Text("Repeating events & buttons").font(.title2.bold()); Text("Arrange your events in the order used in the planner.").font(.subheadline).foregroundStyle(.secondary); LogBadge(title: "\(store.records("tasks").count) events") }.listRowBackground(Color.clear).listRowSeparator(.hidden)
            ForEach(store.records("tasks"), id: \.recordID) { task in
                HStack(spacing: 12) {
                    RecordIcon(record: task).padding(8).background(Color(logHex: task.text("color")), in: RoundedRectangle(cornerRadius: 14))
                    VStack(alignment: .leading, spacing: 6) {
                        Text(task.text("name")).font(.headline)
                        Text("\(task.text("recurrence_type").capitalized) · Daily target \((Int(task.text("daily_default_count")) ?? 1) * max(1, task.list("scheduled_times").count))").font(.caption).foregroundStyle(.secondary)
                        if !task.list("options").isEmpty { Text("Values: " + task.list("options").joined(separator: ", ")).font(.caption).foregroundStyle(.secondary) }
                        LogBadge(title: task.flag("is_sticky") ? "Sticky" : "Dropdown")
                    }
                    Spacer(minLength: 0)
                    Button("Edit") { selected = task; adding = true }.buttonStyle(.bordered)
                }.logCard().listRowInsets(EdgeInsets(top: 5, leading: 16, bottom: 5, trailing: 16)).listRowBackground(Color.clear).listRowSeparator(.hidden)
            }.onMove { source, destination in
                var tasks = store.records("tasks"); tasks.move(fromOffsets: source, toOffset: destination)
                for (position, task) in tasks.enumerated() { _ = store.enqueue("tasks.position", target: task.recordID, data: ["position": .number(Double(position))]) }
            }
        }.listStyle(.plain).plannerBackground().navigationTitle("Events").toolbar { EditButton(); Button { selected = [:]; adding = true } label: { Image(systemName: "plus") } }
            .sheet(isPresented: $adding) { NavigationStack { DefinitionEditor(record: selected) } }.refreshable { await store.sync() }
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
            Section("Appearance") { TextField("Name", text: $name); TextField("Emoji", text: $emoji); ColorPicker("Button color", selection: Binding(get: { Color(logHex: color) }, set: { color = $0.logHex }), supportsOpacity: false) }
            Section("Values") { TextField("Options separated by commas", text: $options); Stepper("Daily count: \(count)", value: $count, in: 1...999) }
            Section("Schedule") {
                Picker("Repeat", selection: $recurrence) { Text("Daily").tag("daily"); Text("Weekly").tag("weekly"); Text("Monthly").tag("monthly") }
                if recurrence == "weekly" { ForEach(1...7, id: \.self) { day in Toggle(["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"][day - 1], isOn: Binding(get: { weekdays.contains(day) }, set: { if $0 { weekdays.insert(day) } else { weekdays.remove(day) } })) } }
                if recurrence == "monthly" { TextField("Days, e.g. 1,15,28", text: $monthDays).keyboardType(.numbersAndPunctuation) }
                LogTimeSlots(value: $times)
                Toggle("Sticky event", isOn: $sticky)
                if sticky {
                    Toggle("Become visible at a set time", isOn: Binding(get: { !visibleAfter.isEmpty }, set: { visibleAfter = $0 ? "09:00" : "" }))
                    if !visibleAfter.isEmpty { DatePicker("Visible after", selection: Binding(get: { LogTimeSlots.parse(visibleAfter) }, set: { visibleAfter = Dates.time($0) }), displayedComponents: .hourAndMinute) }
                }
            }
            if !record.isEmpty { Section { Button("Delete event definition", role: .destructive) { deleting = true }; Text("Recorded entries are preserved as text logs.").font(.caption) } }
        }.navigationTitle(record.isEmpty ? "New event" : "Edit event")
            .onAppear {
                guard !record.isEmpty else { return }
                icon = record.text("icon_data"); name = record.text("name"); emoji = record.text("emoji"); color = Color(logHex: record.text("color")).logHex
                options = record.list("options").joined(separator: ","); recurrence = record.text("recurrence_type"); weekdays = Set(record.list("recurrence_days").compactMap(Int.init)); monthDays = record.list("recurrence_days").joined(separator: ","); times = record.list("scheduled_times").joined(separator: ","); sticky = record.flag("is_sticky"); visibleAfter = record.text("visible_after"); count = Int(record.text("daily_default_count")) ?? 1
            }
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }; ToolbarItem(placement: .confirmationAction) { Button("Save") { save() }.disabled(name.trimmingCharacters(in: .whitespaces).isEmpty) } }
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
    @State private var selected: Record = [:]
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                VStack(alignment: .leading, spacing: 8) { Text("Goals & progress").font(.title2.bold()); Text("Choose a goal to view progress, or edit its target and sources.").font(.subheadline).foregroundStyle(.secondary) }.frame(maxWidth: .infinity, alignment: .leading).logCard()
                ForEach(store.records("goals"), id: \.recordID) { goal in
                    VStack(alignment: .leading, spacing: 12) {
                        HStack {
                            NavigationLink { GoalDetail(goalID: goal.recordID) } label: {
                                HStack(spacing: 12) { RecordIcon(record: goal).padding(8).background(Color(logHex: goal.text("color")), in: RoundedRectangle(cornerRadius: 14)); VStack(alignment: .leading, spacing: 6) { Text(goal.text("name")).font(.headline); Text("\(goal.text("target_points")) points · \(goal.text("period").capitalized) reset").font(.caption).foregroundStyle(.secondary) } }
                            }.buttonStyle(.plain)
                            Spacer()
                            Button("Edit") { selected = goal; adding = true }.buttonStyle(.bordered)
                        }
                        ProgressView(value: min(Double(store.goalTotal(goal, day: Date())), max(1, Double(goal.text("target_points")) ?? 1)), total: max(1, Double(goal.text("target_points")) ?? 1)).tint(Color(logHex: goal.text("color")))
                        Text("\(store.goalTotal(goal, day: Date()))/\(goal.text("target_points")) points · \(store.goalLastActivity(goal))").font(.caption).foregroundStyle(.secondary)
                    }.logCard()
                }
            }.padding(16)
        }.plannerBackground().navigationTitle("Goals").toolbar { Button { selected = [:]; adding = true } label: { Image(systemName: "plus") } }
            .sheet(isPresented: $adding) { NavigationStack { GoalEditor(record: selected) } }.refreshable { await store.sync() }
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
            Section("Goal") { TextField("Name", text: $name); TextField("Emoji", text: $emoji); ColorPicker("Goal color", selection: Binding(get: { Color(logHex: color) }, set: { color = $0.logHex }), supportsOpacity: false); TextField("Target points", value: $target, format: .number).keyboardType(.numberPad); Picker("Period", selection: $period) { ForEach(["daily", "weekly", "monthly", "none"], id: \.self) { Text($0.capitalized).tag($0) } } }
            Section("Dates (optional)") { OptionalLogDate(title: "Start date", value: $start); OptionalLogDate(title: "End date", value: $end) }
            Section("Progress sources") {
                Toggle("Allow manual progress", isOn: $manual)
                Picker("Event", selection: $taskID) { Text("None").tag(""); ForEach(store.records("tasks"), id: \.recordID) { Text($0.text("name")).tag($0.recordID) } }
                GitHubProjectPicker(value: $projects)
            }
        }.navigationTitle(record.isEmpty ? "New goal" : "Edit goal")
            .onAppear { guard !record.isEmpty else { return }; icon = record.text("icon_data"); name = record.text("name"); emoji = record.text("emoji"); color = record.text("color"); target = Int(record.text("target_points")) ?? 10; period = record.text("period"); manual = record.flag("manual_enabled"); start = String(record.text("start_date").prefix(10)); end = String(record.text("end_date").prefix(10)); let sources = record["sources"]?.array.map(\.object) ?? []; taskID = sources.first { $0.text("type") == "event" }?.text("task_definition_id") ?? ""; projects = sources.filter { $0.text("type") == "github" }.map { $0.text("github_project") }.joined(separator: ",") }
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }; ToolbarItem(placement: .confirmationAction) { Button("Save") {
                let data: Record = ["icon_data": icon.isEmpty ? .null : .string(icon), "remove_icon": .bool(removeIcon), "name": .string(name), "emoji": .string(emoji), "color": .string(color), "target_points": .number(Double(target)), "period": .string(period), "manual_enabled": .bool(manual), "task_definition_id": taskID.isEmpty ? .null : .string(taskID), "github_projects_text": .string(projects), "start_date": start.isEmpty ? .null : .string(start), "end_date": end.isEmpty ? .null : .string(end)]
                if store.enqueue(record.isEmpty ? "goals.create" : "goals.update", target: record.isEmpty ? nil : record.recordID, data: data) { dismiss() }
            }.disabled(name.isEmpty || target < 1) } }
    }
}
