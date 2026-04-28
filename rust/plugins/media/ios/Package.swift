// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-media",
    platforms: [
        .iOS(.v14),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-media",
            type: .static,
            targets: ["tauri-plugin-nativeblade-media"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api")
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-media",
            dependencies: [
                .byName(name: "Tauri")
            ],
            path: "Sources"
        ),
    ]
)
