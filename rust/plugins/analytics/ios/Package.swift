// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-analytics",
    platforms: [
        .iOS(.v14),
        .macOS(.v10_15),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-analytics",
            type: .static,
            targets: ["tauri-plugin-nativeblade-analytics"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api"),
        .package(url: "https://github.com/firebase/firebase-ios-sdk.git", from: "12.0.0"),
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-analytics",
            dependencies: [
                .byName(name: "Tauri"),
                // iOS-only: the macOS slice of Firebase pulls Cocoa.h and breaks
                // the macOS pass swift-rs runs. The plugin only ships on iOS.
                .product(
                    name: "FirebaseAnalytics",
                    package: "firebase-ios-sdk",
                    condition: .when(platforms: [.iOS])
                ),
            ],
            path: "Sources"
        ),
    ]
)
