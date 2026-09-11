---
title: "App Store publication with MCP"
description: "Project-aware publication checklist and official App Store sources."
---

# App Store publication with MCP

Reference reviewed on 2026-09-12. Verify the linked official sources and account notices for every submission. This tool does not fetch live policies. Requirements vary by app, device, region and account.

## Conversation and project inspection

Call `publish_ios`. It reads the `AppServiceProvider` configuration loaded by Laravel via `ShellConfig`. If `plugins()` is absent, all built-in plugins are included. Explicit optional plugins and the core plugins must be distinguished. Check custom plugins, the generated Info.plist, entitlements, privacy manifests and final archive.

Reply in the developer's language with clear sentences and no em dash or en dash. Ask up to three unanswered questions at a time. Explain options using project evidence, retain previous choices and leave unknown facts explicitly pending. Pass previous answers by question id. The tool is stateless.

## Account, identity and distribution

Confirm an active Apple Developer membership, the correct team, an appropriate App Store Connect role, current agreements and access to the app record. If monetized, check the relevant banking, tax and paid-app setup in the account. Do not ask for account passwords, certificate private keys or API secrets in chat.

For a new record, confirm app name, primary language, bundle ID, SKU and access. For updates, match the existing record and signing identity. Read `identifier`, `version` and `buildNumber` from the project and compare with uploaded builds. Do not replace an existing bundle ID. [App information](https://developer.apple.com/help/app-store-connect/reference/app-information/app-information/).

Choose TestFlight internal testing, external testing or App Store distribution. External TestFlight testing may require beta review and test information. A TestFlight approval does not publish to the App Store. [TestFlight overview](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/).

## Build and signing

Since April 28, 2026, uploads must use Xcode 26 or later and the iOS/iPadOS 26 SDK or later. Recheck the effective requirement for the submission date. `minIosVersion` is the minimum supported OS, not the build SDK requirement. [Upcoming requirements](https://developer.apple.com/news/upcoming-requirements/).

Follow the NativeBlade cloud workflow in [Publish](publish.md) or use `php artisan nativeblade:build ios` on an appropriate macOS/Xcode build environment. `php artisan nativeblade:config` applies configuration changes. Do not use the `--host` development preview for a store build. Verify the archive/IPA, distribution certificate, provisioning, team, capabilities and entitlements. An IPA path does not prove valid signing.

Upload using an available supported delivery method such as Xcode or Transporter, then wait for processing and select the matching build in App Store Connect. Do not report an upload or successful processing without evidence. [Upload builds](https://developer.apple.com/help/app-store-connect/manage-builds/upload-builds/).

## Metadata and assets

Use `store_listing` to draft and validate localized fields. App name is 2 to 30 characters; subtitle is up to 30. [App information](https://developer.apple.com/help/app-store-connect/reference/app-information/app-information/).

Description and What's New are each up to 4000 characters, promotional text up to 170. Keywords are up to 100 UTF-8 bytes according to the reference specification. Review notes are up to 4000 bytes. Keep the product description, release changes and private review instructions separate. What's New can be omitted for a first version. Add the support URL, optional marketing URL and copyright. [Version fields](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information/).

Provide a privacy policy URL, primary category and any applicable secondary category. Prepare the app icon in the build and genuine screenshots for the supported device families. Verify dimensions against the current device table instead of reusing one old iPhone size for every app. App previews are optional. [Screenshot specifications](https://developer.apple.com/help/app-store-connect/reference/app-information/screenshot-specifications/).

## Privacy, content and review information

- App Privacy covers the app and integrated third parties. Confirm data types, purposes, linkage to the user and tracking. A plugin being included does not prove it collects data. Review actual SDK configuration and backend behavior. [Manage App Privacy](https://developer.apple.com/help/app-store-connect/manage-app-information/manage-app-privacy/).
- Review PrivacyInfo.xcprivacy, required-reason APIs, approved reasons, SDK signatures and applicable third-party SDK requirements. A configured manifest is not proof the archive satisfies them. [Privacy manifests](https://developer.apple.com/documentation/bundleresources/privacy-manifest-files).
- If tracking occurs, check App Tracking Transparency and consent before tracking. Do not equate all analytics with tracking. Review the definitions and app behavior. [User privacy and data use](https://developer.apple.com/app-store/user-privacy-and-data-use/).
- Check account creation and in-app account deletion. Review third-party login against the applicable login-service rules and exceptions. Do not assume every app needs Sign in with Apple. [App Review Guidelines](https://developer.apple.com/app-store/review/guidelines/).
- Answer the current age-rating questionnaire accurately. Check children, user-generated content and regulated features when present. [Age ratings](https://developer.apple.com/help/app-store-connect/manage-app-information/set-an-app-age-rating/).
- Answer export-compliance questions based on actual encryption and applicable exemptions. HTTPS or secure storage alone does not settle the questionnaire. [Export compliance](https://developer.apple.com/help/app-store-connect/manage-app-information/overview-of-export-compliance/).
- Confirm content rights and regional availability requirements, including trader status when distributing in the EU. Use the actual account and markets. [EU trader requirements](https://developer.apple.com/help/app-store-connect/manage-compliance-information/manage-european-union-digital-services-act-trader-requirements/).
- For purchases and subscriptions, confirm what is sold, current regional rules, product setup, pricing, restoration and review requirements. Do not claim every payment flow has the same rules. [App Review Guidelines](https://developer.apple.com/app-store/review/guidelines/).

Provide review contact details, clear navigation steps and access to all restricted functionality. Put demo credentials only in the designated console fields. Keep the backend available. Explain hardware dependencies and any required test setup. Test purchases, login, permission denial, offline behavior and deletion on real devices.

## Submission and release

Select the processed build and complete required metadata. Summarize version, build, distribution, markets, price, text, assets, privacy answers and unresolved checks. Submission for review and public release are separate actions. [Submit an app](https://developer.apple.com/help/app-store-connect/manage-submissions-to-app-review/submit-an-app/).

Explain manual release, automatic release or automatic release no earlier than a selected date. For eligible updates, discuss phased release and how to pause it. Do not promise a review duration. These MCP tools prepare the developer and AI host; they do not connect to App Store Connect, upload, submit or publish. [Release settings](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information/).
