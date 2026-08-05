// Inline a rendered page's local assets into the HTML itself. The WebView has no
// file server: the app (and each satellite window) runs in an origin-null srcdoc
// iframe, so a <link href="/css/x.css"> or <script src="/js/x.js"> would become a
// real network request and fail (ERR_CONNECTION_REFUSED). Everything local must
// be inlined here instead — stylesheets, scripts, Livewire, Vite build assets,
// images, and url() references inside CSS.
//
// Standalone module (no php-runtime import) so it can be unit-tested with a mock
// `php`, following the same convention as bundle-base.js.
//
// `php` must expose readFileAsText(path) and readFileAsBuffer(path).
export function inlineAssets(html, php) {
    let inlineJs = '';

    // Rewrite url(/x.woff2|png|...) inside inlined CSS to base64 (WebView has no file server).
    const cssMime = { woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf', eot: 'application/vnd.ms-fontobject', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml' };
    const cssUrls = (css) => css.replace(/url\(\s*(['"]?)\/([^'")?#]+\.(woff2|woff|ttf|otf|eot|png|jpe?g|gif|svg))[^'")]*\1\s*\)/gi, (m, q, file, ext) => {
        try {
            const content = php.readFileAsText('/app/public/' + file);
            if (content.startsWith('data:')) return "url('" + content + "')";
            const bytes = php.readFileAsBuffer('/app/public/' + file);
            let bin = ''; for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return "url('data:" + (cssMime[ext.toLowerCase()] || 'application/octet-stream') + ";base64," + btoa(bin) + "')";
        } catch { return m; }
    });

    html = html.replace(
        /<link[^>]*href="[^"]*\/build\/assets\/([^"]+\.css)"[^>]*\/?>/g,
        (m, file) => { try { return '<style>' + cssUrls(php.readFileAsText('/app/public/build/assets/' + file)) + '</style>'; } catch { return ''; } }
    );

    html = html.replace(/<link[^>]*href="[^"]*\/build\/assets\/([^"]+\.js)"[^>]*\/?>/g, () => '');

    html = html.replace(
        /<script[^>]*src="[^"]*\/build\/assets\/([^"]+\.js)"[^>]*><\/script>/g,
        (m, file) => { try { inlineJs += php.readFileAsText('/app/public/build/assets/' + file) + '\n'; } catch {} return ''; }
    );

    html = html.replace(
        /<script\s+src="[^"]*livewire[^"]*\.js[^"]*"([^>]*)><\/script>/g,
        (m, attrs) => {
            try {
                let js;
                try { js = php.readFileAsText('/app/vendor/livewire/livewire/dist/livewire.min.js'); }
                catch { js = php.readFileAsText('/app/vendor/livewire/livewire/dist/livewire.js'); }
                attrs = attrs.replace(/data-module-url="[^"]*"/, 'data-module-url=""')
                    .replace(/data-update-uri="http[s]?:\/\/[^"]*\/livewire/, 'data-update-uri="/livewire');
                return '<script' + attrs + '>' + js + '</script>';
            } catch { return ''; }
        }
    );

    if (inlineJs) html = html.replace('</body>', '<script>' + inlineJs + '</script></body>');

    // Inline local <script src="/x.js"> / <link href="/x.css"> from the bundle (WebView has no file server).
    // The optional (?:https?://host)? prefix matches host-absolute URLs too, since
    // asset('js/x.js') / asset('css/x.css') render as http://localhost/js/x.js.
    // [^"/]* stops at the first path slash so subdirectories survive in `file`.
    html = html.replace(
        /<script([^>]*)\ssrc="(?:https?:\/\/[^"\/]*)?\/([^"]+\.js)"([^>]*)><\/script>/g,
        (m, pre, file, post) => {
            if (file.indexOf('build/assets/') === 0) return m;
            try {
                const js = php.readFileAsText('/app/public/' + file).replace(/<\/script>/gi, '<\\/script>');
                return '<script' + (pre + post).replace(/\stype=("|')module\1/i, '') + '>' + js + '</script>';
            } catch { return m; }
        }
    );
    html = html.replace(
        /<link([^>]*)\shref="(?:https?:\/\/[^"\/]*)?\/([^"]+\.css)"([^>]*)\/?>/g,
        (m, pre, file, post) => {
            if (file.indexOf('build/assets/') === 0 || !/stylesheet/i.test(pre + post)) return m;
            try { return '<style>' + cssUrls(php.readFileAsText('/app/public/' + file)) + '</style>'; }
            catch { return m; }
        }
    );

    const mimeMap = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml' };

    html = html.replace(
        /(<img[^>]*src=")(?:https?:\/\/[^"\/]*)?\/([^"]+\.(png|jpg|jpeg|gif|svg))("[^>]*>)/gi,
        (m, before, file, ext, after) => {
            try {
                const content = php.readFileAsText('/app/public/' + file);
                if (content.startsWith('data:')) return before + content + after;
                const mime = mimeMap[ext.toLowerCase()] || 'application/octet-stream';
                const bytes = php.readFileAsBuffer('/app/public/' + file);
                let binary = '';
                for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
                const b64 = btoa(binary);
                return before + 'data:' + mime + ';base64,' + b64 + after;
            } catch {}
            return m;
        }
    );

    return html;
}
