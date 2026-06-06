// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-secure-storage",
    platforms: [
        .iOS(.v14),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-secure-storage",
            type: .static,
            targets: ["tauri-plugin-nativeblade-secure-storage"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api")
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-secure-storage",
            dependencies: [
                .byName(name: "Tauri")
            ],
            path: "Sources"
        ),
    ]
)
