(function () {
  var root = document.getElementById('emerus-wsforms-overlay-root');
  if (!root) {
    return;
  }

  var config = window.EmerusWsFormsOverlay || {};
  var mobileQuery = window.matchMedia('(max-width: 1040px)');
  var contentWidth = 1400;
  var contentSideGap = 22;
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

  function setRightOffset(width) {
    var viewportWidth = Number.isFinite(width) ? width : window.innerWidth;
    var rightOffset = Math.max(12, ((viewportWidth - contentWidth) / 2) + contentSideGap);
    root.style.setProperty('--ewo-right-offset', rightOffset + 'px');
  }

  function insertAfter(referenceNode, node) {
    if (!referenceNode || !referenceNode.parentNode) {
      return;
    }
    referenceNode.parentNode.insertBefore(node, referenceNode.nextSibling);
  }

  function resetClasses() {
    root.classList.remove('is-fixed-fallback');
    root.classList.remove('is-attached-to-hero');
    root.classList.remove('is-below-hero');
  }

  function placeOverlay() {
    var host = findHeroHost();
    var isMobile = mobileQuery.matches;

    if (host) {
      if (!isMobile) {
        var computed = window.getComputedStyle(host);
        if (computed.position === 'static') {
          host.style.position = 'relative';
        }
        setRightOffset(host.getBoundingClientRect().width);
        host.classList.add('emerus-overlay-host');
        if (root.parentNode !== host) {
          host.appendChild(root);
        }
        resetClasses();
        root.classList.add('is-attached-to-hero');
        return;
      }

      host.classList.add('emerus-overlay-host');
      setRightOffset(window.innerWidth);
      insertAfter(host, root);
      resetClasses();
      root.classList.add('is-below-hero');
      return;
    }

    if (isMobile) {
      var main = document.querySelector('main');
      if (main) {
        main.appendChild(root);
      } else if (document.body && root.parentNode !== document.body) {
        document.body.appendChild(root);
      }
      resetClasses();
      root.classList.add('is-below-hero');
      return;
    }

    if (document.body && root.parentNode !== document.body) {
      document.body.appendChild(root);
    }

    setRightOffset(window.innerWidth);
    resetClasses();
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
  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', placeOverlay);
  } else if (typeof mobileQuery.addListener === 'function') {
    mobileQuery.addListener(placeOverlay);
  }

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
