(function () {
  var root = document.getElementById('emerus-wsforms-overlay-root');
  if (!root) {
    return;
  }

  var config = window.EmerusWsFormsOverlay || {};
  var maxWidth = parseInt(root.getAttribute('data-max-width') || config.maxWidth || 420, 10);
  if (!Number.isFinite(maxWidth)) {
    maxWidth = 420;
  }
  root.style.setProperty('--ewo-max-width', maxWidth + 'px');

  function isVisible(el) {
    if (!el) {
      return false;
    }
    var rect = el.getBoundingClientRect();
    return rect.width > 100 && rect.height > 180;
  }

  function findHeroHost() {
    var selectors = [
      'main .brxe-section',
      '.brxe-main .brxe-section',
      'main section',
      '.hero',
      '[data-hero]'
    ];

    for (var i = 0; i < selectors.length; i += 1) {
      var nodes = document.querySelectorAll(selectors[i]);
      for (var j = 0; j < nodes.length; j += 1) {
        var node = nodes[j];
        if (!isVisible(node)) {
          continue;
        }
        var rect = node.getBoundingClientRect();
        if (rect.top > window.innerHeight * 0.6) {
          continue;
        }
        return node;
      }
    }

    return null;
  }

  function placeOverlay() {
    var host = findHeroHost();

    if (host) {
      var computed = window.getComputedStyle(host);
      if (computed.position === 'static') {
        host.style.position = 'relative';
      }
      host.classList.add('emerus-overlay-host');
      host.appendChild(root);
      root.classList.remove('is-fixed-fallback');
      root.classList.add('is-attached-to-hero');
      return;
    }

    if (document.body && root.parentNode !== document.body) {
      document.body.appendChild(root);
    }

    root.classList.remove('is-attached-to-hero');
    root.classList.add('is-fixed-fallback');
  }

  function debounce(fn, wait) {
    var timeout;
    return function () {
      clearTimeout(timeout);
      timeout = setTimeout(fn, wait);
    };
  }

  placeOverlay();

  window.addEventListener('load', placeOverlay);
  window.addEventListener('resize', debounce(placeOverlay, 120));

  window.EmerusZoho = {
    endpoint: config.restUrl || '',
    nonce: config.nonce || '',
    sendLead: async function (payload) {
      if (!this.endpoint) {
        throw new Error('Zoho endpoint URL is missing.');
      }

      var response = await fetch(this.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': this.nonce
        },
        body: JSON.stringify(payload || {})
      });

      var data = await response.json().catch(function () {
        return {};
      });

      if (!response.ok) {
        var message = (data && data.message) ? data.message : 'Zoho request failed.';
        throw new Error(message);
      }

      return data;
    }
  };
})();
