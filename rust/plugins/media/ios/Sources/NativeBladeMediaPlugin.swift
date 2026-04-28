import AVFoundation
import PhotosUI
import SwiftRs
import Tauri
import UIKit
import UniformTypeIdentifiers
import WebKit

class PickArgs: Decodable {
    var maxWidth: Int?
    var maxHeight: Int?
    var quality: Double?
    var facing: String?
    var output: String?
    var multiple: Bool?
    var max: Int?
    var id: String?
}

class ReadAssetArgs: Decodable {
    var url: String
}

private struct PendingState {
    let invoke: Invoke
    let opts: PickArgs
    let isVideo: Bool
}

class NativeBladeMediaPlugin: Plugin, UIImagePickerControllerDelegate, UINavigationControllerDelegate, PHPickerViewControllerDelegate {

    private static let DEFAULT_MAX = 1200
    private static let DEFAULT_QUALITY: CGFloat = 0.7

    private var pending: PendingState?

    @objc public func pickFromCamera(_ invoke: Invoke) throws {
        let opts = try invoke.parseArgs(PickArgs.self)
        guard UIImagePickerController.isSourceTypeAvailable(.camera) else {
            invoke.reject("camera not available")
            return
        }
        // Permission: prompt if needed; reject if denied.
        let status = AVCaptureDevice.authorizationStatus(for: .video)
        if status == .denied || status == .restricted {
            invoke.reject("camera permission denied")
            return
        }
        if status == .notDetermined {
            AVCaptureDevice.requestAccess(for: .video) { [weak self] granted in
                DispatchQueue.main.async {
                    if granted { self?.presentCamera(invoke: invoke, opts: opts) }
                    else { invoke.reject("camera permission denied") }
                }
            }
            return
        }
        presentCamera(invoke: invoke, opts: opts)
    }

    @objc public func pickFromGallery(_ invoke: Invoke) throws {
        let opts = try invoke.parseArgs(PickArgs.self)
        presentPHPicker(invoke: invoke, opts: opts, video: false)
    }

    @objc public func pickVideo(_ invoke: Invoke) throws {
        let opts = try invoke.parseArgs(PickArgs.self)
        presentPHPicker(invoke: invoke, opts: opts, video: true)
    }

    @objc public func checkPermissions(_ invoke: Invoke) {
        invoke.resolve(currentPermissionStatus())
    }

    @objc public func requestPermissions(_ invoke: Invoke) {
        let status = AVCaptureDevice.authorizationStatus(for: .video)
        if status == .notDetermined {
            AVCaptureDevice.requestAccess(for: .video) { [weak self] _ in
                DispatchQueue.main.async {
                    invoke.resolve(self?.currentPermissionStatus() ?? [:])
                }
            }
        } else {
            invoke.resolve(currentPermissionStatus())
        }
    }

    @objc public func readAsset(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(ReadAssetArgs.self)
        var path = args.url
        if path.hasPrefix("file://") { path = String(path.dropFirst("file://".count)) }
        let url = URL(fileURLWithPath: path)
        guard let data = try? Data(contentsOf: url) else {
            invoke.reject("asset not found: \(path)")
            return
        }
        let mime = mimeForPath(path)
        let b64 = data.base64EncodedString()
        invoke.resolve([
            "dataUrl": "data:\(mime);base64,\(b64)",
            "mime": mime,
            "size": data.count,
        ])
    }

