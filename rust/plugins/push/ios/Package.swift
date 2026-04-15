// swift-tools-version:5.5
// Package manifest for the NativeBlade push notifications iOS plugin.

import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-push",
    platforms: [
        .iOS(.v13),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-push",
            type: .static,
            targets: ["tauri-plugin-nativeblade-push"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api")
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-push",
            dependencies: [
                .byName(name: "Tauri")
            ],
            path: "Sources"
        ),
    ]
)
