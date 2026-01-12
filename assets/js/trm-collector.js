(function () {
    'use strict';

    if (!window.navigator || typeof window.navigator.sendBeacon !== 'function') {
        return;
    }

    const cfg = window.TRMCollectorSettings || {};
    if (!cfg.restUrl || !cfg.nonce) {
        return;
    }

    const sessionKey = cfg.sessionKey || 'trm_session_id';
    const sessionId = ensureSession();

    let lcpTime = null;
    try {
        const po = new PerformanceObserver((entryList) => {
            const entries = entryList.getEntries();
            const last = entries[entries.length - 1];
            if (last) {
                lcpTime = (last.renderTime || last.loadTime || last.startTime) / 1000;
            }
        });
        po.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {
        // LCP observer not supported; ignore.
    }

    function ensureSession() {
        try {
            if (!sessionStorage.getItem(sessionKey)) {
                const id = generateId();
                sessionStorage.setItem(sessionKey, id);
            }
            return sessionStorage.getItem(sessionKey);
        } catch (e) {
            return generateId();
        }
    }

    function generateId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'trm-' + Math.random().toString(16).slice(2) + '-' + Date.now();
    }

    function getNavigationTimings() {
        const nav = performance.getEntriesByType('navigation')[0];
        if (nav) {
            return {
                ttfb: (nav.responseStart - nav.requestStart) / 1000,
                load: (nav.loadEventEnd - nav.requestStart) / 1000,
            };
        }
        const t = performance.timing;
        return {
            ttfb: (t.responseStart - t.requestStart) / 1000,
            load: (t.loadEventEnd - t.requestStart) / 1000,
        };
    }

    function deviceType() {
        return /Mobi|Android/i.test(navigator.userAgent) ? 'mob' : 'desk';
    }

    function connectionType() {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        return conn && conn.effectiveType ? conn.effectiveType : '';
    }

    function sendPayload() {
        if (navigator.connection && navigator.connection.saveData) {
            return;
        }

        const timings = getNavigationTimings();
        
        // Detect stale server_time (Cached Page)
        // If PHP reported generating time is longer than the entire document fetch time, 
        // it means the HTML was served from cache with an old timestamp.
        let serverTime = Number(cfg.server && cfg.server.time ? cfg.server.time : 0);
        
        // Get document fetch duration (responseEnd - requestStart)
        const perf = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('navigation')[0] : null;
        if (perf) {
            const docDuration = (perf.responseEnd - perf.requestStart) / 1000; // in seconds
            // If Server Time is significantly larger than Doc Duration, it's a cached artifact.
            // We add a small 50ms buffer for timer mismatches.
            if (serverTime > (docDuration + 0.05)) {
                serverTime = 0; // Honest value: Server didn't run PHP for *this* request
            }
        }

        const payload = {
            event_time: cfg.timestamp,
            url: window.location.href, // More reliable than pathname
            server_time: serverTime,
            ttfb: Number(timings.ttfb || 0),
            lcp: Number(lcpTime || 0),
            total_load: Number(timings.load || 0),
            memory_peak: Number(cfg.server && cfg.server.memoryPeak ? cfg.server.memoryPeak : 0),
            device: deviceType(),
            net: connectionType(),
            country: cfg.server && cfg.server.country ? cfg.server.country : '',
            session_id: sessionId,
        };

        // Use fetch with keepalive as the modern standard for RUM
        if (window.fetch) {
            fetch(cfg.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TRM-Nonce': cfg.nonce
                },
                body: JSON.stringify(payload),
                keepalive: true,
                priority: 'low'
            }).catch(() => {});
        } else if (window.navigator.sendBeacon) {
            // Fallback for older browsers (no custom headers support in sendBeacon usually)
            // We append token to URL but use a custom param to avoid WP Core conflict
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            const url = new URL(cfg.restUrl);
            url.searchParams.set('trm_token', cfg.nonce);
            window.navigator.sendBeacon(url.toString(), blob);
        }
    }

    // Capture before unload to ensure data is sent even on navigation
    const eventName = 'visibilitychange';
    document.addEventListener(eventName, function() {
        if (document.visibilityState === 'hidden') {
            sendPayload();
        }
    });
})();
