/**
 * Cache-immune attribution beacon.
 *
 * THE BUG THIS EXISTS FOR: Xpay_Attribution::classify() is hooked on
 * template_redirect. On a store with a full-page cache (WP Rocket, LiteSpeed,
 * Cloudflare APO...) the cached HTML is served *before any PHP runs*, so for a
 * fresh visitor — which is every visitor arriving from an AI assistant — the
 * classifier never executes. It is not that it classifies wrongly; it never
 * runs at all — on a cached store, virtually no order carries a ref record.
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

  // Locale-aware "is this a product page path?" — mirror of the server's
  // is_deep_product_landing(). Kept in sync deliberately.
  function isDeepProductPath(path) {
    if (!path) return false;
    var bases = ['product', 'produit', 'produits', 'producto', 'productos', 'produkt', 'prodotto', 'produto'];
    var segs = path.toLowerCase().split('/');
    for (var i = 0; i < segs.length; i++) {
      if (bases.indexOf(segs[i]) !== -1) return true;
    }
    return false;
  }

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

    // A real, attributable signal: a stamp, a utm, or a referrer we can classify.
    var hasSignal = payload.xpay_ref || payload.utm_source || payload.referrer;

    // The direct-deep-PDP heuristic. ⛔ Gate it to no-referrer landings on a
    // PRODUCT path only. Without this it fires for EVERY direct visitor (bookmark,
    // type-in, homepage, category) — a large population, each of which would then
    // trigger an uncached WP-boot REST hit. Mirrors is_deep_product_landing() on
    // the server; locale-aware so /produit/ /producto/ etc. also count.
    var couldBeHeuristic = !payload.referrer && isDeepProductPath(payload.landing);

    // Nothing to say → don't wake the origin at all.
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
