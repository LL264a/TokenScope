// TokenScope CAPI 拦截器 v2 - 捕捉所有腾讯云 CAPI 响应
// 注入到主世界（通过 chrome-extension:// URL 加载，绕过 CSP）
(function() {
    if (window.__tsCapiInjected) return;
    window.__tsCapiInjected = true;
    var seen = {};
    var allData = [];

    // 从 URL 提取 cmd 参数
    function getCmd(u) {
        var m = u.match(/[?&]cmd=([^&]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    }

    // 提取 CAPI 返回的内层 Response
    function getResponse(d) {
        try {
            var r = d.data;
            if (r && r.data && r.data && r.data.Response) return r.data.Response;
            if (r && r.Response) return r.Response;
        } catch(e) {}
        return null;
    }

    var pushTimer = null;
    // 推送采集到的数据（带 debounce，等所有 CAPI 响应到齐再发消息）
    function pushData(url, response) {
        var cmd = getCmd(url);
        if (!cmd) return;
        // 用 URL 去重（每个 CAPI 有唯一 t 参数），而非 cmd name
        var key = url.slice(-80);
        if (seen[key]) return;
        seen[key] = true;
        allData.push({cmd: cmd, data: response, url: url});
        window.__tsCapiData = allData;
        // debounce: 最后一条响应到达 800ms 后统一发消息
        if (pushTimer) clearTimeout(pushTimer);
        pushTimer = setTimeout(function() {
            window.postMessage({type:'__ts_capi', all: allData}, '*');
        }, 1500);
    }
    
    // XHR intercept
    if (XMLHttpRequest && XMLHttpRequest.prototype) {
        var _send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function(b) {
            this.addEventListener('load', function() {
                try {
                    var d = JSON.parse(this.responseText);
                    if (d && d.code === 0) {
                        var url = this.responseURL || '';
                        if (url.indexOf('cgi/capi') > -1) {
                            var resp = getResponse(d);
                            if (resp) pushData(url, resp);
                        }
                    }
                } catch(e) {}
            });
            return _send.apply(this, arguments);
        };
    }
    
    // fetch intercept
    var _fetch = window.fetch;
    if (_fetch) {
        window.fetch = function() {
            var args = arguments;
            var url = typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url ? args[0].url : '');
            return _fetch.apply(this, args).then(function(resp) {
                if (url.indexOf('cgi/capi') > -1) {
                    resp.clone().json().then(function(d) {
                        if (d && d.code === 0) {
                            var inner = getResponse(d);
                            if (inner) pushData(url, inner);
                        }
                    }).catch(function(){});
                }
                return resp;
            });
        };
    }
})();
