<?php
/**
 * Plugin Name: Emerus WS Forms Overlay
 * Description: Injects WS Form overlays in Bricks hero sections with page targeting, EN/HR copy, and optional Zoho CRM lead forwarding.
 * Version: 0.4.10
 * Author: Emerus
 * Text Domain: emerus-wsforms-overlay
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Emerus_WSForms_Overlay {
    const OPTION_KEY = 'emerus_wsforms_overlay_options';

    public function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'handle_admin_save']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_overlay']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('emerus-wsforms-overlay', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_admin_page() {
        add_options_page(
            'Emerus WS Forms Overlay',
            'Emerus WS Forms Overlay',
            'manage_options',
            'emerus-wsforms-overlay',
            [$this, 'render_admin_page']
        );
    }

    public function handle_admin_save() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['emerus_wsforms_overlay_save'])) {
            return;
        }

        check_admin_referer('emerus_wsforms_overlay_save_action', 'emerus_wsforms_overlay_nonce');

        $raw     = isset($_POST[self::OPTION_KEY]) ? (array) $_POST[self::OPTION_KEY] : [];
        $options = $this->sanitize_options($raw);

        update_option(self::OPTION_KEY, $options);
        add_settings_error('emerus_wsforms_overlay_messages', 'emerus_wsforms_overlay_saved', __('Settings saved.', 'emerus-wsforms-overlay'), 'updated');
    }

    private function default_custom_js_template() {
        return <<<'JS'
(function () {
  // Optional hook: runs after plugin script and after defaults are applied.
  // Useful for advanced WS Form field logic.
  window.addEventListener('emerus-ws-defaults-applied', function (event) {
    var detail = event.detail || {};
    // Example:
    // console.log('Resolved defaults:', detail.defaults, 'variant:', detail.variant);
  });
})();
JS;
    }

    private function ws_integration_template() {
        return <<<'JS'
/*
WS Form custom JS example using OFFICIAL WS variables (Actions scope):
https://wsform.com/knowledgebase/variables/#field

1) Add this script to WS Form Action -> Run JavaScript.
2) Replace placeholder field IDs inside #field(...).
3) Keep this action early in order (before message / redirects).
4) For payload preview only set dryRun = true (still applies global context + DataLayer preview event).
*/
(async function () {
  if (!window.EmerusZoho || !window.EmerusZoho.sendLead) {
    return;
  }

  var formVariant = 'product'; // hero or product
  var manualFormKey = ''; // optional override, e.g. services_products_en
  var dryRun = false; // true = log payload only
  var customInterestValue = ''; // optional hard override (if set, always used as fallback)
  var customSubSource = ''; // optional override for non-plugin forms / custom grouping
  // Interest source mode per form:
  // auto = form value first, then URL mapping fallback
  // form = only form value (no URL fallback)
  // path = always URL mapping (ignores form value for Interes)
  var interestSourceMode = 'auto'; // auto | form | path

  // Explicit URL regex rules: both HR and EN URLs map to HR Zoho values.
  // Parent path match (children included), as requested:
  // #^/solar-systems/#
  // #^/building-systems/#
  // #^/industrial-profiles/#
  // #^/hr/gradevinski-sustavi/#
  // #^/hr/solarni-sustavi/#
  // #^/hr/industrijski-profili/#
  var interestPathRules = [
    { pattern: '#^/solar-systems/#i', value: 'Solarni sustavi' },
    { pattern: '#^/building-systems/#i', value: 'Građevinski sustavi' },
    { pattern: '#^/industrial-profiles/#i', value: 'Industrijski profili' },
    { pattern: '#^/hr/gradevinski-sustavi/#i', value: 'Građevinski sustavi' },
    { pattern: '#^/hr/solarni-sustavi/#i', value: 'Solarni sustavi' },
    { pattern: '#^/hr/industrijski-profili/#i', value: 'Industrijski profili' }
  ];

  // Explicit slug to Croatian product/service title mapping.
  // Longest slug match wins (child page beats parent).
  var productPathHrMap = [
    { slug: '/building-systems/entrance-door/', value: 'Ulazna vrata' },
    { slug: '/building-systems/facade-systems/', value: 'Fasadni sustavi' },
    { slug: '/building-systems/fencing-systems/', value: 'Ogradni sustavi' },
    { slug: '/building-systems/sliding-systems/', value: 'Klizni sustavi' },
    { slug: '/building-systems/sun-protection/', value: 'Zaštita od sunca' },
    { slug: '/building-systems/windows-and-doors/', value: 'Prozori i vrata' },
    { slug: '/building-systems/', value: 'Građevinski sustavi' },

    { slug: '/hr/gradevinski-sustavi/fasadni-sustavi/', value: 'Fasadni sustavi' },
    { slug: '/hr/gradevinski-sustavi/klizni-sustavi/', value: 'Klizni sustavi' },
    { slug: '/hr/gradevinski-sustavi/ogradni-sustavi/', value: 'Ogradni sustavi' },
    { slug: '/hr/gradevinski-sustavi/prozori-i-vrata/', value: 'Prozori i vrata' },
    { slug: '/hr/gradevinski-sustavi/ulazna-vrata/', value: 'Ulazna vrata' },
    { slug: '/hr/gradevinski-sustavi/zastita-od-sunca/', value: 'Zaštita od sunca' },
    { slug: '/hr/gradevinski-sustavi/', value: 'Građevinski sustavi' },

    { slug: '/hr/industrijski-profili/rjesenja-po-mjeri/', value: 'Rješenja po mjeri' },
    { slug: '/hr/industrijski-profili/standarni-profili/', value: 'Standarni profili' },
    { slug: '/hr/industrijski-profili/', value: 'Industrijski profili' },

    { slug: '/hr/solarni-sustavi/crijepni-krov/', value: 'Crijepni krov' },
    { slug: '/hr/solarni-sustavi/kosi-industrijski-krov/', value: 'Kosi industrijski krov' },
    { slug: '/hr/solarni-sustavi/podni-sustavi/', value: 'Podni sustavi' },
    { slug: '/hr/solarni-sustavi/ravni-krov/', value: 'Ravni krov' },
    { slug: '/hr/solarni-sustavi/', value: 'Solarni sustavi' },

    { slug: '/industrial-profiles/customized-solutions/', value: 'Rješenja po mjeri' },
    { slug: '/industrial-profiles/standard-profiles/', value: 'Standarni profili' },
    { slug: '/industrial-profiles/', value: 'Industrijski profili' },

    { slug: '/solar-systems/flat-roof/', value: 'Ravni krov' },
    { slug: '/solar-systems/floor-systems/', value: 'Podni sustavi' },
    { slug: '/solar-systems/sloping-industrial-roof/', value: 'Kosi industrijski krov' },
    { slug: '/solar-systems/tiled-roof/', value: 'Crijepni krov' },
    { slug: '/solar-systems/', value: 'Solarni sustavi' }
  ];

  // Replace placeholders with WS field IDs from builder (e.g. 351, 352, 353...).
  // Remove keys you do not need.
  var lead = {
    Last_Name: '#field(PLACEHOLDER_LAST_NAME_FIELD_ID)',
    First_Name: '#field(PLACEHOLDER_FIRST_NAME_FIELD_ID)',
    Email: '#field(PLACEHOLDER_EMAIL_FIELD_ID)',
    Phone: '#field(PLACEHOLDER_PHONE_FIELD_ID)',
    Description: '#field(PLACEHOLDER_DESCRIPTION_FIELD_ID)',
    // For select / multiselect use #field(ID, ", ")
    Interes: '#field(PLACEHOLDER_INTEREST_FIELD_ID, ", ")',
    'Proizvod/Usluga': '#field(PLACEHOLDER_PRODUCT_FIELD_ID, ", ")',
    // Leave these empty: plugin global context injects them automatically.
    'Landing Page': '',
    'Page URL': '',
    'Page Title': '',
    'UTM polja': ''
  };

  function norm(value) {
    return String(value || '').trim();
  }

  function isUnparsedVariable(value) {
    var v = norm(value);
    return /^#[a-z_]+(\(|$)/i.test(v);
  }

  function cleanupValue(value) {
    var v = norm(value);
    if (!v) {
      return '';
    }
    if (/^[\s"',\\()[\],.-]+$/.test(v)) {
      return '';
    }
    if (v.indexOf('PLACEHOLDER_') !== -1) {
      return '';
    }
    if (isUnparsedVariable(v)) {
      return '';
    }
    return v;
  }

  function cleanupUtm(serialized) {
    var raw = cleanupValue(serialized);
    if (!raw) {
      return '';
    }
    var parts = raw.split('&');
    var out = [];
    for (var i = 0; i < parts.length; i += 1) {
      var part = norm(parts[i]);
      if (!part) {
        continue;
      }
      var kv = part.split('=');
      var key = norm(kv[0]);
      var val = kv.length > 1 ? norm(kv.slice(1).join('=')) : '';
      if (!key || !val || val.charAt(0) === '#') {
        continue;
      }
      out.push(key + '=' + val);
    }
    return out.join('&');
  }

  function inferProductServiceFromPage() {
    var postTitle = cleanupValue('#post_title');
    if (postTitle) {
      return postTitle;
    }

    var title = norm(document.title);
    if (!title) {
      return '';
    }

    return norm(title.split('|')[0]);
  }

  function inferFormKey() {
    var manual = cleanupValue(manualFormKey);
    if (manual) {
      return manual;
    }

    // WS variables (Actions scope)
    var formId = cleanupValue('#form_id');
    var formLabel = cleanupValue('#form_label');

    if (/^\d+$/.test(formId)) {
      return 'ws_form_' + formId;
    }
    if (formLabel) {
      return formLabel.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }
    return '';
  }

  function normalizePath(value) {
    var path = norm(value).toLowerCase();
    if (!path) {
      return '/';
    }
    if (path.charAt(0) !== '/') {
      path = '/' + path;
    }
    path = path.replace(/\/+/g, '/');
    if (path.charAt(path.length - 1) !== '/') {
      path += '/';
    }
    return path;
  }

  function pathMatchesRule(path, regexPattern) {
    var current = normalizePath(path);
    var pattern = norm(regexPattern);
    if (!pattern) {
      return false;
    }

    try {
      // Pattern style: #^/building-systems/#i
      if (pattern.charAt(0) === '#') {
        var lastHash = pattern.lastIndexOf('#');
        if (lastHash > 0) {
          var body = pattern.slice(1, lastHash);
          var flags = pattern.slice(lastHash + 1);
          return new RegExp(body, flags).test(current);
        }
      }
      // Fallback: treat as plain regex body, case-insensitive.
      return new RegExp(pattern, 'i').test(current);
    } catch (e) {
      return false;
    }
  }

  function inferInterestFromPath() {
    var override = cleanupValue(customInterestValue);
    if (override) {
      return override;
    }

    var path = '';
    try {
      path = norm(window.location.pathname).toLowerCase();
    } catch (e) {
      path = '';
    }
    if (!path) {
      return '';
    }

    for (var i = 0; i < interestPathRules.length; i += 1) {
      var rule = interestPathRules[i] || {};
      if (pathMatchesRule(path, rule.pattern || '')) {
        return cleanupValue(rule.value || '');
      }
    }

    return '';
  }

  function inferCroatianProductByPath() {
    var path = '';
    try {
      path = normalizePath(window.location.pathname || '');
    } catch (e) {
      path = '';
    }

    if (!path) {
      return '';
    }

    var best = '';
    var bestLen = 0;

    for (var i = 0; i < productPathHrMap.length; i += 1) {
      var item = productPathHrMap[i] || {};
      var slug = normalizePath(item.slug || '');
      var value = cleanupValue(item.value || '');
      if (!slug || !value) {
        continue;
      }

      if (path === slug || path.indexOf(slug) === 0) {
        if (slug.length > bestLen) {
          bestLen = slug.length;
          best = value;
        }
      }
    }

    return best;
  }

  try {
    var finalLead = {};
    var keys = Object.keys(lead);
    for (var i = 0; i < keys.length; i += 1) {
      var key = keys[i];
      var value = key === 'UTM polja' ? cleanupUtm(lead[key]) : cleanupValue(lead[key]);
      finalLead[key] = value;
    }

    var pageShortTitle = inferProductServiceFromPage();

    // Keep these empty by default so plugin global context can inject:
    // - Landing Page (first session URL from options)
    // - UTM polja (last attributed UTM if available)
    if (!finalLead['Landing Page']) {
      finalLead['Landing Page'] = '';
    }
    if (!finalLead['UTM polja']) {
      finalLead['UTM polja'] = '';
    }

    // Page Title should always have a short page title fallback.
    if (!finalLead['Page Title']) {
      finalLead['Page Title'] = pageShortTitle || cleanupValue('#post_title') || document.title;
    }

    var interestMode = norm(interestSourceMode).toLowerCase();
    var pathInterest = inferInterestFromPath();
    var pathProductHr = inferCroatianProductByPath();
    var isMappedPath = !!pathInterest;

    // Always force Croatian Proizvod/Usluga value when current path is mapped.
    if (pathProductHr) {
      finalLead['Proizvod/Usluga'] = pathProductHr;
    } else if (!finalLead['Proizvod/Usluga'] && isMappedPath) {
      finalLead['Proizvod/Usluga'] = pathInterest;
    }

    if (interestMode === 'path') {
      if (pathInterest) {
        finalLead.Interes = pathInterest;
      }
      if (!finalLead['Proizvod/Usluga'] && isMappedPath && finalLead.Interes) {
        finalLead['Proizvod/Usluga'] = finalLead.Interes;
      }
    } else if (interestMode === 'form') {
      if (!finalLead.Interes && finalLead['Proizvod/Usluga']) {
        finalLead.Interes = finalLead['Proizvod/Usluga'];
      }
    } else {
      if (!finalLead.Interes && finalLead['Proizvod/Usluga']) {
        finalLead.Interes = finalLead['Proizvod/Usluga'];
      }
      if (!finalLead.Interes && pathInterest) {
        finalLead.Interes = pathInterest;
      }
      if (!finalLead['Proizvod/Usluga'] && isMappedPath && finalLead.Interes) {
        finalLead['Proizvod/Usluga'] = finalLead.Interes;
      }
    }

    if (!finalLead.Last_Name) {
      finalLead.Last_Name = 'Website Lead';
    }

    var rows = [];
    Object.keys(finalLead).forEach(function (key) {
      var value = String(finalLead[key] || '');
      if (value.trim() !== '') {
        rows.push({ k: key, v: value });
      }
    });

    var payload = {
      form_variant: formVariant,
      form_key: inferFormKey(),
      page_url: cleanupValue('#tracking_url') || window.location.href,
      page_title: pageShortTitle || cleanupValue('#post_title') || document.title,
      sub_source: '',
      rows: rows,
      lead: finalLead
    };

    var customSubSourceClean = cleanupValue(customSubSource);
    payload.sub_source = customSubSourceClean;

    if (dryRun) {
      var previewPayload = payload;
      if (window.EmerusZoho && typeof window.EmerusZoho.previewLead === 'function') {
        previewPayload = window.EmerusZoho.previewLead(payload, { pushDataLayer: true, eventType: 'success' });
      }
      console.log('Emerus payload preview (dry run)', {
        formId: cleanupValue('#form_id'),
        formLabel: cleanupValue('#form_label'),
        payload: previewPayload
      });
      return;
    }

    console.log('Emerus payload preview', {
      formId: cleanupValue('#form_id'),
      formLabel: cleanupValue('#form_label'),
      payload: payload
    });

    await window.EmerusZoho.sendLead(payload);
  } catch (error) {
    console.error('Zoho integration failed:', error);
  }
})();
JS;
    }

    private function ws_payload_json_template() {
        $template = [
            'form_variant' => 'product',
            'form_key'     => 'services_products_en',
            'sub_source'   => 'Footer newsletter',
            'page_url'     => 'https://example.com/industrijski-profili',
            'page_title'   => 'Industrijski profili - Emerus',
            'rows'         => [
                ['k' => 'Last_Name', 'v' => 'Test Korisnik'],
                ['k' => 'First_Name', 'v' => 'Test'],
                ['k' => 'Email', 'v' => 'test@example.com'],
                ['k' => 'Phone', 'v' => '+38599111222'],
                ['k' => 'Description', 'v' => 'Opis upita.'],
                ['k' => 'Interes', 'v' => 'Industrijski profili'],
                ['k' => 'Landing Page', 'v' => 'https://example.com/industrijski-profili'],
                ['k' => 'Page URL', 'v' => 'https://example.com/industrijski-profili/prozor-sustav/'],
                ['k' => 'Page Title', 'v' => 'Industrijski profili - Emerus'],
                ['k' => 'UTM polja', 'v' => 'utm_source=google&utm_medium=cpc'],
                ['k' => 'Proizvod/Usluga', 'v' => 'Industrijski profili'],
            ],
            'lead'         => [
                'Last_Name'        => 'Test Korisnik',
                'First_Name'       => 'Test',
                'Email'            => 'test@example.com',
                'Phone'            => '+38599111222',
                'Description'      => 'Opis upita.',
                'Interes'          => 'Industrijski profili',
                'Landing Page'     => 'https://example.com/industrijski-profili',
                'Page URL'         => 'https://example.com/industrijski-profili/prozor-sustav/',
                'Page Title'       => 'Industrijski profili - Emerus',
                'UTM polja'        => 'utm_source=google&utm_medium=cpc',
                'Proizvod/Usluga'  => 'Industrijski profili',
            ],
        ];

        return (string) wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function defaults() {
        return [
            'hero_form_id'               => '',
            'product_form_id'            => '',
            'hero_show_everywhere'       => 1,
            'hero_pages'                 => [],
            'hero_rules'                 => '',
            'product_pages'              => [],
            'product_rules'              => '',
            'product_replaces_hero'      => 1,
            'load_helpers_globally'      => 1,
            'hero_title_hr'              => 'Zanima vas suradnja s Emerusom? Pošaljite nam upit.',
            'hero_title_en'              => 'Interested in working with Emerus? Send us your inquiry.',
            'hero_subtitle_hr'           => 'Brzi upit za najkraći put do ponude.',
            'hero_subtitle_en'           => 'Quick inquiry for the fastest path to an offer.',
            'product_title_hr'           => 'Zatražite ponudu za ovaj proizvod ili uslugu.',
            'product_title_en'           => 'Request an offer for this product or service.',
            'product_subtitle_hr'        => 'Pošaljite detalje projekta i javit ćemo se brzo.',
            'product_subtitle_en'        => 'Share project details and we will get back quickly.',
            'hero_page_overrides'        => [],
            'product_page_overrides'     => [],
            'product_page_texts'         => "", // page_id_or_slug|hr title|en title
            'ws_default_rules'           => '',
            'ws_i18n_enabled'            => 0,
            'ws_i18n_rules'              => '',
            'privacy_compliance_enabled' => 1,
            'privacy_prefix_hr'          => 'Slažem se s ',
            'privacy_prefix_en'          => 'I agree to the ',
            'privacy_link_text_hr'       => 'Politikom privatnosti',
            'privacy_link_text_en'       => 'Privacy Policy',
            'privacy_url_hr'             => '',
            'privacy_url_en'             => '',
            'ws_custom_js_enabled'       => 0,
            'ws_custom_js'               => $this->default_custom_js_template(),
            'overlay_max_width'          => 420,
            'global_context_enabled'     => 1,
            'context_landing_field_api'  => 'Landing Page',
            'context_page_title_field_api' => 'Page Title',
            'context_utm_field_api'      => 'UTM polja',
            'context_utm_keys'           => 'utm_source,utm_medium,utm_campaign,utm_content,utm_term',
            'context_use_first_session'  => 1,
            'datalayer_enabled'          => 1,
            'datalayer_object_name'      => 'dataLayer',
            'datalayer_event_success'    => 'emerus_zoho_submit_success',
            'datalayer_event_error'      => 'emerus_zoho_submit_error',
            'datalayer_include_payload'  => 1,
            'zoho_enabled'               => 0,
            'zoho_client_id'             => '',
            'zoho_client_secret'         => '',
            'zoho_refresh_token'         => '',
            'zoho_accounts_base'         => 'https://accounts.zoho.eu',
            'zoho_api_base'              => 'https://www.zohoapis.eu',
            'zoho_module'                => 'Leads',
            'zoho_timeout'               => 30,
            'zoho_lead_source'           => 'Website',
            'zoho_sub_source_hero'       => 'Hero forma',
            'zoho_sub_source_product'    => 'Ponuda - proizvod',
            'zoho_sub_source_map'        => [],
            'zoho_source_field_api'      => 'Lead_Source',
            'zoho_sub_source_field_api'  => '',
            'zoho_page_url_field_api'    => '',
            'zoho_page_title_field_api'  => '',
        ];
    }

    private function get_options() {
        return wp_parse_args((array) get_option(self::OPTION_KEY, []), $this->defaults());
    }

    private function sanitize_options(array $raw) {
        $defaults = $this->defaults();

        $options = [
            'hero_form_id'               => isset($raw['hero_form_id']) ? sanitize_text_field($raw['hero_form_id']) : $defaults['hero_form_id'],
            'product_form_id'            => isset($raw['product_form_id']) ? sanitize_text_field($raw['product_form_id']) : $defaults['product_form_id'],
            'hero_show_everywhere'       => !empty($raw['hero_show_everywhere']) ? 1 : 0,
            'hero_pages'                 => $this->sanitize_int_array(isset($raw['hero_pages']) ? (array) $raw['hero_pages'] : []),
            'hero_rules'                 => isset($raw['hero_rules']) ? sanitize_textarea_field($raw['hero_rules']) : $defaults['hero_rules'],
            'product_pages'              => $this->sanitize_int_array(isset($raw['product_pages']) ? (array) $raw['product_pages'] : []),
            'product_rules'              => isset($raw['product_rules']) ? sanitize_textarea_field($raw['product_rules']) : $defaults['product_rules'],
            'product_replaces_hero'      => !empty($raw['product_replaces_hero']) ? 1 : 0,
            'load_helpers_globally'      => !empty($raw['load_helpers_globally']) ? 1 : 0,
            'hero_title_hr'              => isset($raw['hero_title_hr']) ? sanitize_text_field($raw['hero_title_hr']) : $defaults['hero_title_hr'],
            'hero_title_en'              => isset($raw['hero_title_en']) ? sanitize_text_field($raw['hero_title_en']) : $defaults['hero_title_en'],
            'hero_subtitle_hr'           => isset($raw['hero_subtitle_hr']) ? sanitize_text_field($raw['hero_subtitle_hr']) : $defaults['hero_subtitle_hr'],
            'hero_subtitle_en'           => isset($raw['hero_subtitle_en']) ? sanitize_text_field($raw['hero_subtitle_en']) : $defaults['hero_subtitle_en'],
            'product_title_hr'           => isset($raw['product_title_hr']) ? sanitize_text_field($raw['product_title_hr']) : $defaults['product_title_hr'],
            'product_title_en'           => isset($raw['product_title_en']) ? sanitize_text_field($raw['product_title_en']) : $defaults['product_title_en'],
            'product_subtitle_hr'        => isset($raw['product_subtitle_hr']) ? sanitize_text_field($raw['product_subtitle_hr']) : $defaults['product_subtitle_hr'],
            'product_subtitle_en'        => isset($raw['product_subtitle_en']) ? sanitize_text_field($raw['product_subtitle_en']) : $defaults['product_subtitle_en'],
            'hero_page_overrides'        => $this->sanitize_page_overrides(isset($raw['hero_page_overrides']) ? (array) $raw['hero_page_overrides'] : []),
            'product_page_overrides'     => $this->sanitize_page_overrides(isset($raw['product_page_overrides']) ? (array) $raw['product_page_overrides'] : []),
            'product_page_texts'         => isset($raw['product_page_texts']) ? sanitize_textarea_field($raw['product_page_texts']) : $defaults['product_page_texts'],
            'ws_default_rules'           => isset($raw['ws_default_rules']) ? sanitize_textarea_field($raw['ws_default_rules']) : $defaults['ws_default_rules'],
            'ws_i18n_enabled'            => !empty($raw['ws_i18n_enabled']) ? 1 : 0,
            'ws_i18n_rules'              => isset($raw['ws_i18n_rules']) ? sanitize_textarea_field($raw['ws_i18n_rules']) : $defaults['ws_i18n_rules'],
            'privacy_compliance_enabled' => !empty($raw['privacy_compliance_enabled']) ? 1 : 0,
            'privacy_prefix_hr'          => isset($raw['privacy_prefix_hr']) ? sanitize_text_field($raw['privacy_prefix_hr']) : $defaults['privacy_prefix_hr'],
            'privacy_prefix_en'          => isset($raw['privacy_prefix_en']) ? sanitize_text_field($raw['privacy_prefix_en']) : $defaults['privacy_prefix_en'],
            'privacy_link_text_hr'       => isset($raw['privacy_link_text_hr']) ? sanitize_text_field($raw['privacy_link_text_hr']) : $defaults['privacy_link_text_hr'],
            'privacy_link_text_en'       => isset($raw['privacy_link_text_en']) ? sanitize_text_field($raw['privacy_link_text_en']) : $defaults['privacy_link_text_en'],
            'privacy_url_hr'             => isset($raw['privacy_url_hr']) ? esc_url_raw(trim((string) $raw['privacy_url_hr'])) : $defaults['privacy_url_hr'],
            'privacy_url_en'             => isset($raw['privacy_url_en']) ? esc_url_raw(trim((string) $raw['privacy_url_en'])) : $defaults['privacy_url_en'],
            'ws_custom_js_enabled'       => !empty($raw['ws_custom_js_enabled']) ? 1 : 0,
            'ws_custom_js'               => isset($raw['ws_custom_js']) ? $this->sanitize_custom_js($raw['ws_custom_js']) : $defaults['ws_custom_js'],
            'overlay_max_width'          => isset($raw['overlay_max_width']) ? max(280, min(640, absint($raw['overlay_max_width']))) : $defaults['overlay_max_width'],
            'global_context_enabled'     => !empty($raw['global_context_enabled']) ? 1 : 0,
            'context_landing_field_api'  => isset($raw['context_landing_field_api']) ? sanitize_text_field($raw['context_landing_field_api']) : $defaults['context_landing_field_api'],
            'context_page_title_field_api' => isset($raw['context_page_title_field_api']) ? sanitize_text_field($raw['context_page_title_field_api']) : $defaults['context_page_title_field_api'],
            'context_utm_field_api'      => isset($raw['context_utm_field_api']) ? sanitize_text_field($raw['context_utm_field_api']) : $defaults['context_utm_field_api'],
            'context_utm_keys'           => isset($raw['context_utm_keys']) ? sanitize_text_field($raw['context_utm_keys']) : $defaults['context_utm_keys'],
            'context_use_first_session'  => !empty($raw['context_use_first_session']) ? 1 : 0,
            'datalayer_enabled'          => !empty($raw['datalayer_enabled']) ? 1 : 0,
            'datalayer_object_name'      => isset($raw['datalayer_object_name']) ? $this->sanitize_datalayer_name($raw['datalayer_object_name']) : $defaults['datalayer_object_name'],
            'datalayer_event_success'    => isset($raw['datalayer_event_success']) ? sanitize_key($raw['datalayer_event_success']) : $defaults['datalayer_event_success'],
            'datalayer_event_error'      => isset($raw['datalayer_event_error']) ? sanitize_key($raw['datalayer_event_error']) : $defaults['datalayer_event_error'],
            'datalayer_include_payload'  => !empty($raw['datalayer_include_payload']) ? 1 : 0,
            'zoho_enabled'               => !empty($raw['zoho_enabled']) ? 1 : 0,
            'zoho_client_id'             => isset($raw['zoho_client_id']) ? sanitize_text_field($raw['zoho_client_id']) : $defaults['zoho_client_id'],
            'zoho_client_secret'         => isset($raw['zoho_client_secret']) ? sanitize_text_field($raw['zoho_client_secret']) : $defaults['zoho_client_secret'],
            'zoho_refresh_token'         => isset($raw['zoho_refresh_token']) ? sanitize_text_field($raw['zoho_refresh_token']) : $defaults['zoho_refresh_token'],
            'zoho_accounts_base'         => isset($raw['zoho_accounts_base']) ? esc_url_raw(trim($raw['zoho_accounts_base'])) : $defaults['zoho_accounts_base'],
            'zoho_api_base'              => isset($raw['zoho_api_base']) ? esc_url_raw(trim($raw['zoho_api_base'])) : $defaults['zoho_api_base'],
            'zoho_module'                => isset($raw['zoho_module']) ? sanitize_text_field($raw['zoho_module']) : $defaults['zoho_module'],
            'zoho_timeout'               => isset($raw['zoho_timeout']) ? max(5, min(120, absint($raw['zoho_timeout']))) : $defaults['zoho_timeout'],
            'zoho_lead_source'           => isset($raw['zoho_lead_source']) ? sanitize_text_field($raw['zoho_lead_source']) : $defaults['zoho_lead_source'],
            'zoho_sub_source_hero'       => isset($raw['zoho_sub_source_hero']) ? sanitize_text_field($raw['zoho_sub_source_hero']) : $defaults['zoho_sub_source_hero'],
            'zoho_sub_source_product'    => isset($raw['zoho_sub_source_product']) ? sanitize_text_field($raw['zoho_sub_source_product']) : $defaults['zoho_sub_source_product'],
            'zoho_sub_source_map'        => $this->sanitize_key_value_rows(isset($raw['zoho_sub_source_map']) ? (array) $raw['zoho_sub_source_map'] : []),
            'zoho_source_field_api'      => isset($raw['zoho_source_field_api']) ? sanitize_text_field($raw['zoho_source_field_api']) : $defaults['zoho_source_field_api'],
            'zoho_sub_source_field_api'  => isset($raw['zoho_sub_source_field_api']) ? sanitize_text_field($raw['zoho_sub_source_field_api']) : $defaults['zoho_sub_source_field_api'],
            'zoho_page_url_field_api'    => isset($raw['zoho_page_url_field_api']) ? sanitize_text_field($raw['zoho_page_url_field_api']) : $defaults['zoho_page_url_field_api'],
            'zoho_page_title_field_api'  => isset($raw['zoho_page_title_field_api']) ? sanitize_text_field($raw['zoho_page_title_field_api']) : $defaults['zoho_page_title_field_api'],
        ];

        return $options;
    }

    private function sanitize_custom_js($value) {
        if (!current_user_can('unfiltered_html')) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        return trim($value);
    }

    private function sanitize_datalayer_name($value) {
        $value = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $value);
        if ($value === null || $value === '') {
            return 'dataLayer';
        }
        return $value;
    }

    private function sanitize_int_array(array $values) {
        $values = array_filter(array_map('absint', $values));
        return array_values(array_unique($values));
    }

    private function sanitize_page_overrides(array $rows) {
        $clean = [];

        foreach ($rows as $page_id => $row) {
            $page_id = absint($page_id);
            if (!$page_id || !is_array($row)) {
                continue;
            }

            $item = [
                'title_hr'    => isset($row['title_hr']) ? sanitize_text_field($row['title_hr']) : '',
                'title_en'    => isset($row['title_en']) ? sanitize_text_field($row['title_en']) : '',
                'subtitle_hr' => isset($row['subtitle_hr']) ? sanitize_text_field($row['subtitle_hr']) : '',
                'subtitle_en' => isset($row['subtitle_en']) ? sanitize_text_field($row['subtitle_en']) : '',
            ];

            $has_value = false;
            foreach ($item as $value) {
                if ($value !== '') {
                    $has_value = true;
                    break;
                }
            }

            if (!$has_value) {
                continue;
            }

            $clean[(string) $page_id] = $item;
        }

        return $clean;
    }

    private function render_page_overrides_table(array $pages, $option_field, array $values) {
        ?>
        <div style="max-height: 360px; overflow: auto; border: 1px solid #dcdcde;">
            <table class="widefat striped" style="margin: 0;">
                <thead>
                    <tr>
                        <th style="width: 230px;">Page</th>
                        <th>Title HR</th>
                        <th>Title EN</th>
                        <th>Subtitle HR</th>
                        <th>Subtitle EN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages as $page) : ?>
                        <?php
                        $page_id = (int) $page->ID;
                        $row     = isset($values[(string) $page_id]) && is_array($values[(string) $page_id]) ? $values[(string) $page_id] : [];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($page->post_title ? $page->post_title : sprintf('#%d', $page_id)); ?></strong><br />
                                <code>ID: <?php echo $page_id; ?></code> · <code><?php echo esc_html((string) $page->post_name); ?></code>
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_field); ?>][<?php echo $page_id; ?>][title_hr]" value="<?php echo esc_attr(isset($row['title_hr']) ? (string) $row['title_hr'] : ''); ?>" />
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_field); ?>][<?php echo $page_id; ?>][title_en]" value="<?php echo esc_attr(isset($row['title_en']) ? (string) $row['title_en'] : ''); ?>" />
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_field); ?>][<?php echo $page_id; ?>][subtitle_hr]" value="<?php echo esc_attr(isset($row['subtitle_hr']) ? (string) $row['subtitle_hr'] : ''); ?>" />
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_field); ?>][<?php echo $page_id; ?>][subtitle_en]" value="<?php echo esc_attr(isset($row['subtitle_en']) ? (string) $row['subtitle_en'] : ''); ?>" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function sanitize_key_value_rows(array $rows) {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $match = isset($row['match']) ? sanitize_text_field((string) $row['match']) : '';
            $value = isset($row['value']) ? sanitize_text_field((string) $row['value']) : '';
            if ($match === '' || $value === '') {
                continue;
            }

            $clean[] = [
                'match' => $match,
                'value' => $value,
            ];
        }

        return $clean;
    }

    private function render_key_value_rows_table($option_field, array $rows, $columns = ['Match', 'Value']) {
        $rows_count = max(8, count($rows) + 2);
        ?>
        <div style="max-width: 980px;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width: 40%;"><?php echo esc_html($columns[0]); ?></th>
                        <th><?php echo esc_html($columns[1]); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < $rows_count; $i++) : ?>
                        <?php
                        $row = isset($rows[$i]) && is_array($rows[$i]) ? $rows[$i] : ['match' => '', 'value' => ''];
                        ?>
                        <tr>
                            <td>
                                <input type="text" class="regular-text code" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_field); ?>][<?php echo (int) $i; ?>][match]" value="<?php echo esc_attr((string) $row['match']); ?>" />
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_field); ?>][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr((string) $row['value']); ?>" />
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options();
        $pages   = get_pages([
            'sort_column' => 'menu_order,post_title',
            'post_status' => ['publish', 'private'],
        ]);

        settings_errors('emerus_wsforms_overlay_messages');
        ?>
        <div class="wrap">
            <h1>Emerus WS Forms Overlay</h1>
            <form method="post">
                <?php wp_nonce_field('emerus_wsforms_overlay_save_action', 'emerus_wsforms_overlay_nonce'); ?>
                <input type="hidden" name="emerus_wsforms_overlay_save" value="1" />

                <h2><?php esc_html_e('Form Setup', 'emerus-wsforms-overlay'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hero_form_id">Hero "Brzi upit" WS Form ID</label></th>
                        <td>
                            <input type="text" id="hero_form_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_form_id]" value="<?php echo esc_attr($options['hero_form_id']); ?>" class="regular-text" />
                            <p class="description">Example: <code>123</code> (rendered as <code>[ws_form id="123"]</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_form_id">Product inquiry WS Form ID</label></th>
                        <td>
                            <input type="text" id="product_form_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_form_id]" value="<?php echo esc_attr($options['product_form_id']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Behavior</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_replaces_hero]" value="1" <?php checked((int) $options['product_replaces_hero'], 1); ?> />
                                On product-targeted pages, show product form instead of hero form.
                            </label>
                            <br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[load_helpers_globally]" value="1" <?php checked((int) $options['load_helpers_globally'], 1); ?> />
                                Load JS integration helpers on all frontend pages (needed for WS forms already on page).
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>Page Targeting</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Hero targeting</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_show_everywhere]" value="1" <?php checked((int) $options['hero_show_everywhere'], 1); ?> />
                                Show hero form on all frontend pages.
                            </label>
                            <p class="description">If unchecked, hero shows only on selected pages or URL regex rules.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hero_pages">Hero pages</label></th>
                        <td>
                            <select id="hero_pages" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_pages][]" multiple style="min-width: 360px; min-height: 180px;">
                                <?php foreach ($pages as $page) : ?>
                                    <option value="<?php echo (int) $page->ID; ?>" <?php selected(in_array((int) $page->ID, $options['hero_pages'], true)); ?>>
                                        <?php echo esc_html($page->post_title ? $page->post_title : sprintf('#%d', (int) $page->ID)); ?> (ID: <?php echo (int) $page->ID; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hero_rules">Hero URL regex rules</label></th>
                        <td>
                            <textarea id="hero_rules" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_rules]" rows="4" class="large-text code"><?php echo esc_textarea($options['hero_rules']); ?></textarea>
                            <p class="description">One regex per line, matched against current path. Example: <code>#^/solarni-sustavi/#</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_pages">Product pages</label></th>
                        <td>
                            <select id="product_pages" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_pages][]" multiple style="min-width: 360px; min-height: 180px;">
                                <?php foreach ($pages as $page) : ?>
                                    <option value="<?php echo (int) $page->ID; ?>" <?php selected(in_array((int) $page->ID, $options['product_pages'], true)); ?>>
                                        <?php echo esc_html($page->post_title ? $page->post_title : sprintf('#%d', (int) $page->ID)); ?> (ID: <?php echo (int) $page->ID; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Pages where product inquiry variant should appear.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_rules">Product URL regex rules</label></th>
                        <td>
                            <textarea id="product_rules" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_rules]" rows="4" class="large-text code"><?php echo esc_textarea($options['product_rules']); ?></textarea>
                            <p class="description">One regex per line. If it matches, product variant is targeted.</p>
                        </td>
                    </tr>
                </table>

                <h2>Text (Croatian / English)</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hero_title_hr">Hero title (HR)</label></th>
                        <td><input type="text" id="hero_title_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_title_hr]" value="<?php echo esc_attr($options['hero_title_hr']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hero_title_en">Hero title (EN)</label></th>
                        <td><input type="text" id="hero_title_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_title_en]" value="<?php echo esc_attr($options['hero_title_en']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hero_subtitle_hr">Hero subtitle (HR)</label></th>
                        <td>
                            <input type="text" id="hero_subtitle_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_subtitle_hr]" value="<?php echo esc_attr($options['hero_subtitle_hr']); ?>" class="large-text" />
                            <p class="description">Type <code>hide</code> to hide subtitle globally.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hero_subtitle_en">Hero subtitle (EN)</label></th>
                        <td>
                            <input type="text" id="hero_subtitle_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_subtitle_en]" value="<?php echo esc_attr($options['hero_subtitle_en']); ?>" class="large-text" />
                            <p class="description">Type <code>hide</code> to hide subtitle globally.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_title_hr">Product title (HR)</label></th>
                        <td><input type="text" id="product_title_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_title_hr]" value="<?php echo esc_attr($options['product_title_hr']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_title_en">Product title (EN)</label></th>
                        <td><input type="text" id="product_title_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_title_en]" value="<?php echo esc_attr($options['product_title_en']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_subtitle_hr">Product subtitle (HR)</label></th>
                        <td>
                            <input type="text" id="product_subtitle_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_subtitle_hr]" value="<?php echo esc_attr($options['product_subtitle_hr']); ?>" class="large-text" />
                            <p class="description">Type <code>hide</code> to hide subtitle globally.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_subtitle_en">Product subtitle (EN)</label></th>
                        <td>
                            <input type="text" id="product_subtitle_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_subtitle_en]" value="<?php echo esc_attr($options['product_subtitle_en']); ?>" class="large-text" />
                            <p class="description">Type <code>hide</code> to hide subtitle globally.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hero per-page title/subtitle</th>
                        <td>
                            <p class="description">Leave fields empty to use global defaults. Use <code>hide</code> in subtitle to hide subtitle on that page.</p>
                            <?php $this->render_page_overrides_table($pages, 'hero_page_overrides', isset($options['hero_page_overrides']) ? (array) $options['hero_page_overrides'] : []); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Product per-page title/subtitle</th>
                        <td>
                            <p class="description">Leave fields empty to use global defaults. Use <code>hide</code> in subtitle to hide subtitle on that page.</p>
                            <?php $this->render_page_overrides_table($pages, 'product_page_overrides', isset($options['product_page_overrides']) ? (array) $options['product_page_overrides'] : []); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_page_texts">Legacy product title rules</label></th>
                        <td>
                            <textarea id="product_page_texts" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_page_texts]" rows="4" class="large-text code"><?php echo esc_textarea($options['product_page_texts']); ?></textarea>
                            <p class="description">Optional legacy fallback format: <code>page_id_or_slug|Croatian title|English title</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="overlay_max_width">Overlay max width (px)</label></th>
                        <td><input type="number" id="overlay_max_width" min="280" max="640" name="<?php echo esc_attr(self::OPTION_KEY); ?>[overlay_max_width]" value="<?php echo (int) $options['overlay_max_width']; ?>" /></td>
                    </tr>
                </table>

                <h2>WS Form Defaults / JS</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ws_default_rules">Default field rules by page</label></th>
                        <td>
                            <textarea id="ws_default_rules" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ws_default_rules]" rows="7" class="large-text code"><?php echo esc_textarea($options['ws_default_rules']); ?></textarea>
                            <p class="description">One rule per line: <code>page_refs|field_name|value|variant</code></p>
                            <p class="description">Examples: <code>industrijski-profili,solar|Interes|Industrijski profili|product</code> or <code>*|Lead_Type|Website|both</code></p>
                            <p class="description"><code>page_refs</code> accepts page IDs and/or slugs separated by comma. Supports parent match with prefix <code>parent:</code> (example: <code>parent:standardni-alu-profili|Interes|Industrijski profili|product</code>). <code>variant</code> is <code>hero</code>, <code>product</code>, or <code>both</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws_i18n_rules">WS #text translation rules</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ws_i18n_enabled]" value="1" <?php checked((int) $options['ws_i18n_enabled'], 1); ?> />
                                Enable HR/EN translation injection for WS hidden text fields.
                            </label>
                            <br /><br />
                            <textarea id="ws_i18n_rules" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ws_i18n_rules]" rows="7" class="large-text code"><?php echo esc_textarea($options['ws_i18n_rules']); ?></textarea>
                            <p class="description">One rule per line: <code>match_key_or_text|hr_value</code> or <code>match_key_or_text|hr_value|en_value</code></p>
                            <p class="description">Examples: <code>Full name|Puno ime</code>, <code>Phone number|Telefon</code>, or <code>i18n_full_name_label|Puno ime|Full name</code></p>
                            <p class="description">Simple mode (no hidden source fields): write token directly in WS text (Label/Placeholder/Help/Button), e.g. <code>i18n_full_name_label</code>, <code>[[i18n_full_name_label]]</code>, or <code>{{i18n_full_name_label}}</code>. Plugin auto-replaces by current language.</p>
                            <p class="description">ENG-as-key mode: put exact English text as first column (e.g. <code>Full name</code>) and plugin will auto-replace to HR only when needed.</p>
                            <p class="description">In WS Form you can also use official variable in Label/Placeholder/Help Text: <code>#text(#field(123))</code>, where 123 is hidden source field ID and that hidden field Name matches first column key.</p>
                            <p class="description">Reference: <a href="https://wsform.com/knowledgebase/dynamic-label-placeholder-and-help-text-with-text/" target="_blank" rel="noopener noreferrer">Dynamic Label, Placeholder and Help Text With #text</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Privacy compliance text</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_compliance_enabled]" value="1" <?php checked((int) $options['privacy_compliance_enabled'], 1); ?> />
                                Auto-adjust WS GDPR checkbox text and privacy link by language.
                            </label>
                            <p class="description">Targets labels containing privacy-policy links (checkbox consent text).</p>
                            <table style="margin-top: 8px;">
                                <tr>
                                    <td style="padding-right:10px;"><label for="privacy_prefix_hr">Prefix HR</label></td>
                                    <td><input type="text" id="privacy_prefix_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_prefix_hr]" value="<?php echo esc_attr($options['privacy_prefix_hr']); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <td style="padding-right:10px;"><label for="privacy_prefix_en">Prefix EN</label></td>
                                    <td><input type="text" id="privacy_prefix_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_prefix_en]" value="<?php echo esc_attr($options['privacy_prefix_en']); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <td style="padding-right:10px;"><label for="privacy_link_text_hr">Link text HR</label></td>
                                    <td><input type="text" id="privacy_link_text_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_link_text_hr]" value="<?php echo esc_attr($options['privacy_link_text_hr']); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <td style="padding-right:10px;"><label for="privacy_link_text_en">Link text EN</label></td>
                                    <td><input type="text" id="privacy_link_text_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_link_text_en]" value="<?php echo esc_attr($options['privacy_link_text_en']); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <td style="padding-right:10px;"><label for="privacy_url_hr">Privacy URL HR</label></td>
                                    <td><input type="url" id="privacy_url_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_url_hr]" value="<?php echo esc_attr($options['privacy_url_hr']); ?>" class="regular-text code" /></td>
                                </tr>
                                <tr>
                                    <td style="padding-right:10px;"><label for="privacy_url_en">Privacy URL EN</label></td>
                                    <td><input type="url" id="privacy_url_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_url_en]" value="<?php echo esc_attr($options['privacy_url_en']); ?>" class="regular-text code" /></td>
                                </tr>
                            </table>
                            <p class="description">If URL is empty, plugin uses WordPress Privacy Policy URL fallback.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws_custom_js">Custom JS hook (optional)</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ws_custom_js_enabled]" value="1" <?php checked((int) $options['ws_custom_js_enabled'], 1); ?> />
                                Enable custom JS hook execution.
                            </label>
                            <br /><br />
                            <textarea id="ws_custom_js" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ws_custom_js]" rows="10" class="large-text code"><?php echo esc_textarea($options['ws_custom_js']); ?></textarea>
                            <p class="description">Runs after plugin JS only when enabled. You can listen to event <code>emerus-ws-defaults-applied</code> and adjust form behavior. This is separate from the long read-only <code>WS submit JS template</code> shown below.</p>
                        </td>
                    </tr>
                </table>

                <h2>Global Metadata Injection</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable global metadata</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[global_context_enabled]" value="1" <?php checked((int) $options['global_context_enabled'], 1); ?> />
                                Inject landing URL, page title and UTM data into Zoho payload globally.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="context_landing_field_api">Landing URL field API</label></th>
                        <td><input type="text" id="context_landing_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[context_landing_field_api]" value="<?php echo esc_attr($options['context_landing_field_api']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="context_page_title_field_api">Page title field API</label></th>
                        <td><input type="text" id="context_page_title_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[context_page_title_field_api]" value="<?php echo esc_attr($options['context_page_title_field_api']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="context_utm_field_api">UTM field API</label></th>
                        <td><input type="text" id="context_utm_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[context_utm_field_api]" value="<?php echo esc_attr($options['context_utm_field_api']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="context_utm_keys">Tracked UTM/param keys</label></th>
                        <td>
                            <input type="text" id="context_utm_keys" name="<?php echo esc_attr(self::OPTION_KEY); ?>[context_utm_keys]" value="<?php echo esc_attr($options['context_utm_keys']); ?>" class="large-text code" />
                            <p class="description">Comma separated. Classic UTM only. Example: <code>utm_source,utm_medium,utm_campaign,utm_content,utm_term</code>. If only <code>gclid</code> or <code>fbclid</code> exists, plugin sets <code>utm_source=google</code> or <code>utm_source=fb</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Landing URL source</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[context_use_first_session]" value="1" <?php checked((int) $options['context_use_first_session'], 1); ?> />
                                Use first session URL (recommended).
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>GTM / Data Layer</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable Data Layer push</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[datalayer_enabled]" value="1" <?php checked((int) $options['datalayer_enabled'], 1); ?> />
                                Push submission events to Data Layer for GTM.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="datalayer_object_name">Data Layer object name</label></th>
                        <td>
                            <input type="text" id="datalayer_object_name" name="<?php echo esc_attr(self::OPTION_KEY); ?>[datalayer_object_name]" value="<?php echo esc_attr($options['datalayer_object_name']); ?>" class="regular-text code" />
                            <p class="description">Usually <code>dataLayer</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="datalayer_event_success">Success event name</label></th>
                        <td><input type="text" id="datalayer_event_success" name="<?php echo esc_attr(self::OPTION_KEY); ?>[datalayer_event_success]" value="<?php echo esc_attr($options['datalayer_event_success']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="datalayer_event_error">Error event name</label></th>
                        <td><input type="text" id="datalayer_event_error" name="<?php echo esc_attr(self::OPTION_KEY); ?>[datalayer_event_error]" value="<?php echo esc_attr($options['datalayer_event_error']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Include payload in Data Layer</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[datalayer_include_payload]" value="1" <?php checked((int) $options['datalayer_include_payload'], 1); ?> />
                                Include lead / rows payload in event object.
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>Zoho CRM (optional, disabled by default)</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable Zoho sending</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_enabled]" value="1" <?php checked((int) $options['zoho_enabled'], 1); ?> />
                                Allow REST endpoint to send leads to Zoho CRM.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_client_id">Client ID</label></th>
                        <td><input type="text" id="zoho_client_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_client_id]" value="<?php echo esc_attr($options['zoho_client_id']); ?>" class="large-text code" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_client_secret">Client Secret</label></th>
                        <td><input type="password" id="zoho_client_secret" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_client_secret]" value="<?php echo esc_attr($options['zoho_client_secret']); ?>" class="large-text code" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_refresh_token">Refresh Token</label></th>
                        <td><input type="password" id="zoho_refresh_token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_refresh_token]" value="<?php echo esc_attr($options['zoho_refresh_token']); ?>" class="large-text code" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_accounts_base">Accounts base URL</label></th>
                        <td><input type="url" id="zoho_accounts_base" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_accounts_base]" value="<?php echo esc_attr($options['zoho_accounts_base']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_api_base">API base URL</label></th>
                        <td><input type="url" id="zoho_api_base" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_api_base]" value="<?php echo esc_attr($options['zoho_api_base']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_module">Zoho module</label></th>
                        <td><input type="text" id="zoho_module" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_module]" value="<?php echo esc_attr($options['zoho_module']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_timeout">Timeout (seconds)</label></th>
                        <td><input type="number" id="zoho_timeout" min="5" max="120" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_timeout]" value="<?php echo (int) $options['zoho_timeout']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_lead_source">Lead Source value</label></th>
                        <td><input type="text" id="zoho_lead_source" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_lead_source]" value="<?php echo esc_attr($options['zoho_lead_source']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_sub_source_hero">Sub-source (hero)</label></th>
                        <td><input type="text" id="zoho_sub_source_hero" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_sub_source_hero]" value="<?php echo esc_attr($options['zoho_sub_source_hero']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_sub_source_product">Sub-source (product)</label></th>
                        <td><input type="text" id="zoho_sub_source_product" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_sub_source_product]" value="<?php echo esc_attr($options['zoho_sub_source_product']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Sub-source mapping table</th>
                        <td>
                            <p class="description">Map form groups to a common sub-source value. Match supports: <code>form_key:services_en</code>, <code>variant:product</code>, or <code>*</code>.</p>
                            <?php $this->render_key_value_rows_table('zoho_sub_source_map', isset($options['zoho_sub_source_map']) ? (array) $options['zoho_sub_source_map'] : [], ['Match', 'Sub-source value']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_source_field_api">Lead Source API field</label></th>
                        <td>
                            <input type="text" id="zoho_source_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_source_field_api]" value="<?php echo esc_attr($options['zoho_source_field_api']); ?>" class="regular-text code" />
                            <p class="description">Standard Zoho field is typically <code>Lead_Source</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_sub_source_field_api">Sub-source API field</label></th>
                        <td>
                            <input type="text" id="zoho_sub_source_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_sub_source_field_api]" value="<?php echo esc_attr($options['zoho_sub_source_field_api']); ?>" class="regular-text code" />
                            <p class="description">Leave empty if you do not use a sub-source custom field. JS payload can override defaults by sending <code>sub_source</code> (or <code>lead_sub_source</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_page_url_field_api">Landing URL API field</label></th>
                        <td><input type="text" id="zoho_page_url_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_page_url_field_api]" value="<?php echo esc_attr($options['zoho_page_url_field_api']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_page_title_field_api">Page title API field</label></th>
                        <td><input type="text" id="zoho_page_title_field_api" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_page_title_field_api]" value="<?php echo esc_attr($options['zoho_page_title_field_api']); ?>" class="regular-text code" /></td>
                    </tr>
                </table>

                <h2>WS Form Integration Template</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Payload options</th>
                        <td>
                            <ul style="margin-top:0;">
                                <li><code>formVariant</code>: <code>hero</code> or <code>product</code></li>
                                <li><code>formKey</code>: key used for Sub-Source mapping table (recommended)</li>
                                <li><code>mode</code>: <code>rows</code>, <code>lead</code>, or <code>both</code></li>
                                <li><code>includeEmpty</code>: include empty values (<code>true</code>/<code>false</code>)</li>
                                <li><code>mapFields</code>: map form field names to Zoho API names</li>
                                <li><code>staticLead</code>: extra fixed lead data always appended</li>
                                <li><code>extraPayload</code>: extra top-level payload values</li>
                                <li><code>applyI18n</code>: apply plugin WS #text translation rules before collecting values (default <code>true</code>)</li>
                            </ul>
                            <p class="description"><code>Lead_Source</code> is filled automatically from plugin settings (typically <code>Website</code>).</p>
                            <p class="description">Global injection (from plugin options) can auto-add <code>Landing Page</code>, <code>Page Title</code>, and <code>UTM polja</code> without WS form fields.</p>
                            <p class="description">JSON sent to backend stays aligned with your tested format (rows and/or lead object).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws_integration_template">WS submit JS template</label></th>
                        <td>
                            <textarea id="ws_integration_template" readonly rows="24" class="large-text code"><?php echo esc_textarea($this->ws_integration_template()); ?></textarea>
                            <p class="description">Copy this into WS Form submit custom JS and adjust selector + field mapping.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws_payload_json_template">JSON payload example</label></th>
                        <td>
                            <textarea id="ws_payload_json_template" readonly rows="20" class="large-text code"><?php echo esc_textarea($this->ws_payload_json_template()); ?></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'emerus-wsforms-overlay')); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        if (is_admin()) {
            return;
        }

        $options = $this->get_options();
        $should_render_overlay = $this->should_render_on_request();
        $load_helpers_globally = (int) $options['load_helpers_globally'] === 1;

        if (!$should_render_overlay && !$load_helpers_globally) {
            return;
        }

        if ($should_render_overlay) {
            wp_enqueue_style(
                'emerus-wsforms-overlay',
                plugins_url('assets/css/frontend.css', __FILE__),
                [],
                '0.4.10'
            );
        }

        wp_enqueue_script(
            'emerus-wsforms-overlay',
            plugins_url('assets/js/frontend.js', __FILE__),
            [],
            '0.4.10',
            true
        );

        $page = get_post(get_queried_object_id());
        $parent_id = 0;
        $parent_slug = '';
        if ($page && !empty($page->post_parent)) {
            $parent_id = (int) $page->post_parent;
            $parent = get_post($parent_id);
            if ($parent) {
                $parent_slug = (string) $parent->post_name;
            }
        }

        wp_localize_script('emerus-wsforms-overlay', 'EmerusWsFormsOverlay', [
            'restUrl'        => esc_url_raw(rest_url('emerus-wsforms/v1/zoho-lead')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'maxWidth'       => (int) $options['overlay_max_width'],
            'currentPath'    => wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH),
            'lang'           => $this->current_lang_code(),
            'pageId'         => (int) get_queried_object_id(),
            'pageSlug'       => $page ? (string) $page->post_name : '',
            'parentPageId'   => $parent_id,
            'parentPageSlug' => $parent_slug,
            'wsDefaultRules' => $this->parse_ws_default_rules((string) $options['ws_default_rules']),
            'wsI18n'         => [
                'enabled' => (int) $options['ws_i18n_enabled'] === 1,
                'rules'   => $this->parse_ws_i18n_rules((string) $options['ws_i18n_rules']),
            ],
            'privacyCompliance' => [
                'enabled'      => (int) $options['privacy_compliance_enabled'] === 1,
                'prefixHr'     => (string) $options['privacy_prefix_hr'],
                'prefixEn'     => (string) $options['privacy_prefix_en'],
                'linkTextHr'   => (string) $options['privacy_link_text_hr'],
                'linkTextEn'   => (string) $options['privacy_link_text_en'],
                'urlHr'        => (string) $options['privacy_url_hr'],
                'urlEn'        => (string) $options['privacy_url_en'],
                'fallbackUrl'  => esc_url_raw(get_privacy_policy_url()),
            ],
            'globalContext'  => [
                'enabled'         => (int) $options['global_context_enabled'] === 1,
                'landingField'    => (string) $options['context_landing_field_api'],
                'pageTitleField'  => (string) $options['context_page_title_field_api'],
                'utmField'        => (string) $options['context_utm_field_api'],
                'utmKeys'         => $this->parse_csv_keys((string) $options['context_utm_keys']),
                'useFirstSession' => (int) $options['context_use_first_session'] === 1,
            ],
            'dataLayer'      => [
                'enabled'       => (int) $options['datalayer_enabled'] === 1,
                'objectName'    => (string) $options['datalayer_object_name'],
                'successEvent'  => (string) $options['datalayer_event_success'],
                'errorEvent'    => (string) $options['datalayer_event_error'],
                'includePayload'=> (int) $options['datalayer_include_payload'] === 1,
            ],
        ]);

        $custom_js_enabled = (int) $options['ws_custom_js_enabled'] === 1;
        $custom_js = trim((string) $options['ws_custom_js']);
        if ($custom_js_enabled && $custom_js !== '') {
            $wrapped_custom_js = 'try { (new Function(' . wp_json_encode($custom_js) . '))(); } catch (e) { console.error("Emerus custom JS error:", e); }';
            wp_add_inline_script('emerus-wsforms-overlay', $wrapped_custom_js, 'after');
        }
    }

    public function render_overlay() {
        if (is_admin() || !$this->should_render_on_request()) {
            return;
        }

        $options = $this->get_options();

        $product_target = $this->is_targeted_page('product', $options);
        $hero_target    = $this->is_targeted_page('hero', $options);

        $variant = 'hero';
        if ($product_target && !empty($options['product_form_id']) && (int) $options['product_replaces_hero'] === 1) {
            $variant = 'product';
        } elseif (!$hero_target && $product_target && !empty($options['product_form_id'])) {
            $variant = 'product';
        }

        if ($variant === 'hero' && empty($options['hero_form_id'])) {
            return;
        }

        if ($variant === 'product' && empty($options['product_form_id'])) {
            return;
        }

        $form_id = $variant === 'product' ? $options['product_form_id'] : $options['hero_form_id'];
        $copy    = $this->resolve_copy($variant, $options);
        $title   = (string) $copy['title'];
        $subtitle = (string) $copy['subtitle'];

        $form_markup = '';
        if (shortcode_exists('ws_form')) {
            $form_markup = do_shortcode('[ws_form id="' . esc_attr($form_id) . '"]');
        } elseif (current_user_can('manage_options')) {
            $form_markup = '<p style="color:#9b1c1c;">WS Form shortcode is not available. Activate WS Form plugin.</p>';
        }

        if (trim((string) $form_markup) === '' && current_user_can('manage_options')) {
            $form_markup = '<p style="color:#9b1c1c;">WS Form output is empty. Check WS Form ID and plugin activation.</p>';
        }

        if (trim((string) $form_markup) === '') {
            return;
        }

        ?>
        <div id="emerus-wsforms-overlay-root" class="emerus-wsforms-overlay-root" data-variant="<?php echo esc_attr($variant); ?>" data-max-width="<?php echo (int) $options['overlay_max_width']; ?>">
            <div class="emerus-wsforms-overlay" role="complementary" aria-label="Inquiry form overlay">
                <div class="emerus-wsforms-overlay__content">
                    <h3 class="emerus-wsforms-overlay__title"><?php echo esc_html($title); ?></h3>
                    <?php if ($subtitle !== '') : ?>
                        <p class="emerus-wsforms-overlay__subtitle"><?php echo esc_html($subtitle); ?></p>
                    <?php endif; ?>
                    <div class="emerus-wsforms-overlay__form" data-emerus-form-variant="<?php echo esc_attr($variant); ?>">
                        <?php echo $form_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function should_render_on_request() {
        if (is_404() || is_search() || is_admin()) {
            return false;
        }

        $options = $this->get_options();

        $hero_target    = $this->is_targeted_page('hero', $options);
        $product_target = $this->is_targeted_page('product', $options);

        if (!$hero_target && !$product_target) {
            return false;
        }

        if ($product_target && !empty($options['product_form_id'])) {
            return true;
        }

        if ($hero_target && !empty($options['hero_form_id'])) {
            return true;
        }

        return false;
    }

    private function is_targeted_page($variant, array $options) {
        $page_id = get_queried_object_id();

        if ($variant === 'hero') {
            if ((int) $options['hero_show_everywhere'] === 1) {
                return true;
            }

            $target_pages = (array) $options['hero_pages'];
            $rules        = (string) $options['hero_rules'];
        } else {
            $target_pages = (array) $options['product_pages'];
            $rules        = (string) $options['product_rules'];
        }

        if ($page_id && in_array((int) $page_id, array_map('intval', $target_pages), true)) {
            return true;
        }

        return $this->path_matches_rules($rules);
    }

    private function path_matches_rules($rules_text) {
        $path  = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '';
        $path  = is_string($path) ? $path : '';
        $rules = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $rules_text)));

        foreach ($rules as $rule) {
            set_error_handler(static function () {
                return true;
            });
            $matched = preg_match($rule, $path);
            restore_error_handler();
            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }

    private function resolve_copy($variant, array $options) {
        $lang          = $this->current_lang_code() === 'hr' ? 'hr' : 'en';
        $title_key     = 'title_' . $lang;
        $subtitle_key  = 'subtitle_' . $lang;
        $default_title = $variant === 'product' ? (string) $options['product_' . $title_key] : (string) $options['hero_' . $title_key];
        $default_sub   = $variant === 'product' ? (string) $options['product_' . $subtitle_key] : (string) $options['hero_' . $subtitle_key];

        $title    = $default_title;
        $subtitle = $default_sub;

        $override = $this->resolve_page_override_copy($variant, $options);
        if (!empty($override[$title_key])) {
            $title = (string) $override[$title_key];
        } elseif ($variant === 'product') {
            // Backward compatibility with old product title rules.
            $legacy = $this->resolve_legacy_product_page_title($lang, (string) $options['product_page_texts']);
            if ($legacy !== '') {
                $title = $legacy;
            }
        }

        if (array_key_exists($subtitle_key, $override) && (string) $override[$subtitle_key] !== '') {
            $subtitle = (string) $override[$subtitle_key];
        }

        if ($this->is_hide_marker($subtitle)) {
            $subtitle = '';
        }

        return [
            'title'    => $title,
            'subtitle' => $subtitle,
        ];
    }

    private function resolve_page_override_copy($variant, array $options) {
        $page_id = (int) get_queried_object_id();
        if (!$page_id) {
            return [];
        }

        $key       = $variant === 'product' ? 'product_page_overrides' : 'hero_page_overrides';
        $overrides = isset($options[$key]) && is_array($options[$key]) ? $options[$key] : [];

        if (!isset($overrides[(string) $page_id]) || !is_array($overrides[(string) $page_id])) {
            return [];
        }

        return $overrides[(string) $page_id];
    }

    private function resolve_legacy_product_page_title($lang, $rules) {
        $post = get_post(get_queried_object_id());
        if (!$post) {
            return '';
        }

        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $rules)));
        if (empty($lines)) {
            return '';
        }

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) {
                continue;
            }

            $key = $parts[0];
            if ((string) $post->ID !== $key && (string) $post->post_name !== $key) {
                continue;
            }

            return $lang === 'hr' ? $parts[1] : $parts[2];
        }

        return '';
    }

    private function is_hide_marker($value) {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['hide', '__hide__', '[hide]'], true);
    }

    private function parse_ws_default_rules($rules_text) {
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $rules_text)));
        $rules = [];

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) {
                continue;
            }

            $refs_raw = array_filter(array_map('trim', explode(',', (string) $parts[0])));
            $refs     = [];

            foreach ($refs_raw as $ref) {
                if ($ref === '*') {
                    $refs[] = '*';
                    continue;
                }

                if (stripos($ref, 'parent:') === 0) {
                    $parent_ref = trim(substr($ref, 7));
                    if ($parent_ref === '') {
                        continue;
                    }
                    if (ctype_digit($parent_ref)) {
                        $refs[] = 'parent:' . (string) absint($parent_ref);
                        continue;
                    }
                    $parent_slug = sanitize_title($parent_ref);
                    if ($parent_slug !== '') {
                        $refs[] = 'parent:' . $parent_slug;
                    }
                    continue;
                }

                if (ctype_digit($ref)) {
                    $refs[] = (string) absint($ref);
                    continue;
                }

                $slug = sanitize_title($ref);
                if ($slug !== '') {
                    $refs[] = $slug;
                }
            }

            $field = sanitize_text_field((string) $parts[1]);
            if ($field === '') {
                continue;
            }

            $variant = isset($parts[3]) ? sanitize_key((string) $parts[3]) : 'both';
            if (!in_array($variant, ['hero', 'product', 'both'], true)) {
                $variant = 'both';
            }

            if (empty($refs)) {
                $refs[] = '*';
            }

            $rules[] = [
                'refs'    => array_values(array_unique($refs)),
                'field'   => $field,
                'value'   => sanitize_text_field((string) $parts[2]),
                'variant' => $variant,
            ];
        }

        return $rules;
    }

    private function parse_ws_i18n_rules($rules_text) {
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $rules_text)));
        $rules = [];

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2) {
                continue;
            }

            $field = trim(sanitize_text_field((string) $parts[0]));
            if ($field === '') {
                continue;
            }

            $hr = sanitize_text_field((string) $parts[1]);
            $en = isset($parts[2]) ? sanitize_text_field((string) $parts[2]) : $field;
            if ($hr === '' && $en === '') {
                continue;
            }

            $rules[] = [
                'field' => $field,
                'hr'    => $hr,
                'en'    => $en,
            ];
        }

        return $rules;
    }

    private function parse_csv_keys($value) {
        $parts = array_filter(array_map('trim', explode(',', (string) $value)));
        $keys  = [];

        foreach ($parts as $part) {
            $part = strtolower(preg_replace('/[^a-z0-9_]/', '', $part));
            if ($part === '') {
                continue;
            }
            $keys[] = $part;
        }

        if (empty($keys)) {
            $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        }

        return array_values(array_unique($keys));
    }

    private function resolve_sub_source_value(array $payload, $variant, array $options) {
        $form_key = isset($payload['form_key']) ? strtolower(trim(sanitize_text_field((string) $payload['form_key']))) : '';
        $variant = strtolower(trim((string) $variant));
        $rules = isset($options['zoho_sub_source_map']) && is_array($options['zoho_sub_source_map']) ? $options['zoho_sub_source_map'] : [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $match = isset($rule['match']) ? strtolower(trim((string) $rule['match'])) : '';
            $value = isset($rule['value']) ? trim((string) $rule['value']) : '';
            if ($match === '' || $value === '') {
                continue;
            }

            if ($this->sub_source_rule_matches($match, $form_key, $variant)) {
                return $value;
            }
        }

        return $variant === 'product' ? (string) $options['zoho_sub_source_product'] : (string) $options['zoho_sub_source_hero'];
    }

    private function sub_source_rule_matches($match, $form_key, $variant) {
        $tokens = array_filter(array_map('trim', explode(',', (string) $match)));
        if (empty($tokens)) {
            return false;
        }

        foreach ($tokens as $token) {
            if ($token === '*') {
                return true;
            }

            if (strpos($token, 'variant:') === 0) {
                $v = trim(substr($token, 8));
                if ($v !== '' && $v === $variant) {
                    return true;
                }
                continue;
            }

            if (strpos($token, 'form_key:') === 0) {
                $key = trim(substr($token, 9));
                if ($key !== '' && $form_key !== '' && $key === $form_key) {
                    return true;
                }
                continue;
            }

            if ($form_key !== '' && $token === $form_key) {
                return true;
            }
        }

        return false;
    }

    private function current_lang_code() {
        if (function_exists('pll_current_language')) {
            $lang = (string) pll_current_language('slug');
            if ($lang !== '') {
                return strtolower(substr($lang, 0, 2));
            }
        }

        if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
            return strtolower(substr((string) ICL_LANGUAGE_CODE, 0, 2));
        }

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        return strtolower(substr((string) $locale, 0, 2));
    }

    public function register_rest_routes() {
        register_rest_route('emerus-wsforms/v1', '/zoho-lead', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_zoho_lead'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_zoho_lead(WP_REST_Request $request) {
        $options = $this->get_options();

        $nonce = $request->get_header('x_wp_nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('emerus_bad_nonce', 'Invalid REST nonce.', ['status' => 403]);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = $request->get_body_params();
        }

        $preview_raw = null;
        if (isset($payload['preview_only'])) {
            $preview_raw = $payload['preview_only'];
        } elseif (isset($payload['dry_run_backend'])) {
            $preview_raw = $payload['dry_run_backend'];
        }

        $preview_only = false;
        if (is_bool($preview_raw)) {
            $preview_only = $preview_raw;
        } elseif ($preview_raw !== null) {
            $preview_only = in_array(strtolower(trim((string) $preview_raw)), ['1', 'true', 'yes', 'on'], true);
        }

        if (!$preview_only && (int) $options['zoho_enabled'] !== 1) {
            return new WP_Error('emerus_zoho_disabled', 'Zoho lead sending is disabled in plugin settings.', ['status' => 403]);
        }

        $lead = [];
        if (!empty($payload['lead']) && is_array($payload['lead'])) {
            foreach ($payload['lead'] as $key => $value) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $lead[$key] = is_scalar($value) ? (string) $value : wp_json_encode($value);
            }
        } else {
            $lead = $this->build_lead_from_rows(isset($payload['rows']) ? (array) $payload['rows'] : []);
        }

        $variant = isset($payload['form_variant']) ? sanitize_key($payload['form_variant']) : 'hero';

        $source_field = trim((string) $options['zoho_source_field_api']);
        if ($source_field !== '' && empty($lead[$source_field])) {
            $lead[$source_field] = (string) $options['zoho_lead_source'];
        }

        $payload_sub_source = '';
        if (isset($payload['sub_source'])) {
            $payload_sub_source = sanitize_text_field((string) $payload['sub_source']);
        } elseif (isset($payload['lead_sub_source'])) {
            $payload_sub_source = sanitize_text_field((string) $payload['lead_sub_source']);
        }
        $resolved_sub_source = $payload_sub_source !== ''
            ? $payload_sub_source
            : $this->resolve_sub_source_value($payload, $variant, $options);

        $sub_source_field = trim((string) $options['zoho_sub_source_field_api']);
        if ($sub_source_field !== '') {
            if ($payload_sub_source !== '') {
                $lead[$sub_source_field] = $payload_sub_source;
            } elseif (empty($lead[$sub_source_field])) {
                $lead[$sub_source_field] = $resolved_sub_source;
            }
        }

        $page_url = isset($payload['page_url']) ? esc_url_raw($payload['page_url']) : '';
        if ($page_url !== '' && trim((string) $options['zoho_page_url_field_api']) !== '') {
            $lead[trim((string) $options['zoho_page_url_field_api'])] = $page_url;
        }

        $page_title = isset($payload['page_title']) ? sanitize_text_field($payload['page_title']) : '';
        if ($page_title !== '' && trim((string) $options['zoho_page_title_field_api']) !== '') {
            $lead[trim((string) $options['zoho_page_title_field_api'])] = $page_title;
        }

        if (empty($lead['Last_Name'])) {
            $lead['Last_Name'] = 'Website Lead';
        }

        $api_url = trailingslashit((string) $options['zoho_api_base']) . 'crm/v2/' . rawurlencode((string) $options['zoho_module']);
        $zoho_request = ['data' => [$lead]];

        if ($preview_only) {
            return new WP_REST_Response([
                'success'     => true,
                'previewOnly' => true,
                'apiUrl'      => $api_url,
                'request'     => $zoho_request,
                'debug'       => [
                    'variant'           => $variant,
                    'subSourceFieldApi' => $sub_source_field,
                    'payloadSubSource'  => $payload_sub_source,
                    'resolvedSubSource' => $resolved_sub_source,
                ],
            ], 200);
        }

        $token = $this->zoho_get_access_token($options);
        if (is_wp_error($token)) {
            return $token;
        }

        $response = wp_remote_post($api_url, [
            'timeout' => (int) $options['zoho_timeout'],
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($zoho_request),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('emerus_zoho_request_error', $response->get_error_message(), ['status' => 500]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body  = (string) wp_remote_retrieve_body($response);
        $json_body = json_decode($raw_body, true);

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('emerus_zoho_error', 'Zoho request failed.', [
                'status'   => 502,
                'httpCode' => $http_code,
                'response' => $json_body ?: $raw_body,
            ]);
        }

        return new WP_REST_Response([
            'success'  => true,
            'httpCode' => $http_code,
            'response' => $json_body,
        ], 200);
    }

    private function zoho_get_access_token(array $options) {
        $cache_key = 'emerus_zoho_access_token';
        $token     = get_transient($cache_key);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token_url = trailingslashit((string) $options['zoho_accounts_base']) . 'oauth/v2/token';

        $response = wp_remote_post($token_url, [
            'timeout' => (int) $options['zoho_timeout'],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'    => [
                'refresh_token' => (string) $options['zoho_refresh_token'],
                'client_id'     => (string) $options['zoho_client_id'],
                'client_secret' => (string) $options['zoho_client_secret'],
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('emerus_zoho_token_error', $response->get_error_message(), ['status' => 500]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body  = (string) wp_remote_retrieve_body($response);
        $json_body = json_decode($raw_body, true);

        if ($http_code !== 200 || empty($json_body['access_token'])) {
            return new WP_Error('emerus_zoho_token_invalid', 'Failed to refresh Zoho access token.', [
                'status'   => 502,
                'httpCode' => $http_code,
                'response' => $json_body ?: $raw_body,
            ]);
        }

        $expires_in = !empty($json_body['expires_in']) ? max(300, ((int) $json_body['expires_in']) - 120) : 3300;
        set_transient($cache_key, (string) $json_body['access_token'], $expires_in);

        return (string) $json_body['access_token'];
    }

    private function build_lead_from_rows(array $rows) {
        $lead = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = isset($row['k']) ? trim((string) $row['k']) : '';
            if ($key === '') {
                continue;
            }

            $lead[$key] = isset($row['v']) ? (string) $row['v'] : '';
        }

        return $lead;
    }
}

new Emerus_WSForms_Overlay();
