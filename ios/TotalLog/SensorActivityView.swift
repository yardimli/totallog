import SwiftUI

struct SensorActivityView: View {
    var records: [Record]
    var desktop: Bool
    var rows: [(name: String, seconds: Int)] {
        let groups = Dictionary(grouping: records) { record in
            let name = record.text(desktop ? "application" : "domain")
            return name.isEmpty ? record.text(desktop ? "process_name" : "url") : name
        }
        return groups.map { (name: $0.key, seconds: $0.value.reduce(0) { $0 + (Int($1.text("duration_seconds")) ?? 0) }) }.sorted { $0.seconds == $1.seconds ? $0.name < $1.name : $0.seconds > $1.seconds }
    }
    var body: some View {
        ForEach(rows, id: \.name) { row in
            HStack { Text(row.name).font(.headline); Spacer(); LogBadge(title: row.seconds < 60 ? "Under 1 min" : "\(row.seconds / 60) min", color: .cyan) }.logCard().listRowSeparator(.hidden).listRowBackground(Color.clear)
        }
    }
}
struct GitHubProjectPicker: View {
    @EnvironmentObject var store: Store
    @Binding var value: String
    @State private var filter = ""
    var selected: Set<String> { Set(value.split(separator: ",").map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }) }
    var projects: [String] {
        var names = selected
        for goal in store.records("goals") { for source in goal["sources"]?.array.map(\.object) ?? [] where source.text("type") == "github" { names.insert(source.text("github_project")) } }
        for block in store.blocks() { for commit in block["metadata"]?.object["commits"]?.array.map(\.object) ?? [] { names.insert(commit.text("project")) } }
        return names.filter { !$0.isEmpty && (filter.isEmpty || $0.localizedCaseInsensitiveContains(filter)) }.sorted()
    }
    var body: some View {
        TextField("Filter GitHub projects…", text: $filter).autocorrectionDisabled()
        ForEach(projects, id: \.self) { project in
            Toggle(project, isOn: Binding(get: { selected.contains(project) }, set: { enabled in var names = selected; if enabled { names.insert(project) } else { names.remove(project) }; value = names.sorted().joined(separator: ",") }))
        }
        TextField("Additional projects, separated by commas", text: $value).autocorrectionDisabled().textInputAutocapitalization(.never)
    }
}
