export const code = `
    function wasmFetch(path, opts) {
        return new Promise(function(resolve) {
            var id = _id++;
            _pending[id] = resolve;
            window.parent.postMessage({ type: 'nativeblade-request', id: id, path: path, options: opts }, '*');
        });
    }

    var _orig = window.fetch;
    window.fetch = function(url, opts) {
        opts = opts || {};
        var path = '', isReq = url instanceof Request;

        if (isReq) {
            try { var p = new URL(url.url); path = p.pathname + p.search; } catch(e) { path = url.url; }
            if (url.url.startsWith('blob:')) path = '/';
        } else if (typeof url === 'string') {
            if (url.startsWith('/')) path = url;
            else if (url.startsWith('http')) { try { var u = new URL(url); path = u.pathname + u.search; } catch(e) { path = url; } }
            else path = '/' + url;
        }
        if (!path) path = '/';
        var method = opts.method||(isReq?url.method:'GET');
        if (method !== 'GET') console.log('[NativeBlade Fetch Interceptor]', method, path);
        if (path.includes('plugin') || path.includes('__tauri') || path.startsWith('/ipc')) return _orig.call(window, url, opts);

        return (isReq ? url.text() : Promise.resolve(opts.body || '')).then(function(body) {
            var h = {};
            if (isReq) url.headers.forEach(function(v,k){h[k]=v;});
            if (opts.headers) {
                if (opts.headers instanceof Headers) opts.headers.forEach(function(v,k){h[k]=v;});
                else if (typeof opts.headers === 'object') Object.keys(opts.headers).forEach(function(k){h[k]=opts.headers[k];});
            }
            return wasmFetch(path, { method: opts.method||(isReq?url.method:'GET'), body: typeof body==='string'?body:'', headers: h })
            .then(function(r) {
                var ct = (h['Content-Type']||h['content-type']||'').indexOf('json')>-1 ? 'application/json' : 'text/html';
                try {
                    var j = JSON.parse(r.text);
                    if (j && j.nativeblade && j.actions) {
                        j.actions.forEach(function(a) {
                            window.parent.postMessage({ type: 'nativeblade-native', action: a.action.replace('so:', ''), payload: a.data }, '*');
                        });
                        return new Response('{}', { status: 200, headers: {'Content-Type':'application/json'} });
                    }
                    if (j && j.components) {
                        var modified = false;
                        j.components.forEach(function(c) {
                            var ef = c.effects || {};
                            var redir = ef.redirect || ef.redirectUsingNavigate;
                            if (redir) {
                                window.parent.postMessage({ type: 'nativeblade-navigate', path: redir }, '*');
                                delete ef.redirect;
                                delete ef.redirectUsingNavigate;
                                modified = true;
                            }
                        });
                        if (modified) return new Response(JSON.stringify(j), { status: r.httpStatusCode||200, headers: {'Content-Type':'application/json'} });
                    }
                } catch(e) {}
                return new Response(r.text, { status: r.httpStatusCode||200, headers: {'Content-Type':ct} });
            });
        });
    };
`;
