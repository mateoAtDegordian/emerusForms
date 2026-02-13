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

  function normalizeParamKeys(keys) {
    if (!Array.isArray(keys)) {
      return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    }

    var out = [];
    for (var i = 0; i < keys.length; i += 1) {
      var key = asString(keys[i]).toLowerCase().replace(/[^a-z0-9_]/g, '');
      if (key && out.indexOf(key) === -1) {
        out.push(key);
      }
    }

    if (out.length === 0) {
      out.push('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term');
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

  function initSessionContext() {
    var ctxConfig = config.globalContext && typeof config.globalContext === 'object' ? config.globalContext : {};
    var store = getSessionStore();
    var paramKeys = normalizeParamKeys(ctxConfig.utmKeys || []);
    var currentUrl = window.location.href;
    var currentTitle = document.title;
    var currentParams = readParamsByKeys(currentUrl, paramKeys);
    var firstUrl = currentUrl;
    var firstTitle = currentTitle;
    var firstParams = currentParams;

    if (store) {
      var storedUrl = store.getItem('emerus_first_session_url');
      var storedTitle = store.getItem('emerus_first_session_title');
      var storedParams = store.getItem('emerus_first_session_utm');

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
        try {
          firstParams = JSON.parse(storedParams) || {};
        } catch (e) {
          firstParams = {};
        }
      }
    }

    return {
      currentUrl: currentUrl,
      currentTitle: currentTitle,
      currentParams: currentParams,
      firstUrl: firstUrl,
      firstTitle: firstTitle,
      firstParams: firstParams,
      paramKeys: paramKeys
    };
  }

  function appendRowIfMissing(rows, key, value) {
    if (!Array.isArray(rows) || !key) {
      return;
    }

    var normalizedKey = asString(key);
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      if (row && asString(row.k) === normalizedKey) {
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
    var landingValue = useFirstSession ? context.firstUrl : context.currentUrl;
    var titleValue = context.currentTitle;
    var utmData = useFirstSession ? context.firstParams : context.currentParams;
    var utmValue = serializeFlatParams(utmData);

    var landingField = asString(ctxConfig.landingField || '').trim();
    var titleField = asString(ctxConfig.pageTitleField || '').trim();
    var utmField = asString(ctxConfig.utmField || '').trim();

    var finalPayload = payload && typeof payload === 'object' ? payload : {};
    if (!finalPayload.lead || typeof finalPayload.lead !== 'object') {
      finalPayload.lead = {};
    }

    if (landingField && typeof finalPayload.lead[landingField] === 'undefined') {
      finalPayload.lead[landingField] = landingValue;
    }
    if (titleField && typeof finalPayload.lead[titleField] === 'undefined') {
      finalPayload.lead[titleField] = titleValue;
    }
    if (utmField && utmValue && typeof finalPayload.lead[utmField] === 'undefined') {
      finalPayload.lead[utmField] = utmValue;
    }

    if (!Array.isArray(finalPayload.rows)) {
      finalPayload.rows = [];
    }

    if (landingField) {
      appendRowIfMissing(finalPayload.rows, landingField, landingValue);
    }
    if (titleField) {
      appendRowIfMissing(finalPayload.rows, titleField, titleValue);
    }
    if (utmField && utmValue) {
      appendRowIfMissing(finalPayload.rows, utmField, utmValue);
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

  function getDataLayerArray() {
    var settings = config.dataLayer && typeof config.dataLayer === 'object' ? config.dataLayer : {};
    var objectName = asString(settings.objectName || 'dataLayer').trim() || 'dataLayer';

    if (!window[objectName] || !Array.isArray(window[objectName])) {
      window[objectName] = [];
    }

    return window[objectName];
  }

  function pushDataLayerEvent(type, payload, meta) {
    var settings = config.dataLayer && typeof config.dataLayer === 'object' ? config.dataLayer : {};
    if (!settings.enabled) {
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

    if (settings.includePayload) {
      dlEvent.emerus_payload = payloadObj;
      dlEvent.emerus_lead = leadObj;
    }

    getDataLayerArray().push(dlEvent);
  }

  async function sendLead(payload) {
    if (!config.restUrl) {
      throw new Error('Zoho endpoint URL is missing.');
    }

    var finalPayload = injectGlobalContext(payload);
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
