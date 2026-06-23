// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-admob",
    platforms: [
        // ATT is part of the consent flow, and `ATTrackingManager` is iOS 14+.
        .iOS(.v14),
    ],
    products: [
        .library(
            name: "tauri-plugin-nativeblade-admob",
            type: .static,
            targets: ["tauri-plugin-nativeblade-admob"]
        ),
    ],
    dependencies: [
        .package(name: "Tauri", path: "../.tauri/tauri-api"),
        .package(url: "https://github.com/googleads/swift-package-manager-google-mobile-ads.git", .upToNextMinor(from: "12.14.0")),
        .package(url: "https://github.com/googleads/swift-package-manager-google-user-messaging-platform.git", .upToNextMinor(from: "3.1.0")),
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-admob",
            dependencies: [
                .byName(name: "Tauri"),
                .product(name: "GoogleMobileAds", package: "swift-package-manager-google-mobile-ads"),
                .product(name: "GoogleUserMessagingPlatform", package: "swift-package-manager-google-user-messaging-platform"),
            ],
            path: "Sources"
        ),
    ]
)
