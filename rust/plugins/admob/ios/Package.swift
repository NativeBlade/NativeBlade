// swift-tools-version:5.5
import PackageDescription

let package = Package(
    name: "tauri-plugin-nativeblade-admob",
    platforms: [
        .iOS(.v14),
        .macOS(.v10_15),
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
        .package(url: "https://github.com/googleads/swift-package-manager-google-mobile-ads.git", from: "11.0.0"),
        .package(url: "https://github.com/googleads/swift-package-manager-google-user-messaging-platform.git", from: "2.0.0"),
    ],
    targets: [
        .target(
            name: "tauri-plugin-nativeblade-admob",
            dependencies: [
                .byName(name: "Tauri"),
                // iOS-only: the Google SDKs ship no macOS slice, and the macOS
                // pass swift-rs runs must not pull them in.
                .product(
                    name: "GoogleMobileAds",
                    package: "swift-package-manager-google-mobile-ads",
                    condition: .when(platforms: [.iOS])
                ),
                .product(
                    name: "UserMessagingPlatform",
                    package: "swift-package-manager-google-user-messaging-platform",
                    condition: .when(platforms: [.iOS])
                ),
            ],
            path: "Sources"
        ),
    ]
)
