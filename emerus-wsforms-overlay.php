<?php
/**
 * Plugin Name: Emerus WS Forms Overlay
 * Description: Injects WS Form overlays in Bricks hero sections with page targeting, EN/HR copy, and optional Zoho CRM lead forwarding.
 * Version: 0.4.14
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
        delete_transient('emerus_zoho_access_token');
        delete_transient($this->zoho_token_cache_key($options));
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
  // Optional: subscribe to Zoho Campaigns lists by list key / ID.
  // Example: ['3zd0e1436524a...'].
  var lists = [];
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
    if (Array.isArray(lists) && lists.length) {
      payload.lists = lists;
    }

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
            'lists'        => ['3zd0e1436524a9384bd0a0c25503f37f8de5c927d6a56bdbc3389723fea607135'],
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
            'zoho_field_map'             => $this->recommended_zoho_field_map(),
            'zoho_source_field_api'      => 'Lead_Source',
            'zoho_sub_source_field_api'  => '',
            'zoho_page_url_field_api'    => '',
            'zoho_page_title_field_api'  => '',
            'zoho_campaigns_lists_enabled'      => 0,
            'zoho_campaigns_base'               => 'https://campaigns.zoho.eu',
            'zoho_campaigns_source'             => 'Website',
            'zoho_campaigns_list_general'       => '',
            'zoho_campaigns_list_newsletter'    => '',
            'zoho_campaigns_list_gated'         => '',
            'zoho_campaigns_list_industrijski'  => '',
            'zoho_campaigns_list_solarni'       => '',
            'zoho_campaigns_list_gradevinski'   => '',
            'zoho_campaigns_interest_map'       => [],
        ];
    }

    private function get_options() {
        $options = wp_parse_args((array) get_option(self::OPTION_KEY, []), $this->defaults());

        if (empty($options['zoho_field_map']) || !is_array($options['zoho_field_map'])) {
            $options['zoho_field_map'] = $this->recommended_zoho_field_map();
        }

        return $options;
    }

    private function recommended_zoho_field_map() {
        return [
            ['match' => 'Last_Name', 'value' => 'Last_Name'],
            ['match' => 'First_Name', 'value' => 'First_Name'],
            ['match' => 'Email', 'value' => 'Email'],
            ['match' => 'Phone', 'value' => 'Phone'],
            ['match' => 'Description', 'value' => 'Description'],
            ['match' => 'Interes', 'value' => 'Interes'],
            ['match' => 'Proizvod/Usluga', 'value' => 'Proizvod_Usluga'],
            ['match' => 'Landing Page', 'value' => 'Landing_page'],
            ['match' => 'Page URL', 'value' => 'Page_URL'],
            ['match' => 'Page Title', 'value' => 'Page_Title'],
            ['match' => 'UTM polja', 'value' => 'UTM_polja'],
            ['match' => 'Web Form', 'value' => 'Web_form'],
            ['match' => 'Zanimacija za', 'value' => 'Zanimacija_za'],
        ];
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
            'zoho_field_map'             => $this->sanitize_key_value_rows(isset($raw['zoho_field_map']) ? (array) $raw['zoho_field_map'] : []),
            'zoho_source_field_api'      => isset($raw['zoho_source_field_api']) ? sanitize_text_field($raw['zoho_source_field_api']) : $defaults['zoho_source_field_api'],
            'zoho_sub_source_field_api'  => isset($raw['zoho_sub_source_field_api']) ? sanitize_text_field($raw['zoho_sub_source_field_api']) : $defaults['zoho_sub_source_field_api'],
            'zoho_page_url_field_api'    => isset($raw['zoho_page_url_field_api']) ? sanitize_text_field($raw['zoho_page_url_field_api']) : $defaults['zoho_page_url_field_api'],
            'zoho_page_title_field_api'  => isset($raw['zoho_page_title_field_api']) ? sanitize_text_field($raw['zoho_page_title_field_api']) : $defaults['zoho_page_title_field_api'],
            'zoho_campaigns_lists_enabled'      => !empty($raw['zoho_campaigns_lists_enabled']) ? 1 : 0,
            'zoho_campaigns_base'               => isset($raw['zoho_campaigns_base']) ? esc_url_raw(trim((string) $raw['zoho_campaigns_base'])) : $defaults['zoho_campaigns_base'],
            'zoho_campaigns_source'             => isset($raw['zoho_campaigns_source']) ? sanitize_text_field($raw['zoho_campaigns_source']) : $defaults['zoho_campaigns_source'],
            'zoho_campaigns_list_general'       => isset($raw['zoho_campaigns_list_general']) ? sanitize_text_field($raw['zoho_campaigns_list_general']) : $defaults['zoho_campaigns_list_general'],
            'zoho_campaigns_list_newsletter'    => isset($raw['zoho_campaigns_list_newsletter']) ? sanitize_text_field($raw['zoho_campaigns_list_newsletter']) : $defaults['zoho_campaigns_list_newsletter'],
            'zoho_campaigns_list_gated'         => isset($raw['zoho_campaigns_list_gated']) ? sanitize_text_field($raw['zoho_campaigns_list_gated']) : $defaults['zoho_campaigns_list_gated'],
            'zoho_campaigns_list_industrijski'  => isset($raw['zoho_campaigns_list_industrijski']) ? sanitize_text_field($raw['zoho_campaigns_list_industrijski']) : $defaults['zoho_campaigns_list_industrijski'],
            'zoho_campaigns_list_solarni'       => isset($raw['zoho_campaigns_list_solarni']) ? sanitize_text_field($raw['zoho_campaigns_list_solarni']) : $defaults['zoho_campaigns_list_solarni'],
            'zoho_campaigns_list_gradevinski'   => isset($raw['zoho_campaigns_list_gradevinski']) ? sanitize_text_field($raw['zoho_campaigns_list_gradevinski']) : $defaults['zoho_campaigns_list_gradevinski'],
            'zoho_campaigns_interest_map'       => $this->sanitize_key_value_rows(isset($raw['zoho_campaigns_interest_map']) ? (array) $raw['zoho_campaigns_interest_map'] : []),
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

    private function map_lead_field_names(array $lead, array $map_rows) {
        if (empty($map_rows)) {
            return $lead;
        }

        $exact_map = [];
        $lower_map = [];
        foreach ($map_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $from = isset($row['match']) ? trim((string) $row['match']) : '';
            $to   = isset($row['value']) ? trim((string) $row['value']) : '';
            if ($from === '' || $to === '') {
                continue;
            }

            $exact_map[$from] = $to;
            $lower_map[strtolower($from)] = $to;
        }

        if (empty($exact_map)) {
            return $lead;
        }

        $mapped = [];
        foreach ($lead as $key => $value) {
            $from_key = trim((string) $key);
            if ($from_key === '') {
                continue;
            }

            $target_key = $from_key;
            if (isset($exact_map[$from_key])) {
                $target_key = $exact_map[$from_key];
            } else {
                $lower = strtolower($from_key);
                if (isset($lower_map[$lower])) {
                    $target_key = $lower_map[$lower];
                } else {
                    $target_key = $this->normalize_field_key_for_zoho($from_key);
                }
            }

            if ($target_key === '') {
                $target_key = $from_key;
            }

            $str_value = is_scalar($value) ? (string) $value : wp_json_encode($value);
            if (!array_key_exists($target_key, $mapped)) {
                $mapped[$target_key] = $str_value;
                continue;
            }

            if (trim((string) $mapped[$target_key]) === '' && trim($str_value) !== '') {
                $mapped[$target_key] = $str_value;
            }
        }

        return $mapped;
    }

    private function normalize_field_key_for_zoho($key) {
        $normalized = trim((string) $key);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[\s\/-]+/u', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', (string) $normalized);
        $normalized = trim((string) $normalized, '_');

        return $normalized !== '' ? $normalized : trim((string) $key);
    }

    private function zoho_fields_cache_key(array $options) {
        $signature = implode('|', [
            trim((string) $options['zoho_api_base']),
            trim((string) $options['zoho_module']),
            trim((string) $options['zoho_client_id']),
            trim((string) $options['zoho_refresh_token']),
        ]);
        return 'emerus_zoho_fields_' . substr(md5($signature), 0, 16);
    }

    private function get_zoho_fields_for_admin(array $options, $force_refresh = false) {
        $cache_key = $this->zoho_fields_cache_key($options);
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return [
                    'success' => true,
                    'source'  => 'cache',
                    'fields'  => $cached,
                ];
            }
        }

        $token = $this->zoho_get_access_token($options, (bool) $force_refresh);
        if (is_wp_error($token)) {
            return [
                'success' => false,
                'message' => $token->get_error_message(),
                'details' => $token->get_error_data(),
            ];
        }

        $api_url = trailingslashit((string) $options['zoho_api_base'])
            . 'crm/v2/settings/fields?module='
            . rawurlencode((string) $options['zoho_module']);

        $request = $this->zoho_send_get_request($api_url, $token, (int) $options['zoho_timeout']);
        if (is_wp_error($request)) {
            return [
                'success' => false,
                'message' => $request->get_error_message(),
                'details' => $request->get_error_data(),
            ];
        }

        if ((int) $request['httpCode'] >= 400 && (int) $request['httpCode'] < 500) {
            $fresh_token = $this->zoho_get_access_token($options, true);
            if (!is_wp_error($fresh_token)) {
                $retry = $this->zoho_send_get_request($api_url, $fresh_token, (int) $options['zoho_timeout']);
                if (!is_wp_error($retry)) {
                    $request = $retry;
                }
            }
        }

        $http_code = (int) $request['httpCode'];
        $raw_body  = (string) $request['rawBody'];
        $json_body = is_array($request['jsonBody']) ? $request['jsonBody'] : [];

        if ($http_code < 200 || $http_code >= 300) {
            return [
                'success' => false,
                'message' => 'Zoho fields request failed.',
                'details' => $json_body ?: $raw_body,
            ];
        }

        $fields_raw = isset($json_body['fields']) && is_array($json_body['fields']) ? $json_body['fields'] : [];
        $fields = [];

        foreach ($fields_raw as $field) {
            if (!is_array($field)) {
                continue;
            }

            $api_name = isset($field['api_name']) ? trim((string) $field['api_name']) : '';
            if ($api_name === '') {
                continue;
            }

            $fields[] = [
                'api_name'         => $api_name,
                'label'            => isset($field['field_label']) ? (string) $field['field_label'] : '',
                'data_type'        => isset($field['data_type']) ? (string) $field['data_type'] : '',
                'system_mandatory' => !empty($field['system_mandatory']),
                'read_only'        => !empty($field['read_only']),
            ];
        }

        set_transient($cache_key, $fields, 15 * MINUTE_IN_SECONDS);

        return [
            'success' => true,
            'source'  => 'live',
            'fields'  => $fields,
        ];
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
        $fetch_zoho_fields = isset($_GET['emerus_zoho_fetch_fields']) && $_GET['emerus_zoho_fetch_fields'] === '1';
        $force_refresh_fields = $fetch_zoho_fields
            && isset($_GET['emerus_zoho_refresh_fields'])
            && $_GET['emerus_zoho_refresh_fields'] === '1';
        $can_fetch_zoho_fields = $fetch_zoho_fields
            && isset($_GET['_wpnonce'])
            && wp_verify_nonce((string) $_GET['_wpnonce'], 'emerus_zoho_fetch_fields');
        $zoho_fields_result = null;

        if ($can_fetch_zoho_fields) {
            $zoho_fields_result = $this->get_zoho_fields_for_admin($options, $force_refresh_fields);
        } else {
            $cached = get_transient($this->zoho_fields_cache_key($options));
            if (is_array($cached)) {
                $zoho_fields_result = [
                    'success' => true,
                    'source'  => 'cache',
                    'fields'  => $cached,
                ];
            }
        }

        $fetch_query_args = [
            'page' => 'emerus-wsforms-overlay',
            'emerus_zoho_fetch_fields' => '1',
            'emerus_zoho_refresh_fields' => '1',
        ];
        if (isset($_GET['lang'])) {
            $lang_param = sanitize_text_field((string) wp_unslash($_GET['lang']));
            if ($lang_param !== '') {
                $fetch_query_args['lang'] = $lang_param;
            }
        }
        $fetch_zoho_fields_url = wp_nonce_url(add_query_arg($fetch_query_args, admin_url('options-general.php')), 'emerus_zoho_fetch_fields');

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
                        <th scope="row">Payload -> Zoho field mapping</th>
                        <td>
                            <p class="description">Map your internal payload keys to real Zoho API field names. Example: <code>Landing Page</code> -> <code>Landing_page</code>.</p>
                            <?php $this->render_key_value_rows_table('zoho_field_map', isset($options['zoho_field_map']) ? (array) $options['zoho_field_map'] : [], ['Payload key', 'Zoho API field']); ?>
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
                    <tr>
                        <th scope="row">Enable Campaigns list subscribe</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_lists_enabled]" value="1" <?php checked((int) $options['zoho_campaigns_lists_enabled'], 1); ?> />
                                Subscribe contacts to Zoho Campaigns lists by list key / ID.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_base">Campaigns base URL</label></th>
                        <td>
                            <input type="url" id="zoho_campaigns_base" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_base]" value="<?php echo esc_attr($options['zoho_campaigns_base']); ?>" class="regular-text code" />
                            <p class="description">Example: <code>https://campaigns.zoho.eu</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_source">Campaigns source</label></th>
                        <td>
                            <input type="text" id="zoho_campaigns_source" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_source]" value="<?php echo esc_attr($options['zoho_campaigns_source']); ?>" class="regular-text" />
                            <p class="description">Sent as <code>source</code> in list subscribe API (default <code>Website</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_list_general">List key/ID (all forms)</label></th>
                        <td>
                            <input type="text" id="zoho_campaigns_list_general" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_list_general]" value="<?php echo esc_attr($options['zoho_campaigns_list_general']); ?>" class="regular-text code" />
                            <p class="description">Always subscribed for every successful form submit.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_list_newsletter">List key/ID (newsletter)</label></th>
                        <td><input type="text" id="zoho_campaigns_list_newsletter" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_list_newsletter]" value="<?php echo esc_attr($options['zoho_campaigns_list_newsletter']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_list_gated">List key/ID (gated)</label></th>
                        <td><input type="text" id="zoho_campaigns_list_gated" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_list_gated]" value="<?php echo esc_attr($options['zoho_campaigns_list_gated']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_list_industrijski">List key/ID (Industrijski)</label></th>
                        <td><input type="text" id="zoho_campaigns_list_industrijski" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_list_industrijski]" value="<?php echo esc_attr($options['zoho_campaigns_list_industrijski']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_list_solarni">List key/ID (Solarni)</label></th>
                        <td><input type="text" id="zoho_campaigns_list_solarni" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_list_solarni]" value="<?php echo esc_attr($options['zoho_campaigns_list_solarni']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoho_campaigns_list_gradevinski">List key/ID (Građevinski)</label></th>
                        <td><input type="text" id="zoho_campaigns_list_gradevinski" name="<?php echo esc_attr(self::OPTION_KEY); ?>[zoho_campaigns_list_gradevinski]" value="<?php echo esc_attr($options['zoho_campaigns_list_gradevinski']); ?>" class="regular-text code" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Interes text -> list key mapping</th>
                        <td>
                            <p class="description">Optional extra routing rules. Match by text contains (case/accent insensitive). Example: <code>solarni|LIST_KEY_HERE</code>.</p>
                            <?php $this->render_key_value_rows_table('zoho_campaigns_interest_map', isset($options['zoho_campaigns_interest_map']) ? (array) $options['zoho_campaigns_interest_map'] : [], ['Interes match text', 'List key/ID']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Available Zoho fields (module)</th>
                        <td>
                            <p>
                                <a href="<?php echo esc_url($fetch_zoho_fields_url); ?>" class="button button-secondary">Refresh fields from Zoho</a>
                                <span class="description">Fetches <code><?php echo esc_html((string) $options['zoho_module']); ?></code> fields from Zoho API and shows API names for mapping.</span>
                            </p>
                            <?php if (is_array($zoho_fields_result) && !empty($zoho_fields_result['success']) && !empty($zoho_fields_result['fields']) && is_array($zoho_fields_result['fields'])) : ?>
                                <p class="description">Source: <code><?php echo esc_html((string) $zoho_fields_result['source']); ?></code></p>
                                <div style="max-height: 320px; overflow: auto; border: 1px solid #dcdcde;">
                                    <table class="widefat striped" style="margin:0;">
                                        <thead>
                                            <tr>
                                                <th style="width: 28%;">API name</th>
                                                <th style="width: 28%;">Label</th>
                                                <th style="width: 18%;">Data type</th>
                                                <th style="width: 12%;">Required</th>
                                                <th style="width: 14%;">Read-only</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ((array) $zoho_fields_result['fields'] as $field) : ?>
                                                <?php if (!is_array($field)) { continue; } ?>
                                                <tr>
                                                    <td><code><?php echo esc_html(isset($field['api_name']) ? (string) $field['api_name'] : ''); ?></code></td>
                                                    <td><?php echo esc_html(isset($field['label']) ? (string) $field['label'] : ''); ?></td>
                                                    <td><code><?php echo esc_html(isset($field['data_type']) ? (string) $field['data_type'] : ''); ?></code></td>
                                                    <td><?php echo !empty($field['system_mandatory']) ? 'yes' : 'no'; ?></td>
                                                    <td><?php echo !empty($field['read_only']) ? 'yes' : 'no'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif (is_array($zoho_fields_result) && empty($zoho_fields_result['success'])) : ?>
                                <p style="color:#b32d2e;"><strong>Could not fetch Zoho fields:</strong> <?php echo esc_html(isset($zoho_fields_result['message']) ? (string) $zoho_fields_result['message'] : 'Unknown error'); ?></p>
                                <?php if (!empty($zoho_fields_result['details'])) : ?>
                                    <textarea readonly rows="6" class="large-text code"><?php echo esc_textarea(wp_json_encode($zoho_fields_result['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
                                <?php endif; ?>
                            <?php else : ?>
                                <p class="description">No fields fetched yet. Click <strong>Refresh fields from Zoho</strong>.</p>
                            <?php endif; ?>
                        </td>
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
                                <li><code>lists</code>: optional array of Zoho Campaigns list keys/IDs (subscribe API)</li>
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
                '0.4.11'
            );
        }

        wp_enqueue_script(
            'emerus-wsforms-overlay',
            plugins_url('assets/js/frontend.js', __FILE__),
            [],
            '0.4.11',
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

    private function resolve_zoho_module(array $payload, array $options) {
        $default_module = trim((string) $options['zoho_module']);
        if ($default_module === '') {
            $default_module = 'Leads';
        }

        $form_key_raw = isset($payload['form_key']) ? sanitize_text_field((string) $payload['form_key']) : '';
        $form_key     = strtolower(trim($form_key_raw));

        $form_id_raw = '';
        if (isset($payload['form_id'])) {
            $form_id_raw = (string) $payload['form_id'];
        } elseif (isset($payload['formId'])) {
            $form_id_raw = (string) $payload['formId'];
        }
        $form_id = absint($form_id_raw);

        // Newsletter: WS Form ID 2 should go to Contacts.
        if ($form_key === 'ws_form_2' || $form_id === 2) {
            return 'Contacts';
        }

        return $default_module;
    }

    private function resolve_zoho_tags(array $payload) {
        $tags = [];

        if (isset($payload['tags'])) {
            $raw_tags = $payload['tags'];
            if (is_array($raw_tags)) {
                foreach ($raw_tags as $tag) {
                    $name = sanitize_text_field((string) $tag);
                    if ($name !== '') {
                        $tags[] = $name;
                    }
                }
            } else {
                $text = sanitize_text_field((string) $raw_tags);
                if ($text !== '') {
                    $parts = array_filter(array_map('trim', explode(',', $text)));
                    foreach ($parts as $part) {
                        $name = sanitize_text_field((string) $part);
                        if ($name !== '') {
                            $tags[] = $name;
                        }
                    }
                }
            }
        }

        $form_key_raw = isset($payload['form_key']) ? sanitize_text_field((string) $payload['form_key']) : '';
        $form_key     = strtolower(trim($form_key_raw));
        $form_id_raw  = isset($payload['form_id']) ? (string) $payload['form_id'] : (isset($payload['formId']) ? (string) $payload['formId'] : '');
        $form_id      = absint($form_id_raw);

        // Newsletter default tag for WS Form ID 2 unless tags are explicitly provided.
        if (empty($tags) && ($form_key === 'ws_form_2' || $form_id === 2)) {
            $tags[] = 'Newsletter';
        }

        return array_values(array_unique($tags));
    }

    private function resolve_zoho_lists(array $payload) {
        $entries = [];

        $push_entry = static function (&$target, $value, $allow_name = true) {
            if (is_array($value)) {
                $id = '';
                if (isset($value['id'])) {
                    $raw_id = trim((string) $value['id']);
                    $id = preg_match('/^\d+$/', $raw_id) ? $raw_id : '';
                } elseif (isset($value['list_id'])) {
                    $raw_id = trim((string) $value['list_id']);
                    $id = preg_match('/^\d+$/', $raw_id) ? $raw_id : '';
                }

                $name = '';
                if ($allow_name && isset($value['name'])) {
                    $name = sanitize_text_field((string) $value['name']);
                } elseif ($allow_name && isset($value['list_name'])) {
                    $name = sanitize_text_field((string) $value['list_name']);
                }

                $member_status = isset($value['member_status']) ? sanitize_text_field((string) $value['member_status']) : '';

                if ($id === '' && $name === '') {
                    return;
                }

                $target[] = [
                    'id'            => $id,
                    'name'          => $name,
                    'member_status' => $member_status,
                ];
                return;
            }

            $text = sanitize_text_field((string) $value);
            if ($text === '') {
                return;
            }

            if (preg_match('/^\d+$/', $text)) {
                $target[] = ['id' => $text, 'name' => '', 'member_status' => ''];
            } elseif ($allow_name) {
                $target[] = ['id' => '', 'name' => $text, 'member_status' => ''];
            }
        };

        if (isset($payload['crm_lists'])) {
            $raw_lists = $payload['crm_lists'];
            if (is_array($raw_lists)) {
                foreach ($raw_lists as $item) {
                    $push_entry($entries, $item, true);
                }
            } else {
                $parts = array_filter(array_map('trim', explode(',', sanitize_text_field((string) $raw_lists))));
                foreach ($parts as $part) {
                    $push_entry($entries, $part, true);
                }
            }
        }

        // Backward-compatibility only: if legacy `lists` is present, accept numeric IDs only.
        if (isset($payload['lists'])) {
            $raw_lists = $payload['lists'];
            if (is_array($raw_lists)) {
                foreach ($raw_lists as $item) {
                    $push_entry($entries, $item, false);
                }
            } else {
                $parts = array_filter(array_map('trim', explode(',', sanitize_text_field((string) $raw_lists))));
                foreach ($parts as $part) {
                    $push_entry($entries, $part, false);
                }
            }
        }

        if (isset($payload['crm_list_ids'])) {
            $raw_ids = $payload['crm_list_ids'];
            if (is_array($raw_ids)) {
                foreach ($raw_ids as $id) {
                    $push_entry($entries, ['id' => $id]);
                }
            } else {
                $parts = array_filter(array_map('trim', explode(',', sanitize_text_field((string) $raw_ids))));
                foreach ($parts as $part) {
                    $push_entry($entries, ['id' => $part]);
                }
            }
        }

        if (isset($payload['crm_list_id'])) {
            $push_entry($entries, ['id' => $payload['crm_list_id']]);
        }

        if (isset($payload['crm_list_name'])) {
            $push_entry($entries, ['name' => $payload['crm_list_name']]);
        }

        if (isset($payload['crm_list_names'])) {
            $raw_names = $payload['crm_list_names'];
            if (is_array($raw_names)) {
                foreach ($raw_names as $name) {
                    $push_entry($entries, ['name' => $name]);
                }
            } else {
                $parts = array_filter(array_map('trim', explode(',', sanitize_text_field((string) $raw_names))));
                foreach ($parts as $part) {
                    $push_entry($entries, ['name' => $part]);
                }
            }
        }

        $seen = [];
        $out  = [];
        foreach ($entries as $entry) {
            $id   = isset($entry['id']) ? (string) $entry['id'] : '';
            $name = isset($entry['name']) ? (string) $entry['name'] : '';
            $status = isset($entry['member_status']) ? (string) $entry['member_status'] : '';
            if ($id === '' && $name === '') {
                continue;
            }

            $key = $id !== '' ? 'id:' . $id : 'name:' . strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'id'            => $id,
                'name'          => $name,
                'member_status' => $status,
            ];
        }

        return $out;
    }

    private function sanitize_campaign_list_key($value) {
        $key = trim(sanitize_text_field((string) $value));
        if ($key === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return '';
        }
        return $key;
    }

    private function normalize_match_text($value) {
        $value = sanitize_text_field((string) $value);
        $value = strtolower(remove_accents($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    }

    private function first_non_empty_scalar(array $values) {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function resolve_interest_text_for_lists(array $payload, array $lead_unmapped, array $lead_mapped) {
        $payload_interest = '';
        if (!empty($payload['interest']) && is_scalar($payload['interest'])) {
            $payload_interest = sanitize_text_field((string) $payload['interest']);
        } elseif (!empty($payload['interes']) && is_scalar($payload['interes'])) {
            $payload_interest = sanitize_text_field((string) $payload['interes']);
        }

        $lead_interest = $this->first_non_empty_scalar([
            isset($lead_unmapped['Interes']) ? $lead_unmapped['Interes'] : '',
            isset($lead_unmapped['Proizvod/Usluga']) ? $lead_unmapped['Proizvod/Usluga'] : '',
            isset($lead_unmapped['Zanimacija za']) ? $lead_unmapped['Zanimacija za'] : '',
            isset($lead_mapped['Interes']) ? $lead_mapped['Interes'] : '',
            isset($lead_mapped['Proizvod_Usluga']) ? $lead_mapped['Proizvod_Usluga'] : '',
            isset($lead_mapped['Zanimacija_za']) ? $lead_mapped['Zanimacija_za'] : '',
        ]);

        return $payload_interest !== '' ? $payload_interest : sanitize_text_field($lead_interest);
    }

    private function resolve_interest_bucket($interest_text) {
        $value = $this->normalize_match_text($interest_text);
        if ($value === '') {
            return '';
        }
        if (strpos($value, 'solarn') !== false || strpos($value, 'solar') !== false) {
            return 'solarni';
        }
        if (strpos($value, 'industr') !== false) {
            return 'industrijski';
        }
        if (strpos($value, 'gradevin') !== false || strpos($value, 'gradjev') !== false || strpos($value, 'building') !== false) {
            return 'gradevinski';
        }
        return '';
    }

    private function payload_context_contains($value, $needle) {
        $haystack = $this->normalize_match_text($value);
        $needle = $this->normalize_match_text($needle);
        if ($haystack === '' || $needle === '') {
            return false;
        }
        return strpos($haystack, $needle) !== false;
    }

    private function extract_campaign_list_keys_from_payload(array $payload) {
        $keys = [];

        $push_key = function ($value) use (&$keys) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this_key = '';
                    if (is_array($item)) {
                        $this_key = $this->sanitize_campaign_list_key(
                            isset($item['list_key']) ? $item['list_key'] :
                            (isset($item['key']) ? $item['key'] :
                            (isset($item['list_id']) ? $item['list_id'] :
                            (isset($item['id']) ? $item['id'] :
                            (isset($item['value']) ? $item['value'] : ''))))
                        );
                    } else {
                        $this_key = $this->sanitize_campaign_list_key($item);
                    }
                    if ($this_key !== '') {
                        $keys[] = $this_key;
                    }
                }
                return;
            }

            $raw = sanitize_text_field((string) $value);
            if ($raw === '') {
                return;
            }
            foreach (array_filter(array_map('trim', explode(',', $raw))) as $part) {
                $this_key = $this->sanitize_campaign_list_key($part);
                if ($this_key !== '') {
                    $keys[] = $this_key;
                }
            }
        };

        $payload_fields = [
            'campaign_list_key',
            'campaign_list_keys',
            'campaign_lists',
            'list_key',
            'list_keys',
            'list_id',
            'list_ids',
            'lists',
        ];

        foreach ($payload_fields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $push_key($payload[$field]);
        }

        return array_values(array_unique($keys));
    }

    private function resolve_zoho_campaign_list_keys(array $payload, array $lead_unmapped, array $lead_mapped, array $options, $variant, $resolved_sub_source, $resolved_module) {
        $manual_keys = $this->extract_campaign_list_keys_from_payload($payload);
        $auto_keys = [];
        $form_variant = strtolower(trim((string) $variant));
        $form_key = strtolower(trim(sanitize_text_field(isset($payload['form_key']) ? (string) $payload['form_key'] : '')));
        $form_id = absint(isset($payload['form_id']) ? $payload['form_id'] : (isset($payload['formId']) ? $payload['formId'] : 0));
        $sub_source = strtolower(trim(sanitize_text_field((string) $resolved_sub_source)));
        $module = strtolower(trim((string) $resolved_module));

        $is_newsletter = $form_id === 2
            || $module === 'contacts'
            || $this->payload_context_contains($form_variant, 'newsletter')
            || $this->payload_context_contains($form_key, 'newsletter')
            || $this->payload_context_contains($sub_source, 'newsletter');

        $is_gated = $this->payload_context_contains($form_variant, 'gated')
            || $this->payload_context_contains($form_key, 'gated')
            || $this->payload_context_contains($sub_source, 'gated');

        if ((int) $options['zoho_campaigns_lists_enabled'] === 1) {
            $general = $this->sanitize_campaign_list_key(isset($options['zoho_campaigns_list_general']) ? $options['zoho_campaigns_list_general'] : '');
            if ($general !== '') {
                $auto_keys[] = $general;
            }

            if ($is_newsletter) {
                $newsletter = $this->sanitize_campaign_list_key(isset($options['zoho_campaigns_list_newsletter']) ? $options['zoho_campaigns_list_newsletter'] : '');
                if ($newsletter !== '') {
                    $auto_keys[] = $newsletter;
                }
            }

            if ($is_gated) {
                $gated = $this->sanitize_campaign_list_key(isset($options['zoho_campaigns_list_gated']) ? $options['zoho_campaigns_list_gated'] : '');
                if ($gated !== '') {
                    $auto_keys[] = $gated;
                }
            }

            $interest_text = $this->resolve_interest_text_for_lists($payload, $lead_unmapped, $lead_mapped);
            $interest_bucket = $this->resolve_interest_bucket($interest_text);
            if ($interest_bucket === 'industrijski') {
                $key = $this->sanitize_campaign_list_key(isset($options['zoho_campaigns_list_industrijski']) ? $options['zoho_campaigns_list_industrijski'] : '');
                if ($key !== '') {
                    $auto_keys[] = $key;
                }
            } elseif ($interest_bucket === 'solarni') {
                $key = $this->sanitize_campaign_list_key(isset($options['zoho_campaigns_list_solarni']) ? $options['zoho_campaigns_list_solarni'] : '');
                if ($key !== '') {
                    $auto_keys[] = $key;
                }
            } elseif ($interest_bucket === 'gradevinski') {
                $key = $this->sanitize_campaign_list_key(isset($options['zoho_campaigns_list_gradevinski']) ? $options['zoho_campaigns_list_gradevinski'] : '');
                if ($key !== '') {
                    $auto_keys[] = $key;
                }
            }

            $normalized_interest = $this->normalize_match_text($interest_text);
            $rules = isset($options['zoho_campaigns_interest_map']) && is_array($options['zoho_campaigns_interest_map'])
                ? $options['zoho_campaigns_interest_map']
                : [];
            if ($normalized_interest !== '' && !empty($rules)) {
                foreach ($rules as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $match = $this->normalize_match_text(isset($rule['match']) ? $rule['match'] : '');
                    $value = $this->sanitize_campaign_list_key(isset($rule['value']) ? $rule['value'] : '');
                    if ($match === '' || $value === '') {
                        continue;
                    }
                    if (strpos($normalized_interest, $match) !== false) {
                        $auto_keys[] = $value;
                    }
                }
            }
        } else {
            $interest_text = $this->resolve_interest_text_for_lists($payload, $lead_unmapped, $lead_mapped);
            $interest_bucket = $this->resolve_interest_bucket($interest_text);
        }

        $resolved_keys = array_values(array_unique(array_merge($manual_keys, $auto_keys)));

        return [
            'keys'    => $resolved_keys,
            'manual'  => array_values(array_unique($manual_keys)),
            'auto'    => array_values(array_unique($auto_keys)),
            'context' => [
                'form_id'       => $form_id,
                'form_key'      => $form_key,
                'form_variant'  => $form_variant,
                'module'        => $module,
                'sub_source'    => $sub_source,
                'is_newsletter' => $is_newsletter,
                'is_gated'      => $is_gated,
                'interest'      => isset($interest_text) ? sanitize_text_field((string) $interest_text) : '',
                'bucket'        => isset($interest_bucket) ? (string) $interest_bucket : '',
            ],
        ];
    }

    private function resolve_campaigns_contact_info(array $lead_unmapped, array $lead_mapped) {
        $email = sanitize_email($this->first_non_empty_scalar([
            isset($lead_unmapped['Email']) ? $lead_unmapped['Email'] : '',
            isset($lead_mapped['Email']) ? $lead_mapped['Email'] : '',
            isset($lead_mapped['Secondary_Email']) ? $lead_mapped['Secondary_Email'] : '',
        ]));

        $first_name = sanitize_text_field($this->first_non_empty_scalar([
            isset($lead_unmapped['First_Name']) ? $lead_unmapped['First_Name'] : '',
            isset($lead_mapped['First_Name']) ? $lead_mapped['First_Name'] : '',
        ]));

        $last_name = sanitize_text_field($this->first_non_empty_scalar([
            isset($lead_unmapped['Last_Name']) ? $lead_unmapped['Last_Name'] : '',
            isset($lead_mapped['Last_Name']) ? $lead_mapped['Last_Name'] : '',
        ]));

        $phone = sanitize_text_field($this->first_non_empty_scalar([
            isset($lead_unmapped['Phone']) ? $lead_unmapped['Phone'] : '',
            isset($lead_mapped['Phone']) ? $lead_mapped['Phone'] : '',
            isset($lead_mapped['Mobile']) ? $lead_mapped['Mobile'] : '',
        ]));

        if ($last_name === '' && $first_name !== '') {
            $last_name = $first_name;
        }
        if ($last_name === '') {
            $last_name = 'Website Lead';
        }

        // Zoho Campaigns listsubscribe expects "Contact Email" as mandatory key.
        // Keep aliases for compatibility with account-side field variations.
        $contact_info = [
            'Contact Email' => $email,
            'Email'         => $email,
            'First Name'    => $first_name,
            'Last Name'     => $last_name,
        ];
        if ($phone !== '') {
            $contact_info['Phone'] = $phone;
        }

        return $contact_info;
    }

    private function zoho_campaigns_subscribe_lists($campaigns_base, $token, $timeout, $source, array $list_keys, array $contact_info) {
        if (empty($list_keys)) {
            return ['success' => true, 'skipped' => true];
        }

        $email = isset($contact_info['Email']) ? sanitize_email((string) $contact_info['Email']) : '';
        if ($email === '') {
            return new WP_Error('emerus_zoho_campaigns_missing_email', 'Campaign list subscribe requires Email in lead payload.', ['status' => 400]);
        }

        $api_url = trailingslashit((string) $campaigns_base) . 'api/v1.1/json/listsubscribe';
        $final_source = sanitize_text_field((string) $source);
        if ($final_source === '') {
            $final_source = 'Website';
        }

        $contact_info_string = $this->build_campaigns_contactinfo_string($contact_info);
        $results = [];
        $all_ok = true;
        foreach ($list_keys as $list_key) {
            $clean_key = $this->sanitize_campaign_list_key($list_key);
            if ($clean_key === '') {
                continue;
            }

            $request_url = add_query_arg([
                'resfmt'      => 'JSON',
                'listkey'     => $clean_key,
                'source'      => $final_source,
                'contactinfo' => $contact_info_string,
            ], $api_url);
            $response = wp_remote_post((string) $request_url, [
                'timeout' => (int) $timeout,
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . (string) $token,
                    'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
                ],
                'body' => [],
            ]);

            if (is_wp_error($response)) {
                $all_ok = false;
                $results[] = [
                    'list_key' => $clean_key,
                    'success'  => false,
                    'error'    => $response->get_error_message(),
                ];
                continue;
            }

            $http_code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = (string) wp_remote_retrieve_body($response);
            $json_body = json_decode($raw_body, true);
            $status = is_array($json_body) && isset($json_body['status']) ? strtolower((string) $json_body['status']) : '';
            $ok = ($http_code >= 200 && $http_code < 300 && $status !== 'error');

            if (!$ok) {
                $error_code = is_array($json_body) && isset($json_body['code']) ? (string) $json_body['code'] : '';
                if ($error_code === '903') {
                    // Fallback endpoint: add subscriber by email only.
                    $fallback_url = trailingslashit((string) $campaigns_base) . 'api/v1.1/addlistsubscribersinbulk';
                    $fallback_response = wp_remote_post((string) $fallback_url, [
                        'timeout' => (int) $timeout,
                        'headers' => [
                            'Authorization' => 'Zoho-oauthtoken ' . (string) $token,
                            'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
                        ],
                        'body' => [
                            'resfmt'   => 'JSON',
                            'listkey'  => $clean_key,
                            'emailids' => $email,
                        ],
                    ]);
                    if (!is_wp_error($fallback_response)) {
                        $fb_http = (int) wp_remote_retrieve_response_code($fallback_response);
                        $fb_raw = (string) wp_remote_retrieve_body($fallback_response);
                        $fb_json = json_decode($fb_raw, true);
                        $fb_status = is_array($fb_json) && isset($fb_json['status']) ? strtolower((string) $fb_json['status']) : '';
                        $fb_ok = ($fb_http >= 200 && $fb_http < 300 && $fb_status !== 'error');
                        if ($fb_ok) {
                            $ok = true;
                            $http_code = $fb_http;
                            $json_body = $fb_json ?: $fb_raw;
                        }
                    }
                }
            }

            if (!$ok) {
                $all_ok = false;
            }

            $results[] = [
                'list_key' => $clean_key,
                'success'  => $ok,
                'httpCode' => $http_code,
                'response' => $json_body ?: $raw_body,
            ];
        }

        return [
            'success'  => $all_ok,
            'httpCode' => $all_ok ? 200 : 502,
            'result'   => $results,
        ];
    }

    private function build_campaigns_contactinfo_string(array $contact_info) {
        $pairs = [];
        foreach ($contact_info as $key => $value) {
            $k = trim(sanitize_text_field((string) $key));
            $v = trim(sanitize_text_field((string) $value));
            if ($k === '' || $v === '') {
                continue;
            }
            $v = str_replace([',', '{', '}', ':'], ' ', $v);
            $v = preg_replace('/\s+/', ' ', $v);
            $pairs[] = $k . ':' . trim((string) $v);
        }

        return '{' . implode(',', $pairs) . '}';
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

        $lead_unmapped = $lead;

        $field_map_rows = isset($options['zoho_field_map']) && is_array($options['zoho_field_map'])
            ? $options['zoho_field_map']
            : [];
        $lead = $this->map_lead_field_names($lead, $field_map_rows);

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

        $resolved_module = $this->resolve_zoho_module($payload, $options);
        $resolved_tags = $this->resolve_zoho_tags($payload);
        $resolved_lists = $this->resolve_zoho_lists($payload);
        $resolved_campaign_lists = $this->resolve_zoho_campaign_list_keys(
            $payload,
            $lead_unmapped,
            $lead,
            $options,
            $variant,
            $resolved_sub_source,
            $resolved_module
        );
        $api_url = trailingslashit((string) $options['zoho_api_base']) . 'crm/v2/' . rawurlencode($resolved_module);
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
                    'fieldMapCount'     => count($field_map_rows),
                    'resolvedModule'    => $resolved_module,
                    'resolvedTags'      => $resolved_tags,
                    'resolvedLists'     => $resolved_lists,
                    'resolvedCampaignLists' => $resolved_campaign_lists,
                ],
            ], 200);
        }

        $token = $this->zoho_get_access_token($options);
        if (is_wp_error($token)) {
            return $token;
        }

        $request_result = $this->zoho_send_request($api_url, $token, (int) $options['zoho_timeout'], $zoho_request);
        if (is_wp_error($request_result)) {
            return new WP_Error('emerus_zoho_request_error', $request_result->get_error_message(), ['status' => 500]);
        }

        $http_code = (int) $request_result['httpCode'];
        $raw_body  = (string) $request_result['rawBody'];
        $json_body = $request_result['jsonBody'];

        if ($http_code >= 400 && $http_code < 500) {
            // Retry once with forced token refresh to avoid stale cached token edge-cases.
            $fresh_token = $this->zoho_get_access_token($options, true);
            if (!is_wp_error($fresh_token)) {
                $retry_result = $this->zoho_send_request($api_url, $fresh_token, (int) $options['zoho_timeout'], $zoho_request);
                if (!is_wp_error($retry_result)) {
                    $token = $fresh_token;
                    $http_code = (int) $retry_result['httpCode'];
                    $raw_body  = (string) $retry_result['rawBody'];
                    $json_body = $retry_result['jsonBody'];
                }
            }
        }

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('emerus_zoho_error', 'Zoho request failed.', [
                'status'   => 502,
                'httpCode' => $http_code,
                'response' => $json_body ?: $raw_body,
            ]);
        }

        $tag_result = null;
        $list_result = null;
        $campaign_list_result = null;
        $created_id = '';
        if (is_array($json_body)
            && !empty($json_body['data'][0]['details']['id'])
        ) {
            $created_id = (string) $json_body['data'][0]['details']['id'];
        }

        if (!empty($resolved_tags)) {
            if ($created_id !== '') {
                $tag_result = $this->zoho_add_tags_to_record(
                    (string) $options['zoho_api_base'],
                    $resolved_module,
                    $created_id,
                    (string) $token,
                    (int) $options['zoho_timeout'],
                    $resolved_tags
                );
            } else {
                $tag_result = new WP_Error('emerus_zoho_tag_missing_record', 'Could not resolve record ID for tag assignment.');
            }
        }

        if (!empty($resolved_lists)) {
            if ($created_id === '') {
                $list_result = new WP_Error('emerus_zoho_list_missing_record', 'Could not resolve record ID for list assignment.');
            } elseif (!in_array(strtolower((string) $resolved_module), ['leads', 'contacts'], true)) {
                $list_result = new WP_Error('emerus_zoho_list_module_not_supported', 'List assignment is supported only for Leads or Contacts module.');
            } else {
                $list_result = $this->zoho_add_to_campaign_lists(
                    (string) $options['zoho_api_base'],
                    $resolved_module,
                    $created_id,
                    (string) $token,
                    (int) $options['zoho_timeout'],
                    $resolved_lists
                );
            }
        }

        if (!empty($resolved_campaign_lists['keys'])) {
            $campaign_list_result = $this->zoho_campaigns_subscribe_lists(
                (string) $options['zoho_campaigns_base'],
                (string) $token,
                (int) $options['zoho_timeout'],
                (string) $options['zoho_campaigns_source'],
                (array) $resolved_campaign_lists['keys'],
                $this->resolve_campaigns_contact_info($lead_unmapped, $lead)
            );
        }

        return new WP_REST_Response([
            'success'  => true,
            'httpCode' => $http_code,
            'response' => $json_body,
            'tags'     => [
                'requested' => $resolved_tags,
                'result'    => is_wp_error($tag_result)
                    ? ['success' => false, 'error' => $tag_result->get_error_message(), 'data' => $tag_result->get_error_data()]
                    : (is_array($tag_result) ? $tag_result : null),
            ],
            'lists'    => [
                'requested' => $resolved_lists,
                'result'    => is_wp_error($list_result)
                    ? ['success' => false, 'error' => $list_result->get_error_message(), 'data' => $list_result->get_error_data()]
                    : (is_array($list_result) ? $list_result : null),
            ],
            'campaignLists' => [
                'requested' => $resolved_campaign_lists,
                'result'    => is_wp_error($campaign_list_result)
                    ? ['success' => false, 'error' => $campaign_list_result->get_error_message(), 'data' => $campaign_list_result->get_error_data()]
                    : (is_array($campaign_list_result) ? $campaign_list_result : null),
            ],
        ], 200);
    }

    private function zoho_token_cache_key(array $options) {
        $signature = implode('|', [
            trim((string) $options['zoho_accounts_base']),
            trim((string) $options['zoho_client_id']),
            trim((string) $options['zoho_refresh_token']),
        ]);

        if ($signature === '||') {
            return 'emerus_zoho_access_token';
        }

        return 'emerus_zoho_access_token_' . substr(md5($signature), 0, 16);
    }

    private function zoho_get_access_token(array $options, $force_refresh = false) {
        $cache_key = $this->zoho_token_cache_key($options);

        if ($force_refresh) {
            delete_transient($cache_key);
            delete_transient('emerus_zoho_access_token');
        }

        $token = get_transient($cache_key);

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

    private function zoho_send_request($api_url, $token, $timeout, array $zoho_request) {
        $response = wp_remote_post((string) $api_url, [
            'timeout' => (int) $timeout,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . (string) $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($zoho_request),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $raw_body  = (string) wp_remote_retrieve_body($response);
        $json_body = json_decode($raw_body, true);

        return [
            'httpCode' => (int) wp_remote_retrieve_response_code($response),
            'rawBody'  => $raw_body,
            'jsonBody' => $json_body,
        ];
    }

    private function zoho_send_get_request($api_url, $token, $timeout) {
        $response = wp_remote_get((string) $api_url, [
            'timeout' => (int) $timeout,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . (string) $token,
                'Content-Type'  => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $raw_body  = (string) wp_remote_retrieve_body($response);
        $json_body = json_decode($raw_body, true);

        return [
            'httpCode' => (int) wp_remote_retrieve_response_code($response),
            'rawBody'  => $raw_body,
            'jsonBody' => $json_body,
        ];
    }

    private function zoho_add_tags_to_record($api_base, $module, $record_id, $token, $timeout, array $tags) {
        if (empty($tags)) {
            return ['success' => true, 'skipped' => true];
        }

        $api_url = trailingslashit((string) $api_base) . 'crm/v8/' . rawurlencode((string) $module) . '/' . rawurlencode((string) $record_id) . '/actions/add_tags';
        $body = [
            'tags' => array_map(static function ($name) {
                return ['name' => (string) $name];
            }, array_values($tags)),
            'over_write' => false,
        ];

        $response = wp_remote_post((string) $api_url, [
            'timeout' => (int) $timeout,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . (string) $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('emerus_zoho_tag_request_error', $response->get_error_message(), ['status' => 500]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body  = (string) wp_remote_retrieve_body($response);
        $json_body = json_decode($raw_body, true);

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('emerus_zoho_tag_error', 'Zoho add-tags request failed.', [
                'status'   => 502,
                'httpCode' => $http_code,
                'response' => $json_body ?: $raw_body,
            ]);
        }

        return [
            'success'  => true,
            'httpCode' => $http_code,
            'response' => $json_body,
        ];
    }

    private function zoho_resolve_campaign_id_by_name($api_base, $token, $timeout, $campaign_name) {
        $campaign_name = sanitize_text_field((string) $campaign_name);
        if ($campaign_name === '') {
            return '';
        }

        $api_url = trailingslashit((string) $api_base)
            . 'crm/v8/Campaigns/search?word='
            . rawurlencode($campaign_name)
            . '&fields=Campaign_Name';

        $request = $this->zoho_send_get_request($api_url, $token, (int) $timeout);
        if (is_wp_error($request)) {
            return $request;
        }

        $http_code = (int) $request['httpCode'];
        $json_body = $request['jsonBody'];
        $raw_body  = (string) $request['rawBody'];

        if ($http_code === 204) {
            return '';
        }

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('emerus_zoho_list_lookup_error', 'Zoho campaign lookup failed.', [
                'status'   => 502,
                'httpCode' => $http_code,
                'response' => $json_body ?: $raw_body,
                'campaign' => $campaign_name,
            ]);
        }

        if (empty($json_body['data']) || !is_array($json_body['data'])) {
            return '';
        }

        $fallback_id = '';
        foreach ($json_body['data'] as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = preg_replace('/[^0-9]/', '', (string) $row['id']);
            if ($id === '') {
                continue;
            }

            if ($fallback_id === '') {
                $fallback_id = $id;
            }

            $row_name = '';
            if (isset($row['Campaign_Name'])) {
                $row_name = sanitize_text_field((string) $row['Campaign_Name']);
            } elseif (isset($row['Name'])) {
                $row_name = sanitize_text_field((string) $row['Name']);
            }

            if ($row_name !== '' && strtolower($row_name) === strtolower($campaign_name)) {
                return $id;
            }
        }

        return $fallback_id;
    }

    private function zoho_add_to_campaign_lists($api_base, $module, $record_id, $token, $timeout, array $lists) {
        if (empty($lists)) {
            return ['success' => true, 'skipped' => true];
        }

        $resolved = [];
        $unresolved = [];
        foreach ($lists as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = isset($entry['id']) ? preg_replace('/[^0-9]/', '', (string) $entry['id']) : '';
            $name = isset($entry['name']) ? sanitize_text_field((string) $entry['name']) : '';
            $member_status = isset($entry['member_status']) ? sanitize_text_field((string) $entry['member_status']) : '';

            if ($id === '' && $name !== '') {
                $resolved_id = $this->zoho_resolve_campaign_id_by_name($api_base, $token, $timeout, $name);
                if (is_wp_error($resolved_id)) {
                    return $resolved_id;
                }
                $id = (string) $resolved_id;
            }

            if ($id === '') {
                $unresolved[] = ['name' => $name];
                continue;
            }

            $resolved[] = [
                'id'            => $id,
                'name'          => $name,
                'member_status' => $member_status,
            ];
        }

        if (empty($resolved)) {
            return new WP_Error('emerus_zoho_list_no_resolved_ids', 'No valid campaign list IDs were resolved from payload.', [
                'status'    => 400,
                'requested' => $lists,
                'unresolved' => $unresolved,
            ]);
        }

        $data_rows = [];
        foreach ($resolved as $item) {
            $row = ['id' => (string) $item['id']];
            if (!empty($item['member_status'])) {
                $row['Member_Status'] = (string) $item['member_status'];
            }
            $data_rows[] = $row;
        }

        $api_url = trailingslashit((string) $api_base)
            . 'crm/v8/'
            . rawurlencode((string) $module)
            . '/'
            . rawurlencode((string) $record_id)
            . '/Campaigns';

        $response = wp_remote_request((string) $api_url, [
            'method'  => 'PUT',
            'timeout' => (int) $timeout,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . (string) $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode(['data' => $data_rows]),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('emerus_zoho_list_request_error', $response->get_error_message(), ['status' => 500]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body  = (string) wp_remote_retrieve_body($response);
        $json_body = json_decode($raw_body, true);

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('emerus_zoho_list_error', 'Zoho list assignment request failed.', [
                'status'    => 502,
                'httpCode'  => $http_code,
                'response'  => $json_body ?: $raw_body,
                'resolved'  => $resolved,
                'unresolved'=> $unresolved,
            ]);
        }

        return [
            'success'    => true,
            'httpCode'   => $http_code,
            'resolved'   => $resolved,
            'unresolved' => $unresolved,
            'response'   => $json_body,
        ];
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
