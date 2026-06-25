// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-payments",
    platforms: [
        // StoreKit 2 needs iOS 15 / macOS 12. The macOS floor is only for the
        // swift-rs resolution pass; the plugin runs on iOS.
        .iOS(.v15),
        .macOS(.v12),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-payments",
            type: .static,
            targets: ["tauri-plugin-nativeblade-payments"]
        ),
    ],
    dependencies: [
        // StoreKit ships with the OS, so there is no third-party package here.
        .package(name: "Tauri", path: "../.tauri/tauri-api"),
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-payments",
            dependencies: [
                .byName(name: "Tauri"),
            ],
            path: "Sources"
        ),
    ]
)
