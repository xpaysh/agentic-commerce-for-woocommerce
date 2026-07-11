/**
 * Cache-immune attribution beacon.
 *
 * THE BUG THIS EXISTS FOR: Xpay_Attribution::classify() is hooked on
 * template_redirect. On a store with a full-page cache (WP Rocket, LiteSpeed,
 * Cloudflare APO...) the cached HTML is served *before any PHP runs*, so for a
 * fresh visitor — which is every visitor arriving from an AI assistant — the
 * classifier never executes. It is not that it classifies wrongly; it never
 * runs at all. a live merchant store has 78 orders and exactly ONE carries a ref record.
 *
 * The fix is the same architecture WooCommerce core chose for its own Order
 * Attribution tracker: collect the signals in the BROWSER (where the cache can't
 * reach) and POST them to an endpoint that is never cached. Cache plugins
 * exclude REST responses by default, so this is cache-immune by construction.
 *
 * Sends at most one beacon per tab-session, and only when there is actually
 * something to classify.
 */
(function () {
  'use strict';

  if (typeof window.XpayAttr === 'undefined' || !window.XpayAttr.endpoint) return;

  try {
    // Once per tab-session. A shopper browsing 12 PDPs sends one beacon, not 12.
    if (window.sessionStorage && sessionStorage.getItem('_xpay_ref_sent')) return;

    var params = new URLSearchParams(window.location.search);
    var payload = {
      xpay_ref: params.get('xpay_ref') || '',
      utm_source: params.get('utm_source') || '',
      referrer: document.referrer || '',
      landing: window.location.pathname || ''
    };

    // Nothing to classify: no stamp, no utm, no referrer. The only remaining
    // signal would be the direct-deep-PDP heuristic, which the server can still
    // evaluate from `landing` — so only skip when there is truly nothing to say.
    var hasSignal = payload.xpay_ref || payload.utm_source || payload.referrer;
    var couldBeHeuristic = !payload.referrer && payload.landing;
    if (!hasSignal && !couldBeHeuristic) return;

    var body = JSON.stringify(payload);
    var sent = false;

    if (navigator.sendBeacon) {
      // sendBeacon survives the page being unloaded mid-flight, and carries
      // same-origin cookies (so the WC session rides along).
      sent = navigator.sendBeacon(
        window.XpayAttr.endpoint,
        new Blob([body], { type: 'application/json' })
      );
    }

    if (!sent && window.fetch) {
      fetch(window.XpayAttr.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        credentials: 'same-origin',
        keepalive: true
      })['catch'](function () {
        /* attribution must never surface an error to the shopper */
      });
    }

    if (window.sessionStorage) sessionStorage.setItem('_xpay_ref_sent', '1');
  } catch (e) {
    /* never break the storefront over analytics */
  }
})();
