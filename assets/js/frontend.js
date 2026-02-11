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

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/([ #;?%&,.+*~':"!^$\[\]()=>|/@])/g, '\\$1');
  }

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

  function ruleMatchesPage(rule) {
    if (!rule || !Array.isArray(rule.refs) || rule.refs.length === 0) {
      return false;
    }

    var pageId = String(config.pageId || '');
    var pageSlug = String(config.pageSlug || '').toLowerCase();

    for (var i = 0; i < rule.refs.length; i += 1) {
      var ref = String(rule.refs[i] || '').toLowerCase();
      if (ref === '*') {
        return true;
      }
      if (pageId && ref === pageId) {
        return true;
      }
      if (pageSlug && ref === pageSlug) {
        return true;
      }
    }

    return false;
  }

  function resolveDefaults() {
    var variant = root.getAttribute('data-variant') || 'hero';
    var defaults = {};
    var rules = Array.isArray(config.wsDefaultRules) ? config.wsDefaultRules : [];

    for (var i = 0; i < rules.length; i += 1) {
      var rule = rules[i];
      if (!rule || !rule.field) {
        continue;
      }

      var ruleVariant = String(rule.variant || 'both');
      if (ruleVariant !== 'both' && ruleVariant !== variant) {
        continue;
      }

      if (!ruleMatchesPage(rule)) {
        continue;
      }

      defaults[String(rule.field)] = String(rule.value || '');
    }

    return defaults;
  }

  function getMatchingFields(container, fieldName) {
    if (!container) {
      return [];
    }

    var escaped = cssEscape(fieldName);
    var selectors = [
      '[name="' + escaped + '"]',
      '[name$="[' + escaped + ']"]',
      '[data-emerus-default="' + escaped + '"]',
      '[data-field-name="' + escaped + '"] input',
      '[data-name="' + escaped + '"] input'
    ];

    var found = [];
    for (var i = 0; i < selectors.length; i += 1) {
      var nodes = container.querySelectorAll(selectors[i]);
      for (var j = 0; j < nodes.length; j += 1) {
        if (found.indexOf(nodes[j]) === -1) {
          found.push(nodes[j]);
        }
      }
    }

    return found;
  }

  function setFieldValue(el, value) {
    if (!el) {
      return false;
    }

    var tagName = (el.tagName || '').toLowerCase();
    var type = (el.type || '').toLowerCase();

    if (tagName === 'select') {
      var hasOption = false;
      for (var i = 0; i < el.options.length; i += 1) {
        if (String(el.options[i].value) === String(value)) {
          hasOption = true;
          break;
        }
      }
      if (!hasOption) {
        return false;
      }
      el.value = value;
    } else if (type === 'checkbox') {
      var truthy = ['1', 'true', 'yes', 'on'];
      el.checked = truthy.indexOf(String(value).toLowerCase()) !== -1;
    } else if (type === 'radio') {
      if (String(el.value) !== String(value)) {
        return false;
      }
      el.checked = true;
    } else {
      if (type !== 'hidden' && String(el.value || '').trim() !== '') {
        return false;
      }
      el.value = value;
    }

    dispatchFieldEvent(el, 'input');
    dispatchFieldEvent(el, 'change');
    return true;
  }

  function dispatchFieldEvent(el, eventName) {
    if (!el || !eventName) {
      return;
    }

    if (typeof Event === 'function') {
      el.dispatchEvent(new Event(eventName, { bubbles: true }));
      return;
    }

    var legacyEvent = document.createEvent('Event');
    legacyEvent.initEvent(eventName, true, true);
    el.dispatchEvent(legacyEvent);
  }

  function applyWsDefaults() {
    var formContainer = root.querySelector('.emerus-wsforms-overlay__form');
    if (!formContainer) {
      return false;
    }

    var defaults = resolveDefaults();
    var keys = Object.keys(defaults);
    var appliedCount = 0;

    for (var i = 0; i < keys.length; i += 1) {
      var fieldName = keys[i];
      var fields = getMatchingFields(formContainer, fieldName);
      for (var j = 0; j < fields.length; j += 1) {
        if (setFieldValue(fields[j], defaults[fieldName])) {
          appliedCount += 1;
        }
      }
    }

    config.resolvedDefaults = defaults;
    window.EmerusWsFormsOverlay = config;

    if (typeof window.CustomEvent === 'function') {
      window.dispatchEvent(new CustomEvent('emerus-ws-defaults-applied', {
        detail: {
          defaults: defaults,
          variant: root.getAttribute('data-variant') || 'hero',
          pageId: config.pageId || 0,
          pageSlug: config.pageSlug || '',
          appliedCount: appliedCount
        }
      }));
    }

    return true;
  }

  function applyWsDefaultsWithRetry(attempt) {
    var maxAttempts = 30;
    if (applyWsDefaults()) {
      return;
    }

    if (attempt >= maxAttempts) {
      return;
    }

    setTimeout(function () {
      applyWsDefaultsWithRetry(attempt + 1);
    }, 160);
  }

  config.getResolvedDefaults = function () {
    var values = resolveDefaults();
    var copy = {};
    var keys = Object.keys(values);
    for (var i = 0; i < keys.length; i += 1) {
      copy[keys[i]] = values[keys[i]];
    }
    return copy;
  };
  config.applyResolvedDefaults = applyWsDefaults;
  window.EmerusWsFormsOverlay = config;

  placeOverlay();
  applyWsDefaultsWithRetry(0);

  var refreshPlacement = debounce(function () {
    placeOverlay();
    applyWsDefaultsWithRetry(0);
  }, 120);

  window.addEventListener('load', refreshPlacement);
  window.addEventListener('resize', refreshPlacement);
  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', refreshPlacement);
  } else if (typeof mobileQuery.addListener === 'function') {
    mobileQuery.addListener(refreshPlacement);
  }

  if (typeof window.MutationObserver === 'function') {
    var observer = new MutationObserver(debounce(function () {
      applyWsDefaultsWithRetry(0);
    }, 80));

    observer.observe(root, {
      childList: true,
      subtree: true
    });
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
