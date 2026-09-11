import Foundation

// A lossless JSON value preserves sensor payloads as the server adds new fields.
enum JSONValue: Codable, Equatable {
    case string(String), number(Double), bool(Bool), object([String: JSONValue]), array([JSONValue]), null
    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null }
        else if let v = try? c.decode(Bool.self) { self = .bool(v) }
        else if let v = try? c.decode(Double.self) { self = .number(v) }
        else if let v = try? c.decode(String.self) { self = .string(v) }
        else if let v = try? c.decode([String: JSONValue].self) { self = .object(v) }
        else { self = .array(try c.decode([JSONValue].self)) }
    }
    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let v): try c.encode(v)
        case .number(let v): try c.encode(v)
        case .bool(let v): try c.encode(v)
        case .object(let v): try c.encode(v)
        case .array(let v): try c.encode(v)
        case .null: try c.encodeNil()
        }
    }
    var text: String { switch self { case .string(let v): return v; case .number(let v): return v == floor(v) ? String(Int(v)) : String(v); case .bool(let v): return v ? "true" : "false"; default: return "" } }
    var object: [String: JSONValue] { if case .object(let v) = self { return v }; return [:] }
    var array: [JSONValue] { if case .array(let v) = self { return v }; return [] }
    var flag: Bool { self == .bool(true) || self == .number(1) }
}
typealias Record = [String: JSONValue]
extension Dictionary where Key == String, Value == JSONValue {
    func text(_ key: String) -> String { self[key]?.text ?? "" }
    func flag(_ key: String) -> Bool { self[key]?.flag ?? false }
    func list(_ key: String) -> [String] { self[key]?.array.map(\.text) ?? [] }
    var recordID: String { text("id") }
}
struct Operation: Codable, Identifiable {
    var id = UUID().uuidString
    var kind: String
    var target_id: String?
    var edited_at: String
    var data: Record
    var error: String?
    // Only these fields are sent; error is local UI state.
    var wire: Record { ["id": .string(id), "kind": .string(kind), "target_id": target_id.map(JSONValue.string) ?? .null, "edited_at": .string(edited_at), "data": .object(data)] }
}
struct DiskState: Codable {
    var server: String = "https://total-log.com"
    var userID: String = ""
    var snapshot: Record = [:]
    var operations: [Operation] = []
    var aliases: [String: String] = [:]
    var messages: [String] = []
    var lastSync: Date?
    var clockOffset: Double = 0
}
enum Dates {
    static func stamp(_ date: Date = Date()) -> String { let f = ISO8601DateFormatter(); f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]; return f.string(from: date) }
    static func parse(_ text: String) -> Date? {
        let f = ISO8601DateFormatter(); f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f.date(from: text) ?? ISO8601DateFormatter().date(from: text)
    }
    static func day(_ date: Date) -> String { let f = DateFormatter(); f.locale = Locale(identifier: "en_US_POSIX"); f.dateFormat = "yyyy-MM-dd"; return f.string(from: date) }
    static func time(_ date: Date) -> String { let f = DateFormatter(); f.locale = Locale(identifier: "en_US_POSIX"); f.dateFormat = "HH:mm"; return f.string(from: date) }
}

struct BrowserLoginPending: Codable {
    var requestID: String
    var state: String
    var verifier: String
    var server: String
    var startedAt: Date
}
