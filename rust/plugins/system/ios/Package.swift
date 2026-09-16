// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-system",
    platforms: [
        .iOS(.v15),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-system",
            type: .static,
            targets: ["tauri-plugin-nativeblade-system"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api")
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-system",
            dependencies: [
                .byName(name: "Tauri")
            ],
            path: "Sources"
        ),
    ]
)