    private func currentPermissionStatus() -> [String: String] {
        let camera: String
        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .authorized: camera = "granted"
        case .denied, .restricted: camera = "denied"
        case .notDetermined: camera = "prompt"
        @unknown default: camera = "unknown"
        }
        // PHPicker is permission-free; legacy library access ignored here.
        return ["camera": camera, "photos": "granted"]
    }

    private func presentCamera(invoke: Invoke, opts: PickArgs) {
        DispatchQueue.main.async { [weak self] in
            guard let self = self, let vc = self.manager.viewController else {
                invoke.reject("no view controller")
                return
            }
            self.pending = PendingState(invoke: invoke, opts: opts, isVideo: false)
            let picker = UIImagePickerController()
            picker.sourceType = .camera
            picker.cameraDevice = (opts.facing == "front") ? .front : .rear
            picker.mediaTypes = [UTType.image.identifier]
            picker.delegate = self
            vc.present(picker, animated: true)
        }
    }

    private func presentPHPicker(invoke: Invoke, opts: PickArgs, video: Bool) {
        DispatchQueue.main.async { [weak self] in
            guard let self = self, let vc = self.manager.viewController else {
                invoke.reject("no view controller")
                return
            }
            self.pending = PendingState(invoke: invoke, opts: opts, isVideo: video)
            var config = PHPickerConfiguration(photoLibrary: .shared())
            config.filter = video ? .videos : .images
            config.selectionLimit = (opts.multiple == true) ? (opts.max ?? 10) : 1
            let picker = PHPickerViewController(configuration: config)
            picker.delegate = self
            vc.present(picker, animated: true)
        }
    }

    // MARK: - UIImagePickerController (camera)

    func imagePickerController(_ picker: UIImagePickerController,
                               didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
        let state = pending
        pending = nil
        picker.dismiss(animated: true)
        guard let state = state, let image = info[.originalImage] as? UIImage else {
            state?.invoke.reject("no image returned")
            return
        }
        guard let item = encodeImage(image, opts: state.opts, name: nil) else {
            state.invoke.reject("failed to encode capture")
            return
        }
        state.invoke.resolve(envelope(items: [item], opts: state.opts))
    }

    func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
        let state = pending
        pending = nil
        picker.dismiss(animated: true)
        state?.invoke.reject("cancelled")
    }

    // MARK: - PHPickerViewController (gallery)

    func picker(_ picker: PHPickerViewController, didFinishPicking results: [PHPickerResult]) {
        let state = pending
        pending = nil
        picker.dismiss(animated: true)
        guard let state = state else { return }
        if results.isEmpty {
            state.invoke.reject("cancelled")
            return
        }

        let group = DispatchGroup()
        var items: [[String: Any]] = []
        let lock = NSLock()

        for res in results {
            let provider = res.itemProvider
            if state.isVideo {
                group.enter()
                let typeId = UTType.movie.identifier
                provider.loadFileRepresentation(forTypeIdentifier: typeId) { url, _ in
                    defer { group.leave() }
                    guard let src = url else { return }
                    if let item = self.copyVideo(src: src, opts: state.opts,
                                                 name: provider.suggestedName) {
                        lock.lock(); items.append(item); lock.unlock()
                    }
                }
            } else {
                group.enter()
                provider.loadObject(ofClass: UIImage.self) { obj, _ in
                    defer { group.leave() }
                    guard let img = obj as? UIImage else { return }
                    if let item = self.encodeImage(img, opts: state.opts,
                                                   name: provider.suggestedName) {
                        lock.lock(); items.append(item); lock.unlock()
                    }
                }
            }
        }

        group.notify(queue: .main) {
            if items.isEmpty {
                state.invoke.reject("failed to process picked items")
            } else {
                state.invoke.resolve(self.envelope(items: items, opts: state.opts))
            }
        }
    }

    // MARK: - Encoding

    private func encodeImage(_ image: UIImage, opts: PickArgs, name: String?) -> [String: Any]? {
        let maxW = CGFloat(opts.maxWidth ?? Self.DEFAULT_MAX)
        let maxH = CGFloat(opts.maxHeight ?? Self.DEFAULT_MAX)
        let quality = CGFloat(opts.quality ?? Double(Self.DEFAULT_QUALITY))

        let resized = resize(image: image, maxW: maxW, maxH: maxH)
        guard let data = resized.jpegData(compressionQuality: quality) else { return nil }

        let dir = (NSTemporaryDirectory() as NSString).appendingPathComponent("nb_media")
        try? FileManager.default.createDirectory(atPath: dir, withIntermediateDirectories: true)
        let filename = "img_\(Int(Date().timeIntervalSince1970 * 1000))_\(Int.random(in: 0...9999)).jpg"
        let path = (dir as NSString).appendingPathComponent(filename)
        guard (try? data.write(to: URL(fileURLWithPath: path))) != nil else { return nil }

        return buildItem(path: path, mime: "image/jpeg",
                         width: Int(resized.size.width), height: Int(resized.size.height),
                         size: data.count, name: name, opts: opts)
    }

    private func copyVideo(src: URL, opts: PickArgs, name: String?) -> [String: Any]? {
        let dir = (NSTemporaryDirectory() as NSString).appendingPathComponent("nb_media")
        try? FileManager.default.createDirectory(atPath: dir, withIntermediateDirectories: true)
        let ext = src.pathExtension.isEmpty ? "mp4" : src.pathExtension
        let filename = "vid_\(Int(Date().timeIntervalSince1970 * 1000)).\(ext)"
        let dst = URL(fileURLWithPath: (dir as NSString).appendingPathComponent(filename))
        do {
            if FileManager.default.fileExists(atPath: dst.path) {
                try FileManager.default.removeItem(at: dst)
            }
            try FileManager.default.copyItem(at: src, to: dst)
        } catch {
            return nil
        }
        let size = (try? FileManager.default.attributesOfItem(atPath: dst.path)[.size] as? Int) ?? 0
        return buildItem(path: dst.path, mime: mimeForPath(dst.path),
                         width: 0, height: 0, size: size, name: name, opts: opts)
    }

    private func buildItem(path: String, mime: String, width: Int, height: Int,
                           size: Int, name: String?, opts: PickArgs) -> [String: Any] {
        let mode = opts.output ?? "url"
        var item: [String: Any] = [
            "mime": mime,
            "size": size,
            "width": width,
            "height": height,
            "name": name ?? (path as NSString).lastPathComponent,
        ]
        if mode == "url" || mode == "both" {
            item["path"] = path
            item["url"] = "file://\(path)"
        } else {
            item["path"] = ""
            item["url"] = ""
        }
        if mode == "dataurl" || mode == "both" {
            if let data = try? Data(contentsOf: URL(fileURLWithPath: path)) {
                item["dataUrl"] = "data:\(mime);base64,\(data.base64EncodedString())"
            } else {
                item["dataUrl"] = ""
            }
        } else {
            item["dataUrl"] = ""
        }
        return item
    }

    private func envelope(items: [[String: Any]], opts: PickArgs) -> [String: Any] {
        return [
            "items": items,
            "id": opts.id as Any,
        ]
    }

    private func resize(image: UIImage, maxW: CGFloat, maxH: CGFloat) -> UIImage {
        let w = image.size.width
        let h = image.size.height
        let ratio = min(maxW / w, maxH / h, 1.0)
        if ratio >= 1.0 { return image }
        let newSize = CGSize(width: w * ratio, height: h * ratio)
        let renderer = UIGraphicsImageRenderer(size: newSize)
        return renderer.image { _ in
            image.draw(in: CGRect(origin: .zero, size: newSize))
        }
    }

    private func mimeForPath(_ path: String) -> String {
        let ext = (path as NSString).pathExtension.lowercased()
        switch ext {
        case "jpg", "jpeg": return "image/jpeg"
        case "png": return "image/png"
        case "heic": return "image/heic"
        case "gif": return "image/gif"
        case "mp4", "m4v": return "video/mp4"
        case "mov": return "video/quicktime"
        default: return "application/octet-stream"
        }
    }
}

@_cdecl("init_plugin_nativeblade_media")
func initPlugin() -> Plugin {
    return NativeBladeMediaPlugin()
}
