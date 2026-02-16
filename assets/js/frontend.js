(function () {
  var config = window.EmerusWsFormsOverlay || {};
  var root = document.getElementById('emerus-wsforms-overlay-root');
  var mobileQuery = window.matchMedia('(max-width: 1040px)');
  var contentWidth = 1400;
  var contentSideGap = 22;

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/([ #;?%&,.+*~':"!^$\[\]()=>|/@])/g, '\\$1');
  }

  function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function asString(value) {
    if (value === null || typeof value === 'undefined') {
      return '';
    }
    return String(value);
  }

  function isEmptyOrPlaceholderValue(value) {
    var normalized = asString(value).trim();
    if (!normalized) {
      return true;
    }

    if (normalized.indexOf('PLACEHOLDER_') !== -1) {
      return true;
    }

    return /^#(?:tracking_|field\(|form_|post_|query_var\()/i.test(normalized);
  }

  function getCurrentVariant() {
    if (root) {
      return asString(root.getAttribute('data-variant') || 'hero');
    }
    return 'hero';
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

  function resolveFormElement(formOrSelector) {
    if (!formOrSelector) {
      return null;
    }

    if (typeof formOrSelector === 'string') {
      return document.querySelector(formOrSelector);
    }

    if (formOrSelector.nodeType === 1 && (formOrSelector.tagName || '').toLowerCase() === 'form') {
      return formOrSelector;
    }

    if (formOrSelector.nodeType === 1) {
      return formOrSelector.closest('form');
    }

    return null;
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
    if (!root) {
      return;
    }

    var viewportWidth = Number.isFinite(width) ? width : window.innerWidth;
    var rightOffset = Math.max(12, ((viewportWidth - contentWidth) / 2) + contentSideGap);
    root.style.setProperty('--ewo-right-offset', rightOffset + 'px');
  }

  function insertAfter(referenceNode, node) {
    if (!referenceNode || !referenceNode.parentNode || !node) {
      return;
    }
    referenceNode.parentNode.insertBefore(node, referenceNode.nextSibling);
  }

  function resetClasses() {
    if (!root) {
      return;
    }

    root.classList.remove('is-fixed-fallback');
    root.classList.remove('is-attached-to-hero');
    root.classList.remove('is-below-hero');
  }

  function placeOverlay() {
    if (!root) {
      return;
    }

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

    var pageId = asString(config.pageId || '');
    var pageSlug = asString(config.pageSlug || '').toLowerCase();
    var parentPageId = asString(config.parentPageId || '');
    var parentPageSlug = asString(config.parentPageSlug || '').toLowerCase();

    for (var i = 0; i < rule.refs.length; i += 1) {
      var ref = asString(rule.refs[i] || '').toLowerCase();
      if (ref === '*') {
        return true;
      }

      if (ref.indexOf('parent:') === 0) {
        var parentRef = ref.slice(7);
        if (parentRef && ((parentPageId && parentRef === parentPageId) || (parentPageSlug && parentRef === parentPageSlug))) {
          return true;
        }
        continue;
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

  function resolveDefaults(variant) {
    var effectiveVariant = asString(variant || getCurrentVariant() || 'hero');
    var defaults = {};
    var rules = Array.isArray(config.wsDefaultRules) ? config.wsDefaultRules : [];

    for (var i = 0; i < rules.length; i += 1) {
      var rule = rules[i];
      if (!rule || !rule.field) {
        continue;
      }

      var ruleVariant = asString(rule.variant || 'both');
      if (ruleVariant !== 'both' && ruleVariant !== effectiveVariant) {
        continue;
      }

      if (!ruleMatchesPage(rule)) {
        continue;
      }

      defaults[asString(rule.field)] = asString(rule.value || '');
    }

    return defaults;
  }

  function getCurrentLangCode() {
    var lang = asString(config.lang || '').toLowerCase();
    if (lang.indexOf('hr') === 0) {
      return 'hr';
    }
    if (lang.indexOf('en') === 0) {
      return 'en';
    }

    var htmlLang = asString(document.documentElement && document.documentElement.lang ? document.documentElement.lang : '').toLowerCase();
    if (htmlLang.indexOf('hr') === 0) {
      return 'hr';
    }
    if (htmlLang.indexOf('en') === 0) {
      return 'en';
    }

    var path = asString(window.location && window.location.pathname ? window.location.pathname : '').toLowerCase();
    if (/^\/hr(\/|$)/.test(path)) {
      return 'hr';
    }
    if (/^\/en(\/|$)/.test(path)) {
      return 'en';
    }

    return 'en';
  }

  function resolveI18nValue(rule) {
    if (!rule || typeof rule !== 'object') {
      return '';
    }

    var key = asString(rule.field || '').trim();
    var hr = asString(rule.hr || '').trim();
    var en = asString(rule.en || '').trim();
    var lang = getCurrentLangCode();

    if (lang === 'hr') {
      return hr || en || key;
    }

    return en || key || hr;
  }

  function replaceI18nTokens(rawText, valuesMap) {
    var text = asString(rawText);
    if (text === '') {
      return text;
    }

    var map = valuesMap && typeof valuesMap === 'object' ? valuesMap : {};
    var keys = Object.keys(map);
    if (keys.length === 0) {
      return text;
    }

    var out = text;
    for (var i = 0; i < keys.length; i += 1) {
      var key = asString(keys[i] || '').trim();
      var translated = asString(map[key] || '').trim();
      if (!key || !translated) {
        continue;
      }

      // Explicit token forms: [[token]] and {{token}}
      out = out.replace(new RegExp('\\[\\[' + escapeRegExp(key) + '\\]\\]', 'g'), translated);
      out = out.replace(new RegExp('\\{\\{\\s*' + escapeRegExp(key) + '\\s*\\}\\}', 'g'), translated);
    }

    var trimmed = out.trim();
    if (trimmed && Object.prototype.hasOwnProperty.call(map, trimmed)) {
      var replacement = asString(map[trimmed] || '').trim();
      if (replacement) {
        var leadingMatch = out.match(/^\s*/);
        var trailingMatch = out.match(/\s*$/);
        var leading = leadingMatch ? leadingMatch[0] : '';
        var trailing = trailingMatch ? trailingMatch[0] : '';
        out = leading + replacement + trailing;
      }
    }

    return out;
  }

  function getI18nRoots(container) {
    var roots = [];
    var target = container && container.querySelectorAll ? container : document;

    if (container && container.nodeType === 1 && (container.tagName || '').toLowerCase() === 'form') {
      roots.push(container);
    }

    var found = target.querySelectorAll('form.wsf-form, form[id^="ws-form-"]');
    for (var i = 0; i < found.length; i += 1) {
      if (roots.indexOf(found[i]) === -1) {
        roots.push(found[i]);
      }
    }

    if (roots.length === 0 && target === document) {
      roots.push(document.body || document.documentElement || document);
    }

    return roots;
  }

  function applyI18nTextTokens(container, valuesMap) {
    var roots = getI18nRoots(container);
    if (roots.length === 0) {
      return 0;
    }

    var changedCount = 0;
    var attrNames = ['placeholder', 'title', 'aria-label', 'data-label', 'data-placeholder'];

    for (var i = 0; i < roots.length; i += 1) {
      var rootNode = roots[i];
      if (!rootNode) {
        continue;
      }

      var walker = document.createTreeWalker(rootNode, NodeFilter.SHOW_TEXT, null);
      var textNode;
      while ((textNode = walker.nextNode())) {
        if (!textNode || !textNode.nodeValue) {
          continue;
        }
        var originalText = textNode.nodeValue;
        var replacedText = replaceI18nTokens(originalText, valuesMap);
        if (replacedText !== originalText) {
          textNode.nodeValue = replacedText;
          changedCount += 1;
        }
      }

      var attrsNodes = rootNode.querySelectorAll('*');
      for (var j = 0; j < attrsNodes.length; j += 1) {
        var el = attrsNodes[j];
        if (!el || !el.getAttribute) {
          continue;
        }

        for (var k = 0; k < attrNames.length; k += 1) {
          var attr = attrNames[k];
          if (!el.hasAttribute(attr)) {
            continue;
          }
          var originalAttr = asString(el.getAttribute(attr) || '');
          var replacedAttr = replaceI18nTokens(originalAttr, valuesMap);
          if (replacedAttr !== originalAttr) {
            el.setAttribute(attr, replacedAttr);
            changedCount += 1;
          }
        }

        var tagName = asString(el.tagName).toLowerCase();
        var type = asString(el.type).toLowerCase();
        if (tagName === 'input' && (type === 'submit' || type === 'button' || type === 'reset')) {
          var originalValue = asString(el.value || '');
          var replacedValue = replaceI18nTokens(originalValue, valuesMap);
          if (replacedValue !== originalValue) {
            el.value = replacedValue;
            changedCount += 1;
          }
        }
      }
    }

    return changedCount;
  }

  function getMatchingFields(container, fieldName) {
    if (!container || !fieldName) {
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

  function setFieldValue(el, value, allowOverwrite) {
    if (!el) {
      return false;
    }

    var tagName = asString(el.tagName).toLowerCase();
    var type = asString(el.type).toLowerCase();

    if (tagName === 'select') {
      var hasOption = false;
      for (var i = 0; i < el.options.length; i += 1) {
        if (asString(el.options[i].value) === asString(value)) {
          hasOption = true;
          break;
        }
      }
      if (!hasOption) {
        return false;
      }
      if (!allowOverwrite && asString(el.value).trim() !== '') {
        return false;
      }
      el.value = value;
    } else if (type === 'checkbox') {
      var truthy = ['1', 'true', 'yes', 'on'];
      el.checked = truthy.indexOf(asString(value).toLowerCase()) !== -1;
    } else if (type === 'radio') {
      if (asString(el.value) !== asString(value)) {
        return false;
      }
      el.checked = true;
    } else {
      if (!allowOverwrite && type !== 'hidden' && asString(el.value).trim() !== '') {
        return false;
      }
      el.value = value;
    }

    dispatchFieldEvent(el, 'input');
    dispatchFieldEvent(el, 'change');
    return true;
  }

  function applyDefaultsToContainer(container, variant, options) {
    if (!container) {
      return {
        defaults: {},
        variant: variant || 'hero',
        appliedCount: 0
      };
    }

    var opts = options || {};
    var allowOverwrite = !!opts.overwrite;
    var defaults = resolveDefaults(variant);
    var keys = Object.keys(defaults);
    var appliedCount = 0;

    for (var i = 0; i < keys.length; i += 1) {
      var fieldName = keys[i];
      var fields = getMatchingFields(container, fieldName);
      for (var j = 0; j < fields.length; j += 1) {
        if (setFieldValue(fields[j], defaults[fieldName], allowOverwrite)) {
          appliedCount += 1;
        }
      }
    }

    return {
      defaults: defaults,
      variant: asString(variant || getCurrentVariant() || 'hero'),
      appliedCount: appliedCount
    };
  }

  function emitDefaultsEvent(result) {
    if (typeof window.CustomEvent !== 'function') {
      return;
    }

    window.dispatchEvent(new CustomEvent('emerus-ws-defaults-applied', {
      detail: {
        defaults: result.defaults || {},
        variant: result.variant || 'hero',
        pageId: config.pageId || 0,
        pageSlug: config.pageSlug || '',
        appliedCount: result.appliedCount || 0
      }
    }));
  }

  function applyDefaults(formOrSelector, options) {
    var opts = options || {};
    var variant = asString(opts.variant || getCurrentVariant() || 'hero');

    var container;
    if (!formOrSelector && root) {
      container = root.querySelector('.emerus-wsforms-overlay__form');
    } else {
      container = resolveFormElement(formOrSelector);
    }

    var result = applyDefaultsToContainer(container, variant, opts);
    config.resolvedDefaults = result.defaults;
    window.EmerusWsFormsOverlay = config;
    emitDefaultsEvent(result);

    return result;
  }

  function applyDefaultsWithRetry(formOrSelector, options, attempt) {
    var maxAttempts = 30;
    var currentAttempt = attempt || 0;
    var result = applyDefaults(formOrSelector, options);

    if ((result.appliedCount > 0 || Object.keys(result.defaults).length === 0) || currentAttempt >= maxAttempts) {
      return;
    }

    setTimeout(function () {
      applyDefaultsWithRetry(formOrSelector, options, currentAttempt + 1);
    }, 160);
  }

  function getI18nRules() {
    var i18nConfig = config.wsI18n && typeof config.wsI18n === 'object' ? config.wsI18n : {};
    if (!i18nConfig.enabled || !Array.isArray(i18nConfig.rules)) {
      return [];
    }
    return i18nConfig.rules;
  }

  function getPrivacyComplianceConfig() {
    var privacy = config.privacyCompliance && typeof config.privacyCompliance === 'object' ? config.privacyCompliance : {};
    if (!privacy.enabled) {
      return null;
    }
    return privacy;
  }

  function resolvePrivacyComplianceCopy() {
    var privacy = getPrivacyComplianceConfig();
    if (!privacy) {
      return null;
    }

    var lang = getCurrentLangCode();
    var isHr = lang === 'hr';
    var prefix = asString(isHr ? privacy.prefixHr : privacy.prefixEn).trim();
    if (prefix) {
      // WP option sanitization trims trailing space; force exactly one separator before link.
      prefix = prefix.replace(/\s+$/, '') + ' ';
    }
    var linkText = asString(isHr ? privacy.linkTextHr : privacy.linkTextEn).trim();
    var url = asString(isHr ? privacy.urlHr : privacy.urlEn).trim();
    if (!url) {
      url = asString(privacy.fallbackUrl || '').trim();
    }

    return {
      prefix: prefix,
      linkText: linkText,
      url: url
    };
  }

  function applyPrivacyComplianceText(container) {
    var copy = resolvePrivacyComplianceCopy();
    if (!copy) {
      return 0;
    }

    var target = container && container.querySelectorAll ? container : document;
    var roots = getI18nRoots(target);
    var changed = 0;

    for (var i = 0; i < roots.length; i += 1) {
      var root = roots[i];
      if (!root || !root.querySelectorAll) {
        continue;
      }

      var anchors = root.querySelectorAll('label.wsf-label a[href*="privacy"], label.wsf-label a[href*="privat"]');
      for (var j = 0; j < anchors.length; j += 1) {
        var anchor = anchors[j];
        var label = anchor.closest ? anchor.closest('label.wsf-label') : null;
        if (!label) {
          continue;
        }

        if (copy.url && asString(anchor.getAttribute('href') || '') !== copy.url) {
          anchor.setAttribute('href', copy.url);
          changed += 1;
        }

        if (copy.linkText && asString(anchor.textContent || '').trim() !== copy.linkText) {
          anchor.textContent = copy.linkText;
          changed += 1;
        }

        if (copy.prefix) {
          var prefixText = copy.prefix;
          var textNode = null;
          for (var c = 0; c < label.childNodes.length; c += 1) {
            var child = label.childNodes[c];
            if (child === anchor) {
              break;
            }
            if (child && child.nodeType === 3 && asString(child.nodeValue).trim() !== '') {
              textNode = child;
            }
          }

          if (textNode) {
            if (asString(textNode.nodeValue) !== prefixText) {
              textNode.nodeValue = prefixText;
              changed += 1;
            }
          } else {
            label.insertBefore(document.createTextNode(prefixText), anchor);
            changed += 1;
          }
        }
      }
    }

    return changed;
  }

  function applyI18nToContainer(container, options) {
    var target = container && container.querySelectorAll ? container : document;
    var opts = options || {};
    var allowOverwrite = !!opts.overwrite;
    var rules = getI18nRules();
    var appliedCount = 0;
    var resolved = {};

    for (var i = 0; i < rules.length; i += 1) {
      var rule = rules[i];
      var fieldName = asString(rule && rule.field ? rule.field : '').trim();
      if (!fieldName) {
        continue;
      }

      var value = resolveI18nValue(rule);
      if (value === '') {
        continue;
      }

      resolved[fieldName] = value;
      var fields = getMatchingFields(target, fieldName);
      for (var j = 0; j < fields.length; j += 1) {
        if (setFieldValue(fields[j], value, allowOverwrite)) {
          appliedCount += 1;
        }
      }
    }

    // Also support direct token usage in WS labels/placeholders/help/buttons:
    // i18n_key, [[i18n_key]], or {{i18n_key}}
    var tokenChanges = applyI18nTextTokens(target, resolved);
    appliedCount += tokenChanges;

    // Dedicated GDPR / privacy consent label compliance copy.
    appliedCount += applyPrivacyComplianceText(target);

    return {
      values: resolved,
      lang: getCurrentLangCode(),
      appliedCount: appliedCount
    };
  }

  function emitI18nEvent(result) {
    if (typeof window.CustomEvent !== 'function') {
      return;
    }

    window.dispatchEvent(new CustomEvent('emerus-ws-i18n-applied', {
      detail: {
        lang: result.lang || getCurrentLangCode(),
        values: result.values || {},
        appliedCount: result.appliedCount || 0
      }
    }));
  }

  function applyI18n(formOrSelector, options) {
    var container = resolveFormElement(formOrSelector);
    var result = applyI18nToContainer(container || document, options || {});
    config.resolvedI18n = result.values || {};
    window.EmerusWsFormsOverlay = config;
    emitI18nEvent(result);
    return result;
  }

  function applyI18nWithRetry(formOrSelector, options, attempt) {
    var maxAttempts = 30;
    var currentAttempt = attempt || 0;
    var rules = getI18nRules();
    if (rules.length === 0) {
      return;
    }

    var result = applyI18n(formOrSelector, options || {});
    if ((result.appliedCount > 0) || currentAttempt >= maxAttempts) {
      return;
    }

    setTimeout(function () {
      applyI18nWithRetry(formOrSelector, options || {}, currentAttempt + 1);
    }, 160);
  }

  function collectPairsFromForm(form, options) {
    var opts = options || {};
    var includeEmpty = !!opts.includeEmpty;
    var mapFields = opts.mapFields && typeof opts.mapFields === 'object' ? opts.mapFields : {};
    var data = new window.FormData(form);
    var pairs = [];

    data.forEach(function (value, key) {
      var finalKey = asString(mapFields[key] || key).trim();
      if (!finalKey) {
        return;
      }

      var finalValue = asString(value);
      if (!includeEmpty && finalValue.trim() === '') {
        return;
      }

      pairs.push({
        k: finalKey,
        v: finalValue
      });
    });

    return pairs;
  }

  function pairsToLead(pairs) {
    var lead = {};
    for (var i = 0; i < pairs.length; i += 1) {
      var pair = pairs[i];
      if (!pair || !pair.k) {
        continue;
      }

      if (typeof lead[pair.k] === 'undefined') {
        lead[pair.k] = pair.v;
      } else {
        lead[pair.k] = asString(lead[pair.k]) + ', ' + asString(pair.v);
      }
    }
    return lead;
  }

  function mergeObjects(base, extra) {
    var merged = {};
    var key;

    for (key in base) {
      if (Object.prototype.hasOwnProperty.call(base, key)) {
        merged[key] = base[key];
      }
    }

    for (key in extra) {
      if (Object.prototype.hasOwnProperty.call(extra, key)) {
        merged[key] = extra[key];
      }
    }

    return merged;
  }

  function inferFormKey(form, options) {
    var opts = options || {};
    if (opts.formKey) {
      return asString(opts.formKey);
    }

    if (!form) {
      return '';
    }

    var explicit = asString(form.getAttribute('data-form-key') || '');
    if (explicit) {
      return explicit;
    }

    var dataId = asString(form.getAttribute('data-id') || '');
    if (dataId) {
      return 'ws_form_' + dataId;
    }

    if (form.id) {
      return asString(form.id);
    }

    return '';
  }

  function getSessionStore() {
    try {
      return window.sessionStorage;
    } catch (e) {
      return null;
    }
  }

  function getLocalStore() {
    try {
      return window.localStorage;
    } catch (e) {
      return null;
    }
  }

  function parseStoredParamsJson(value) {
    if (!value) {
      return {};
    }
    try {
      var parsed = JSON.parse(value);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function hasNonEmptyParams(data) {
    if (!data || typeof data !== 'object') {
      return false;
    }

    for (var key in data) {
      if (!Object.prototype.hasOwnProperty.call(data, key)) {
        continue;
      }
      if (asString(data[key]).trim() !== '') {
        return true;
      }
    }

    return false;
  }

  function normalizeParamKeys(keys) {
    var allowedKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    if (!Array.isArray(keys)) {
      return allowedKeys.slice();
    }

    var out = [];
    for (var i = 0; i < keys.length; i += 1) {
      var key = asString(keys[i]).toLowerCase().replace(/[^a-z0-9_]/g, '');
      if (allowedKeys.indexOf(key) !== -1 && out.indexOf(key) === -1) {
        out.push(key);
      }
    }

    if (out.length === 0) {
      out = allowedKeys.slice();
    }

    return out;
  }

  function readParamsByKeys(urlString, keys) {
    var data = {};
    var parsed;

    try {
      parsed = new window.URL(urlString || window.location.href, window.location.origin);
    } catch (e) {
      parsed = null;
    }

    if (!parsed) {
      return data;
    }

    for (var i = 0; i < keys.length; i += 1) {
      var key = keys[i];
      var value = parsed.searchParams.get(key);
      if (value !== null && asString(value).trim() !== '') {
        data[key] = asString(value).trim();
      }
    }

    // If there are no classic UTM params, derive only utm_source from click IDs.
    if (!hasNonEmptyParams(data)) {
      if (asString(parsed.searchParams.get('gclid') || '').trim() !== '') {
        data.utm_source = 'google';
      } else if (asString(parsed.searchParams.get('fbclid') || '').trim() !== '') {
        data.utm_source = 'fb';
      }
    }

    return data;
  }

  function serializeFlatParams(data) {
    var pairs = [];
    var key;

    for (key in data) {
      if (!Object.prototype.hasOwnProperty.call(data, key)) {
        continue;
      }
      if (asString(data[key]).trim() === '') {
        continue;
      }
      pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(asString(data[key])));
    }

    return pairs.join('&');
  }

  function stripUrlParams(urlString) {
    var raw = asString(urlString).trim();
    if (!raw) {
      return '';
    }

    try {
      var parsed = new window.URL(raw, window.location.origin);
      return parsed.origin + parsed.pathname;
    } catch (e) {
      return raw.split('#')[0].split('?')[0];
    }
  }

  function initSessionContext() {
    var ctxConfig = config.globalContext && typeof config.globalContext === 'object' ? config.globalContext : {};
    var store = getSessionStore();
    var localStore = getLocalStore();
    var paramKeys = normalizeParamKeys(ctxConfig.utmKeys || []);
    var currentUrl = window.location.href;
    var currentTitle = document.title;
    var currentParams = readParamsByKeys(currentUrl, paramKeys);
    var firstUrl = currentUrl;
    var firstTitle = currentTitle;
    var firstParams = currentParams;
    var lastAttributionUrl = '';
    var lastAttributionParams = {};
    var currentHasUtm = hasNonEmptyParams(currentParams);

    if (store) {
      var storedUrl = store.getItem('emerus_first_session_url');
      var storedTitle = store.getItem('emerus_first_session_title');
      var storedParams = store.getItem('emerus_first_session_utm');

      // Reset first-session attribution whenever a new UTM hit is detected.
      if (currentHasUtm) {
        store.setItem('emerus_first_session_url', currentUrl);
        store.setItem('emerus_first_session_title', currentTitle);
        store.setItem('emerus_first_session_utm', JSON.stringify(currentParams));
        firstUrl = currentUrl;
        firstTitle = currentTitle;
        firstParams = currentParams;
      } else {
        if (!storedUrl) {
          store.setItem('emerus_first_session_url', currentUrl);
        } else {
          firstUrl = storedUrl;
        }

        if (!storedTitle) {
          store.setItem('emerus_first_session_title', currentTitle);
        } else {
          firstTitle = storedTitle;
        }

        if (!storedParams) {
          store.setItem('emerus_first_session_utm', JSON.stringify(currentParams));
        } else {
          firstParams = parseStoredParamsJson(storedParams);
        }
      }
    }

    if (localStore) {
      var storedLastUtmUrl = asString(localStore.getItem('emerus_last_utm_url') || '').trim();
      var storedLastUtmParams = parseStoredParamsJson(localStore.getItem('emerus_last_utm_params'));

      if (hasNonEmptyParams(currentParams)) {
        localStore.setItem('emerus_last_utm_url', currentUrl);
        localStore.setItem('emerus_last_utm_params', JSON.stringify(currentParams));
        lastAttributionUrl = currentUrl;
        lastAttributionParams = currentParams;
      } else {
        lastAttributionUrl = storedLastUtmUrl;
        lastAttributionParams = storedLastUtmParams;
      }
    } else if (store) {
      // Fallback for environments without localStorage.
      var sessionLastUtmUrl = asString(store.getItem('emerus_last_utm_url') || '').trim();
      var sessionLastUtmParams = parseStoredParamsJson(store.getItem('emerus_last_utm_params'));

      if (hasNonEmptyParams(currentParams)) {
        store.setItem('emerus_last_utm_url', currentUrl);
        store.setItem('emerus_last_utm_params', JSON.stringify(currentParams));
        lastAttributionUrl = currentUrl;
        lastAttributionParams = currentParams;
      } else {
        lastAttributionUrl = sessionLastUtmUrl;
        lastAttributionParams = sessionLastUtmParams;
      }
    }

    return {
      currentUrl: currentUrl,
      currentTitle: currentTitle,
      currentParams: currentParams,
      firstUrl: firstUrl,
      firstTitle: firstTitle,
      firstParams: firstParams,
      lastAttributionUrl: lastAttributionUrl,
      lastAttributionParams: lastAttributionParams,
      paramKeys: paramKeys
    };
  }

  function upsertRow(rows, key, value) {
    if (!Array.isArray(rows) || !key) {
      return;
    }

    var normalizedKey = asString(key);
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      if (row && asString(row.k) === normalizedKey) {
        if (isEmptyOrPlaceholderValue(row.v)) {
          row.v = asString(value);
        }
        return;
      }
    }

    rows.push({ k: normalizedKey, v: asString(value) });
  }

  function injectGlobalContext(payload) {
    var ctxConfig = config.globalContext && typeof config.globalContext === 'object' ? config.globalContext : {};
    if (!ctxConfig.enabled) {
      return payload;
    }

    var context = initSessionContext();
    var useFirstSession = !!ctxConfig.useFirstSession;
    var landingValueRaw = useFirstSession ? context.firstUrl : context.currentUrl;
    var landingValue = stripUrlParams(landingValueRaw);
    var titleValue = asString((payload && payload.page_title) ? payload.page_title : context.currentTitle);
    if (titleValue.indexOf('|') !== -1) {
      titleValue = asString(titleValue.split('|')[0]).trim();
    }
    var pageUrlValue = asString((payload && payload.page_url) ? payload.page_url : context.currentUrl).trim();
    var utmData = hasNonEmptyParams(context.lastAttributionParams)
      ? context.lastAttributionParams
      : (useFirstSession ? context.firstParams : context.currentParams);
    var utmValue = serializeFlatParams(utmData);

    var landingField = asString(ctxConfig.landingField || '').trim();
    var titleField = asString(ctxConfig.pageTitleField || '').trim();
    var utmField = asString(ctxConfig.utmField || '').trim();
    var pageUrlField = asString(ctxConfig.pageUrlField || 'Page URL').trim();

    var finalPayload = payload && typeof payload === 'object' ? payload : {};
    if (!finalPayload.lead || typeof finalPayload.lead !== 'object') {
      finalPayload.lead = {};
    }

    if (landingField && isEmptyOrPlaceholderValue(finalPayload.lead[landingField])) {
      finalPayload.lead[landingField] = landingValue;
    }
    if (titleField && isEmptyOrPlaceholderValue(finalPayload.lead[titleField])) {
      finalPayload.lead[titleField] = titleValue;
    }
    if (utmField && utmValue && isEmptyOrPlaceholderValue(finalPayload.lead[utmField])) {
      finalPayload.lead[utmField] = utmValue;
    }
    if (pageUrlField && isEmptyOrPlaceholderValue(finalPayload.lead[pageUrlField])) {
      finalPayload.lead[pageUrlField] = pageUrlValue;
    }

    if (!Array.isArray(finalPayload.rows)) {
      finalPayload.rows = [];
    }

    if (landingField) {
      upsertRow(finalPayload.rows, landingField, landingValue);
    }
    if (titleField) {
      upsertRow(finalPayload.rows, titleField, titleValue);
    }
    if (utmField && utmValue) {
      upsertRow(finalPayload.rows, utmField, utmValue);
    }
    if (pageUrlField) {
      upsertRow(finalPayload.rows, pageUrlField, pageUrlValue);
    }

    return finalPayload;
  }

  function rowsToObject(rows) {
    var obj = {};
    if (!Array.isArray(rows)) {
      return obj;
    }

    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      if (!row || !row.k) {
        continue;
      }
      obj[asString(row.k)] = asString(row.v || '');
    }

    return obj;
  }

  function objectToRows(obj) {
    var rows = [];
    if (!obj || typeof obj !== 'object') {
      return rows;
    }

    for (var key in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, key)) {
        continue;
      }
      var value = asString(obj[key]);
      if (value.trim() === '') {
        continue;
      }
      rows.push({
        k: asString(key),
        v: value
      });
    }

    return rows;
  }

  function normalizePayloadShape(payload) {
    var out = payload && typeof payload === 'object' ? payload : {};
    var hasRows = Array.isArray(out.rows);
    var hasLead = out.lead && typeof out.lead === 'object' && !Array.isArray(out.lead);

    if (!hasRows) {
      out.rows = [];
    }
    if (!hasLead) {
      out.lead = {};
    }

    var rowsObj = rowsToObject(out.rows);
    var leadObj = out.lead;
    var key;

    // Fill missing lead values from rows.
    for (key in rowsObj) {
      if (!Object.prototype.hasOwnProperty.call(rowsObj, key)) {
        continue;
      }
      if (isEmptyOrPlaceholderValue(leadObj[key])) {
        leadObj[key] = rowsObj[key];
      }
    }

    // Ensure rows include lead values as well.
    for (key in leadObj) {
      if (!Object.prototype.hasOwnProperty.call(leadObj, key)) {
        continue;
      }
      var val = asString(leadObj[key]);
      if (val.trim() === '') {
        continue;
      }
      upsertRow(out.rows, key, val);
    }

    // If rows were empty but lead had values, regenerate rows from lead.
    if (out.rows.length === 0) {
      out.rows = objectToRows(leadObj);
    }

    out.lead = leadObj;
    return out;
  }

  function getDataLayerTargets() {
    var settings = config.dataLayer && typeof config.dataLayer === 'object' ? config.dataLayer : {};
    var objectName = asString(settings.objectName || 'dataLayer').trim() || 'dataLayer';
    var names = [];
    var seenNames = {};
    var out = [];

    function addName(name) {
      var safeName = asString(name).trim();
      if (!safeName) {
        return;
      }
      if (seenNames[safeName]) {
        return;
      }
      seenNames[safeName] = true;
      names.push(safeName);
    }

    addName(objectName);
    addName('dataLayer');

    for (var i = 0; i < names.length; i += 1) {
      var name = names[i];
      if (!window[name] || typeof window[name].push !== 'function') {
        window[name] = [];
      }
      out.push({
        name: name,
        ref: window[name]
      });
    }

    return out;
  }

  function pushDataLayerEvent(type, payload, meta) {
    var settings = config.dataLayer && typeof config.dataLayer === 'object' ? config.dataLayer : {};
    if (settings.enabled === false || settings.enabled === 0 || settings.enabled === '0') {
      return;
    }

    var eventName = type === 'error'
      ? asString(settings.errorEvent || 'emerus_zoho_submit_error')
      : asString(settings.successEvent || 'emerus_zoho_submit_success');

    var payloadObj = payload && typeof payload === 'object' ? payload : {};
    var leadObj = payloadObj.lead && typeof payloadObj.lead === 'object'
      ? payloadObj.lead
      : rowsToObject(payloadObj.rows || []);

    var dlEvent = {
      event: eventName,
      emerus_status: type,
      emerus_form_variant: asString(payloadObj.form_variant || ''),
      emerus_form_key: asString(payloadObj.form_key || ''),
      emerus_page_url: asString(payloadObj.page_url || window.location.href),
      emerus_page_title: asString(payloadObj.page_title || document.title),
      emerus_rows_count: Array.isArray(payloadObj.rows) ? payloadObj.rows.length : 0,
      emerus_lead_fields: Object.keys(leadObj)
    };

    var metaObj = meta && typeof meta === 'object' ? meta : {};
    if (typeof metaObj.httpStatus !== 'undefined') {
      dlEvent.emerus_http_status = metaObj.httpStatus;
    }
    if (metaObj.errorMessage) {
      dlEvent.emerus_error_message = asString(metaObj.errorMessage);
    }
    if (metaObj.zohoId) {
      dlEvent.emerus_zoho_id = asString(metaObj.zohoId);
    }
    if (metaObj.dryRun === true) {
      dlEvent.emerus_dry_run = true;
    }

    if (settings.includePayload) {
      dlEvent.emerus_payload = payloadObj;
      dlEvent.emerus_lead = leadObj;
    }

    var targets = getDataLayerTargets();
    var seenRefs = [];

    for (var t = 0; t < targets.length; t += 1) {
      var target = targets[t];
      if (!target || !target.ref || typeof target.ref.push !== 'function') {
        continue;
      }
      if (seenRefs.indexOf(target.ref) !== -1) {
        continue;
      }
      seenRefs.push(target.ref);
      target.ref.push(dlEvent);
    }
  }

  function prepareLeadPayload(payload) {
    var base = payload && typeof payload === 'object' ? payload : {};
    var copy = {};
    var key;

    for (key in base) {
      if (Object.prototype.hasOwnProperty.call(base, key)) {
        copy[key] = base[key];
      }
    }

    copy = normalizePayloadShape(copy);
    copy = injectGlobalContext(copy);
    return normalizePayloadShape(copy);
  }

  function previewLead(payload, options) {
    var opts = options && typeof options === 'object' ? options : {};
    var finalPayload = prepareLeadPayload(payload);
    var pushEnabled = opts.pushDataLayer !== false;
    var eventType = opts.eventType === 'error' ? 'error' : 'success';
    var meta = {
      dryRun: true
    };

    if (opts.errorMessage) {
      meta.errorMessage = asString(opts.errorMessage);
    }

    if (pushEnabled) {
      pushDataLayerEvent(eventType, finalPayload, meta);
    }

    if (window.console && typeof window.console.log === 'function') {
      window.console.log('Emerus final payload (dry run)', finalPayload);
    }

    return finalPayload;
  }

  async function previewBackendLead(payload) {
    if (!config.restUrl) {
      throw new Error('Zoho endpoint URL is missing.');
    }

    var finalPayload = prepareLeadPayload(payload);
    finalPayload.preview_only = true;

    var response = await fetch(config.restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce || ''
      },
      body: JSON.stringify(finalPayload || {})
    });

    var data = await response.json().catch(function () {
      return {};
    });

    if (!response.ok) {
      var message = (data && data.message) ? data.message : 'Backend preview failed.';
      throw new Error(message);
    }

    return {
      payload: finalPayload,
      response: data
    };
  }

  async function sendLead(payload) {
    if (!config.restUrl) {
      pushDataLayerEvent('error', payload, {
        errorMessage: 'Zoho endpoint URL is missing.'
      });
      throw new Error('Zoho endpoint URL is missing.');
    }

    var finalPayload = prepareLeadPayload(payload);
    if (window.console && typeof window.console.log === 'function') {
      window.console.log('Emerus final payload', finalPayload);
    }
    var response;
    var data;

    try {
      response = await fetch(config.restUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce || ''
        },
        body: JSON.stringify(finalPayload || {})
      });

      data = await response.json().catch(function () {
        return {};
      });
    } catch (networkError) {
      pushDataLayerEvent('error', finalPayload, {
        errorMessage: networkError && networkError.message ? networkError.message : 'Network error'
      });
      throw networkError;
    }

    if (!response.ok) {
      var message = (data && data.message) ? data.message : 'Zoho request failed.';
      pushDataLayerEvent('error', finalPayload, {
        httpStatus: response.status,
        errorMessage: message
      });
      throw new Error(message);
    }

    var zohoId = '';
    if (data && data.response && data.response.data && data.response.data[0] && data.response.data[0].details) {
      zohoId = asString(data.response.data[0].details.id || '');
    }

    pushDataLayerEvent('success', finalPayload, {
      httpStatus: response.status,
      zohoId: zohoId
    });

    return data;
  }

  async function sendWsForm(formOrSelector, options) {
    var opts = options || {};
    var form = resolveFormElement(formOrSelector);

    if (!form) {
      throw new Error('WS form element not found.');
    }

    var variant = asString(opts.formVariant || opts.variant || getCurrentVariant() || 'hero');
    if (opts.applyDefaults !== false) {
      applyDefaultsWithRetry(form, { variant: variant, overwrite: !!opts.overwriteDefaults }, 0);
    }
    if (opts.applyI18n !== false) {
      applyI18nWithRetry(form, { overwrite: !!opts.overwriteI18n }, 0);
    }

    var pairs = collectPairsFromForm(form, {
      includeEmpty: !!opts.includeEmpty,
      mapFields: opts.mapFields || {}
    });

    var lead = pairsToLead(pairs);
    var staticLead = opts.staticLead && typeof opts.staticLead === 'object' ? opts.staticLead : {};
    lead = mergeObjects(lead, staticLead);

    var mode = asString(opts.mode || 'rows').toLowerCase();
    if (['rows', 'lead', 'both'].indexOf(mode) === -1) {
      mode = 'rows';
    }

    var payload = {
      form_variant: variant,
      form_key: inferFormKey(form, opts),
      page_url: window.location.href,
      page_title: document.title
    };

    if (mode === 'rows' || mode === 'both') {
      payload.rows = pairs;
    }

    if (mode === 'lead' || mode === 'both') {
      payload.lead = lead;
    }

    if (opts.extraPayload && typeof opts.extraPayload === 'object') {
      payload = mergeObjects(payload, opts.extraPayload);
    }

    return sendLead(payload);
  }

  config.getResolvedDefaults = function (variant) {
    var values = resolveDefaults(variant || getCurrentVariant());
    var copy = {};
    var keys = Object.keys(values);
    for (var i = 0; i < keys.length; i += 1) {
      copy[keys[i]] = values[keys[i]];
    }
    return copy;
  };

  config.applyResolvedDefaults = function (formOrSelector, options) {
    return applyDefaults(formOrSelector, options || {});
  };

  config.getResolvedI18n = function () {
    var values = config.resolvedI18n && typeof config.resolvedI18n === 'object' ? config.resolvedI18n : {};
    var copy = {};
    var keys = Object.keys(values);
    for (var i = 0; i < keys.length; i += 1) {
      copy[keys[i]] = values[keys[i]];
    }
    return copy;
  };

  config.applyI18n = function (formOrSelector, options) {
    return applyI18n(formOrSelector, options || {});
  };

  window.EmerusWsFormsOverlay = config;

  window.EmerusZoho = {
    endpoint: config.restUrl || '',
    nonce: config.nonce || '',
    sendLead: sendLead,
    previewLead: previewLead,
    previewBackendLead: previewBackendLead,
    sendWsForm: sendWsForm,
    applyDefaults: function (formOrSelector, options) {
      return applyDefaults(formOrSelector, options || {});
    },
    applyI18n: function (formOrSelector, options) {
      return applyI18n(formOrSelector, options || {});
    },
    collectRows: function (formOrSelector, options) {
      var form = resolveFormElement(formOrSelector);
      if (!form) {
        return [];
      }
      return collectPairsFromForm(form, options || {});
    },
    collectLead: function (formOrSelector, options) {
      var rows = this.collectRows(formOrSelector, options || {});
      var lead = pairsToLead(rows);
      var staticLead = options && options.staticLead && typeof options.staticLead === 'object' ? options.staticLead : {};
      return mergeObjects(lead, staticLead);
    },
    getGlobalContext: function () {
      return initSessionContext();
    }
  };

  if (config.globalContext && config.globalContext.enabled) {
    var bootContext = initSessionContext();
    config.globalContextState = bootContext;
    window.EmerusWsFormsOverlay = config;
  }

  var wsI18nEnabled = getI18nRules().length > 0;

  if (root) {
    var maxWidth = parseInt(root.getAttribute('data-max-width') || config.maxWidth || 420, 10);
    if (!Number.isFinite(maxWidth)) {
      maxWidth = 420;
    }
    root.style.setProperty('--ewo-max-width', maxWidth + 'px');

    placeOverlay();
    applyDefaultsWithRetry(null, { variant: getCurrentVariant() }, 0);
  }

  if (wsI18nEnabled) {
    applyI18nWithRetry(null, { overwrite: false }, 0);
  }

  var refreshPlacement = debounce(function () {
    if (root) {
      placeOverlay();
      applyDefaultsWithRetry(null, { variant: getCurrentVariant() }, 0);
    }
    if (wsI18nEnabled) {
      applyI18nWithRetry(null, { overwrite: false }, 0);
    }
  }, 120);

  window.addEventListener('load', refreshPlacement);
  if (root) {
    window.addEventListener('resize', refreshPlacement);
    if (typeof mobileQuery.addEventListener === 'function') {
      mobileQuery.addEventListener('change', refreshPlacement);
    } else if (typeof mobileQuery.addListener === 'function') {
      mobileQuery.addListener(refreshPlacement);
    }
  }

  if (typeof window.MutationObserver === 'function') {
    var observeTarget = root || document.body;
    if (observeTarget) {
      var observer = new MutationObserver(debounce(function () {
        if (root) {
          applyDefaultsWithRetry(null, { variant: getCurrentVariant() }, 0);
        }
        if (wsI18nEnabled) {
          applyI18nWithRetry(null, { overwrite: false }, 0);
        }
      }, 80));

      observer.observe(observeTarget, {
        childList: true,
        subtree: true
      });
    }
  }
})();
