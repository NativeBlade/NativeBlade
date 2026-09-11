---
title: "Google Play publication with MCP"
description: "Project-aware publication checklist and official Google Play sources."
---

# Google Play publication with MCP

Reference reviewed on 2026-09-12. This is a bundled reference, not a live policy service. Before each submission, open the linked official pages and check their effective dates and the app's Play Console notices. Requirements depend on account, device family, distribution, region and app behavior.

## How to conduct the conversation

Use `publish_android` to read the configuration loaded from `AppServiceProvider` through `ShellConfig`. No `plugins()` declaration means all built-in plugins are included. An explicit selection limits optional plugins. Core plugins remain included. Inspect custom plugins and generated manifests too. A plugin's presence is a reason to investigate, never an automatic privacy answer.

Summarize relevant findings in the developer's language. Use short sentences and no em dash or en dash. Ask up to three missing questions at a time, explain choices and reuse previous answers. Use the returned question ids in `answers` on subsequent calls. The tool is stateless and does not persist answers or connect to a developer account.

## Account and release

Confirm personal versus organization account, account verification, access permissions and whether this is the first release or an update. Check payment profile, agreements and current console tasks. Never request private signing keys or passwords in chat.

For personal accounts created after November 13, 2023, the reviewed requirement is a closed test with at least 12 testers opted in continuously for 14 days before applying for production access. Completing the test does not itself grant access. An internal test does not replace that closed test. Check the actual account's eligibility. [Testing requirements](https://support.google.com/googleplay/android-developer/answer/14151465).

Choose internal testing, closed testing, open testing when eligible, or production. Explain who receives the build. For updates, compare the intended release to the version already published, including previously used version codes.

## NativeBlade build and technical checks

Read `identifier`, `version`, `buildNumber`, `minSdk`, `targetSdk` and `permissions` from the Android configuration. A missing identifier or version needs correction. A missing explicit target does not prove the generated target is missing: inspect the generated Gradle file and final artifact.

Since August 31, 2026, new phone/tablet apps and updates must target API 36 or higher. Wear OS and Automotive use API 35; TV and XR use API 34. Confirm applicable exceptions and any granted extension in the console. Do not confuse `minSdk` with `targetSdk`. [Target API requirements](https://developer.android.com/google/play/requirements/target-sdk).

Use the NativeBlade cloud workflow described in [Publish](publish.md) or the supported local command `php artisan nativeblade:build android`, according to the developer's environment. After config edits, `php artisan nativeblade:config` applies the native configuration. Production builds must bundle the app and must not use the `--host` development preview option.

Inspect the resulting AAB, signing certificate, package id and version code. An AAB file existing does not prove it is signed or uploadable. Confirm Play App Signing and the upload key, preserve signing continuity on updates, and use a new version code. New apps normally use Android App Bundles. [App setup and signing](https://support.google.com/googleplay/android-developer/answer/9859152).

NativeBlade includes native libraries. Check 64-bit support, ELF alignment, packaging and execution on 16 KB devices for every shipped native library, including dependencies. The page reviewed here states a February 1, 2027 update-blocking deadline for missing 16 KB support. Recheck the current page and account notices before treating a date as a blocker. [16 KB compatibility](https://developer.android.com/guide/practices/page-sizes).

## Store fields and assets

Use `store_listing` for localized text. Supply the real app purpose and user benefits separately from changes in this release. Fields: name up to 30 characters, short description up to 80, full description up to 4000. Provide a support email, category, app/game designation and localized content. [Store listing fields](https://support.google.com/googleplay/android-developer/answer/9859152).

Prepare the store icon (512 by 512), feature graphic (1024 by 500) and real screenshots of the supported devices. Check current image formats, file sizes, minimum counts and device-specific rules before exporting. Do not fabricate app screens. [Preview asset specifications](https://support.google.com/googleplay/android-developer/answer/9866151).

Release notes describe actual changes, with up to 500 Unicode characters per language. The release name is internal. Add locale tags only when pasting into the console's tagged release-notes field. Keep tags outside the text count. [Release preparation](https://support.google.com/googleplay/android-developer/answer/9859348).

## App content and conditional declarations

Complete each applicable console task and explain why it applies:

- App access: provide review instructions and a working test account through the console when login restricts access. Ensure the backend and review environment remain available.
- Privacy policy: provide a public URL and in-app access. Review the app, backend and SDK collection, sharing, purposes, encryption in transit, retention and deletion. Prepare Data safety answers from confirmed behavior. Permissions alone cannot determine them. [Data safety](https://support.google.com/googleplay/android-developer/answer/10787469).
- Accounts: if users can create accounts, provide in-app deletion and a web resource for requesting deletion of the account and associated data, subject to the policy's scope and exceptions. [Account deletion](https://support.google.com/googleplay/android-developer/answer/13327111).
- Declare ads, target audience, content rating and applicable children/families requirements. Confirm actual ages and content instead of choosing the lowest rating.
- Review sensitive permissions, especially background location, photo/video access, SMS/call logs and foreground services if present. Explain the user-facing need and check required declarations.
- Review health, financial, government, news, user-generated content and other category-specific declarations when relevant. Inspect the current console task list rather than treating this list as exhaustive. [Developer policies](https://play.google.com/about/developer-content-policy/).
- For digital goods or subscriptions, verify the applicable billing policy, eligible regional programs, products, pricing, restoration and testing. Physical goods and digital goods need different analysis. Do not infer a billing exemption. [Payments policy](https://support.google.com/googleplay/android-developer/answer/9858738).

## Review and rollout

Choose countries, price and availability. Confirm the consequences before selecting free versus paid. Run an appropriate test track and review pre-launch reports, crashes, ANRs, login, purchases, permission denial, offline behavior and account deletion.

Prepare a final report with the artifact identity, version, target track, countries, metadata, confirmed declarations, remaining blockers and evidence. Explain review submission separately from going live. Discuss managed publishing and staged rollout for eligible updates. Recheck console warnings and the official release workflow before any external action. These MCP tools do not upload, submit for review or release an app. [Release workflow](https://support.google.com/googleplay/android-developer/answer/9859348).
