// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-sharing",
    platforms: [
        .iOS(.v14),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-sharing",
            type: .static,
            targets: ["tauri-plugin-nativeblade-sharing"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api")
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-sharing",
            dependencies: [
                .byName(name: "Tauri")
            ],
            path: "Sources"
        ),
    ]
)
