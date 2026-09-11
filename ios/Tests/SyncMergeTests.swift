import Foundation

@main struct SyncMergeTests {
    static func main() throws {
        let create = Operation(kind: "tasks.create", edited_at: Dates.stamp(), data: ["name": .string("Water")])
        let child = Operation(kind: "events.create", edited_at: Dates.stamp(), data: ["task_definition_id": .string(create.id)])
        let whileInFlight = Operation(kind: "blocks.create", edited_at: Dates.stamp(), data: ["content": .string("Do not lose me")])
        var disk = DiskState()
        disk.operations = [create, child, whileInFlight]
        let childWire = child.wire
        SyncMerge.apply(to: &disk, results: [.object(["id": .string(create.id), "status": .string("applied"), "entity_id": .number(42)]), .object(["id": .string(child.id), "status": .string("rejected"), "message": .string("Select a value")])], submitted: [create, child], snapshot: ["tasks": .array([])])
        precondition(disk.operations.count == 2, "Edits added during upload must survive")
        precondition(disk.aliases[create.id] == "42")
        precondition(disk.operations[0].wire == childWire, "Retry payloads must remain immutable")
        precondition(disk.operations[0].error == "Select a value")
        let restored = try JSONDecoder().decode(DiskState.self, from: JSONEncoder().encode(disk))
        precondition(restored.operations[1].data.text("content") == "Do not lose me", "Queue survives relaunch")
        precondition(restored.aliases[create.id] == "42", "Offline reference aliases survive relaunch")
        SyncMerge.apply(to: &disk, results: [.object(["id": .string(whileInFlight.id), "status": .string("applied")])], submitted: [child], snapshot: [:])
        precondition(disk.operations.count == 2, "Unsubmitted operations cannot be acknowledged")
        SyncMerge.apply(to: &disk, results: [.object(["id": .string(child.id), "status": .string("conflict"), "message": .string("Server is newer")])], submitted: [child], snapshot: [:])
        precondition(disk.messages.first?.contains(create.id) == true, "Conflict keeps complete local payload for recovery")
        precondition(disk.operations.count == 1)
        print("PASS: immutable retries, in-flight edits, durable queue and aliases, result scoping, conflict recovery")
    }
}
