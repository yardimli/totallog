import SwiftUI

struct JournalView: View {
    @EnvironmentObject var store: Store
    @State private var day = Date()
    @State private var mode = "Day"
    @State private var add = false
    @State private var showHidden = false
    @State private var chooseDate = false
    @State private var selectedBlock: Record = [:]
    @State private var details = false
    var blocks: [Record] { store.blocks(day: Dates.day(day)).filter { showHidden || !$0.flag("is_hidden") } }
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                VStack(spacing: 14) {
                    Picker("View", selection: $mode) { ForEach(["Day", "Week", "Month"], id: \.self) { Text($0).tag($0) } }.pickerStyle(.segmented)
                    HStack {
                        Button { move(-1) } label: { Image(systemName: "chevron.left").frame(width: 44, height: 32) }.accessibilityLabel("Previous \(mode)")
                        Spacer()
                        Button { chooseDate = true } label: { Text(periodTitle).font(.subheadline.bold()).multilineTextAlignment(.center) }
                        Spacer()
                        Button { move(1) } label: { Image(systemName: "chevron.right").frame(width: 44, height: 32) }.accessibilityLabel("Next \(mode)")
                    }
                    Button("Today") { day = Date() }.buttonStyle(.bordered)
                }.logCard()
                if mode == "Day" { eventPills }
                if !store.records("goals").isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack { ForEach(store.records("goals"), id: \.recordID) { goal in
                            NavigationLink { GoalDetail(goalID: goal.recordID) } label: { RecordPill(record: goal, subtitle: "\(store.goalTotal(goal, day: day))/\(goal.text("target_points")) points · \(store.goalLastActivity(goal))") }.buttonStyle(.plain)
                        } }
                    }
                }
                if mode == "Day" { timeline } else { calendarGrid }
            }.padding(16)
        }.plannerBackground().navigationTitle("Total Log").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) { Menu {
                    Button("New log", systemImage: "square.and.pencil") { add = true }
                    Menu("Record event") { ForEach(availableTasks, id: \.recordID) { task in NavigationLink(task.text("name")) { RecordEventView(definition: task, day: day) } } }
                    Toggle("Show hidden entries", isOn: $showHidden)
                    NavigationLink("Chat about this day") { ChatView(day: day) }
                } label: { Image(systemName: "plus.circle") } }
            }
            .refreshable { await refreshDay() }
            .task(id: Dates.day(day)) { await refreshDay() }
            .sheet(isPresented: $details) { NavigationStack { BlockDetail(block: selectedBlock).toolbar { ToolbarItem(placement: .cancellationAction) { Button("Close") { details = false } } } } }
            .sheet(isPresented: $add) { NavigationStack { LogEditor(day: day) } }
            .sheet(isPresented: $chooseDate) { NavigationStack { DatePicker("Date", selection: $day, displayedComponents: .date).datePickerStyle(.graphical).padding().navigationTitle("Go to date").toolbar { Button("Done") { chooseDate = false } } }.presentationDetents([.medium, .large]) }
    }
    var eventPills: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack { ForEach(availableTasks.filter { $0.flag("is_sticky") && (!Calendar.current.isDateInToday(day) || $0.text("visible_after") <= Dates.time(Date())) }, id: \.recordID) { task in
                NavigationLink { RecordEventView(definition: task, day: day) } label: {
                    RecordPill(record: task, subtitle: "\(store.blocks(day: Dates.day(day)).filter { $0["task_event"]?.object.text("task_definition_id") == task.recordID }.count) recorded")
                }.buttonStyle(.plain)
            } }
        }
    }
    var timeline: some View {
        LazyVStack(spacing: 10) {
            if blocks.isEmpty { ContentUnavailableView("No entries", systemImage: "book", description: Text("Add a log or record an event to start this day.")) }
            ForEach(blocks, id: \.recordID) { block in
                HStack(alignment: .top, spacing: 10) {
                    Text(Dates.parse(block.text("occurred_at")).map(store.clock) ?? "—").font(.caption.monospacedDigit()).foregroundStyle(.secondary).frame(width: 48).padding(.top, 20)
                    Button { selectedBlock = block; details = true } label: { BlockRow(block: block, showsDate: false).frame(maxWidth: .infinity, alignment: .leading).logCard() }.buttonStyle(.plain)
                }
            }
        }
    }
    var calendarGrid: some View {
        let days = visibleDays
        let grouped = Dictionary(grouping: store.blocks().filter { showHidden || !$0.flag("is_hidden") }, by: { $0.text("log_date") })
        return Group {
            if mode == "Week" {
                LazyVGrid(columns: [GridItem(.adaptive(minimum: 140))], spacing: 10) { ForEach(days, id: \.self) { date in dayCard(date, count: grouped[Dates.day(date)]?.count ?? 0, compact: false) } }
            } else {
                VStack(spacing: 8) {
                    HStack { ForEach(0..<7) { offset in Text(store.calendar.shortWeekdaySymbols[(store.calendar.firstWeekday - 1 + offset) % 7]).font(.caption2).foregroundStyle(.secondary).frame(maxWidth: .infinity) } }
                    LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 4), count: 7), spacing: 6) { ForEach(days, id: \.self) { date in dayCard(date, count: grouped[Dates.day(date)]?.count ?? 0, compact: true) } }
                }
            }
        }
    }
    func dayCard(_ date: Date, count: Int, compact: Bool) -> some View {
        let today = store.calendar.isDateInToday(date)
        return Button { day = date; mode = "Day" } label: {
            VStack(alignment: .leading, spacing: compact ? 7 : 16) {
                HStack {
                    if !compact { Text(date.formatted(.dateTime.weekday(.abbreviated))).font(.caption).foregroundStyle(.secondary); Spacer() }
                    Text(date.formatted(.dateTime.day())).font(compact ? .caption.bold() : .headline).padding(5).foregroundStyle(today ? Color.white : Color.primary).background(today ? Color.logAccent : Color.clear, in: Circle())
                }
                Text(compact ? "\(count)" : "\(count) log entries").font(.caption2).foregroundStyle(.secondary)
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 3), count: compact ? 3 : 8), spacing: 4) {
                    ForEach(0..<min(count, compact ? 9 : 40), id: \.self) { _ in Rectangle().fill(Color.logAccent.opacity(0.8)).frame(width: 4, height: 4) }
                }.frame(height: compact ? 20 : 36, alignment: .top)
                Spacer(minLength: 0)
            }.padding(compact ? 5 : 14).frame(maxWidth: .infinity).frame(height: compact ? 100 : 150)
                .background(today ? Color.logAccent.opacity(0.08) : Color(uiColor: .secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: compact ? 10 : 18))
                .overlay(RoundedRectangle(cornerRadius: compact ? 10 : 18).stroke(today ? Color.logAccent : Color.primary.opacity(0.08)))
                .opacity(mode == "Month" && !store.calendar.isDate(date, equalTo: day, toGranularity: .month) ? 0.4 : 1)
        }.buttonStyle(.plain).accessibilityLabel("\(date.formatted(date: .complete, time: .omitted)), \(count) log entries")
    }
    var visibleDays: [Date] {
        let cal = store.calendar
        let interval = cal.dateInterval(of: mode == "Month" ? .month : .weekOfYear, for: day)!
        let start = cal.dateInterval(of: .weekOfYear, for: interval.start)!.start
        let end = mode == "Month" ? cal.dateInterval(of: .weekOfYear, for: cal.date(byAdding: .day, value: -1, to: interval.end)!)!.end : interval.end
        return (0..<(cal.dateComponents([.day], from: start, to: end).day ?? 7)).compactMap { cal.date(byAdding: .day, value: $0, to: start) }
    }
    var periodTitle: String {
        if mode == "Month" { return day.formatted(.dateTime.month(.wide).year()) }
        if mode == "Week", let first = visibleDays.first, let last = visibleDays.last { return first.formatted(.dateTime.month(.abbreviated).day()) + " – " + last.formatted(.dateTime.month(.abbreviated).day().year()) }
        return day.formatted(.dateTime.weekday(.abbreviated).month(.abbreviated).day().year())
    }
    func move(_ delta: Int) { day = store.calendar.date(byAdding: mode == "Month" ? .month : mode == "Week" ? .weekOfYear : .day, value: delta, to: day) ?? day }
    func refreshDay() async { guard store.online else { return }; do { _ = try await store.call("refresh-day/\(Dates.day(day))", data: [:]); await store.sync() } catch { store.error = error.localizedDescription } }
    var availableTasks: [Record] {
        let parts = store.calendar.dateComponents([.weekday, .day], from: day)
        let weekday = ((parts.weekday ?? 1) + 5) % 7 + 1
        return store.records("tasks").filter {
            ($0.text("recurrence_type") != "weekly" || $0.list("recurrence_days").contains(String(weekday))) && ($0.text("recurrence_type") != "monthly" || $0.list("recurrence_days").contains(String(parts.day ?? 1))) && ($0["is_active"] == nil || $0.flag("is_active"))
        }
    }
}
struct BlockRow: View {
    @EnvironmentObject var store: Store
    var block: Record
    var showsDate = true
    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            RecordIcon(record: block)
            VStack(alignment: .leading, spacing: 7) {
                LogBadge(title: block.text("type").replacingOccurrences(of: "sensor_", with: "").replacingOccurrences(of: "_", with: " ").uppercased(), color: block.text("type") == "event" ? .green : .cyan)
                if let event = block["task_event"]?.object, !event.isEmpty { Text(event.text("task_name")).font(.headline); if !event.text("selected_value").isEmpty { Text(event.text("selected_value")).font(.subheadline) } }
                if !block.text("content").isEmpty { Text(block.text("content")).font(.subheadline).lineLimit(4) }
                HStack {
                    if showsDate { Text(block.text("log_date")); if let date = Dates.parse(block.text("occurred_at")) { Text(store.clock(date)) } }
                    if block.flag("pending") { Label("Pending", systemImage: "clock.arrow.circlepath") }
                    if block.flag("is_hidden") { Label("Hidden", systemImage: "eye.slash") }
                }.font(.caption).foregroundStyle(.secondary)
            }
        }
    }
}
