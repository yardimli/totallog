import SwiftUI
import CoreLocation

@MainActor final class LocationCapture: NSObject, ObservableObject, @preconcurrency CLLocationManagerDelegate {
    @Published var location: CLLocation?
    @Published var error: String?
    @Published var waiting = false
    private let manager = CLLocationManager()
    override init() { super.init(); manager.delegate = self; manager.desiredAccuracy = kCLLocationAccuracyHundredMeters }
    func request() { waiting = true; error = nil; if manager.authorizationStatus == .notDetermined { manager.requestWhenInUseAuthorization() } else { locate() } }
    private func locate() {
        switch manager.authorizationStatus {
        case .authorizedAlways, .authorizedWhenInUse: manager.requestLocation()
        case .denied, .restricted: waiting = false; error = "Location access is disabled. You can enable it in iPhone Settings."
        default: break
        }
    }
    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) { if waiting { locate() } }
    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) { location = locations.last; waiting = false }
    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) { self.error = error.localizedDescription; waiting = false }
}
struct EventLocationView: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) var dismiss
    @StateObject private var capture = LocationCapture()
    var eventID: String
    @State private var city = ""
    @State private var suburb = ""
    var body: some View {
        Form {
            Section { Button("Use current location") { capture.request() }; if capture.waiting { ProgressView() }; if let error = capture.error { Text(error).foregroundStyle(.red) }; if let location = capture.location { Text("\(location.coordinate.latitude), \(location.coordinate.longitude)"); Text("Accuracy: \(Int(location.horizontalAccuracy)) m") } }
            Section("Place (optional)") { TextField("City", text: $city); TextField("Suburb", text: $suburb) }
            Button("Save location") {
                guard let location = capture.location else { return }
                if store.enqueue("events.location", target: eventID, data: ["latitude": .number(location.coordinate.latitude), "longitude": .number(location.coordinate.longitude), "location_accuracy": .number(max(0, location.horizontalAccuracy)), "city": .string(city), "suburb": .string(suburb)]) { dismiss() }
            }.disabled(capture.location == nil)
        }.navigationTitle("Event location")
    }
}
