import Foundation

/// Called only after both upload results and a complete snapshot have arrived.
/// The caller persists the whole resulting state atomically, or rolls back.
enum SyncMerge {
    static func apply(to disk: inout DiskState, results: [JSONValue], submitted: [Operation], snapshot: Record) {
        let submittedIDs = Set(submitted.map(\.id))
        for value in results {
            let result = value.object
            let id = result.text("id")
            guard submittedIDs.contains(id), let index = disk.operations.firstIndex(where: { $0.id == id }) else { continue }
            switch result.text("status") {
            case "rejected": disk.operations[index].error = result.text("message")
            case "applied", "conflict":
                if result.text("status") == "conflict" {
                    let original = disk.operations[index]
                    let encoder = JSONEncoder(); encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
                    let content = (try? encoder.encode(original.data)).flatMap { String(data: $0, encoding: .utf8) } ?? ""
                    disk.messages.append("\(original.kind): \(result.text("message"))\nLocal change:\n\(content)")
                }
                let remoteID = result.text("entity_id")
                if !remoteID.isEmpty { disk.aliases[id] = remoteID }
                disk.operations.remove(at: index)
            default: break // Unknown responses never acknowledge local work.
            }
        }
        disk.snapshot = snapshot
    }
}
