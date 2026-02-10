<?php
/**
 * Plugin Name: Emerus WS Forms Overlay
 * Description: Injects WS Form overlays in Bricks hero sections with page targeting, EN/HR copy, and optional Zoho CRM lead forwarding.
 * Version: 0.1.1
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
            'hero_title_hr'              => 'Zanima vas suradnja s Emerusom? Pošaljite nam upit.',
            'hero_title_en'              => 'Interested in working with Emerus? Send us your inquiry.',
            'hero_subtitle_hr'           => 'Brzi upit za najkraći put do ponude.',
            'hero_subtitle_en'           => 'Quick inquiry for the fastest path to an offer.',
            'product_title_hr'           => 'Zatražite ponudu za ovaj proizvod ili uslugu.',
            'product_title_en'           => 'Request an offer for this product or service.',
            'product_subtitle_hr'        => 'Pošaljite detalje projekta i javit ćemo se brzo.',
            'product_subtitle_en'        => 'Share project details and we will get back quickly.',
            'product_page_texts'         => "", // page_id_or_slug|hr title|en title
            'overlay_max_width'          => 420,
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
            'hero_title_hr'              => isset($raw['hero_title_hr']) ? sanitize_text_field($raw['hero_title_hr']) : $defaults['hero_title_hr'],
            'hero_title_en'              => isset($raw['hero_title_en']) ? sanitize_text_field($raw['hero_title_en']) : $defaults['hero_title_en'],
            'hero_subtitle_hr'           => isset($raw['hero_subtitle_hr']) ? sanitize_text_field($raw['hero_subtitle_hr']) : $defaults['hero_subtitle_hr'],
            'hero_subtitle_en'           => isset($raw['hero_subtitle_en']) ? sanitize_text_field($raw['hero_subtitle_en']) : $defaults['hero_subtitle_en'],
            'product_title_hr'           => isset($raw['product_title_hr']) ? sanitize_text_field($raw['product_title_hr']) : $defaults['product_title_hr'],
            'product_title_en'           => isset($raw['product_title_en']) ? sanitize_text_field($raw['product_title_en']) : $defaults['product_title_en'],
            'product_subtitle_hr'        => isset($raw['product_subtitle_hr']) ? sanitize_text_field($raw['product_subtitle_hr']) : $defaults['product_subtitle_hr'],
            'product_subtitle_en'        => isset($raw['product_subtitle_en']) ? sanitize_text_field($raw['product_subtitle_en']) : $defaults['product_subtitle_en'],
            'product_page_texts'         => isset($raw['product_page_texts']) ? sanitize_textarea_field($raw['product_page_texts']) : $defaults['product_page_texts'],
            'overlay_max_width'          => isset($raw['overlay_max_width']) ? max(280, min(640, absint($raw['overlay_max_width']))) : $defaults['overlay_max_width'],
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
            'zoho_source_field_api'      => isset($raw['zoho_source_field_api']) ? sanitize_text_field($raw['zoho_source_field_api']) : $defaults['zoho_source_field_api'],
            'zoho_sub_source_field_api'  => isset($raw['zoho_sub_source_field_api']) ? sanitize_text_field($raw['zoho_sub_source_field_api']) : $defaults['zoho_sub_source_field_api'],
            'zoho_page_url_field_api'    => isset($raw['zoho_page_url_field_api']) ? sanitize_text_field($raw['zoho_page_url_field_api']) : $defaults['zoho_page_url_field_api'],
            'zoho_page_title_field_api'  => isset($raw['zoho_page_title_field_api']) ? sanitize_text_field($raw['zoho_page_title_field_api']) : $defaults['zoho_page_title_field_api'],
        ];

        return $options;
    }

    private function sanitize_int_array(array $values) {
        $values = array_filter(array_map('absint', $values));
        return array_values(array_unique($values));
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
                        <td><input type="text" id="hero_subtitle_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_subtitle_hr]" value="<?php echo esc_attr($options['hero_subtitle_hr']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hero_subtitle_en">Hero subtitle (EN)</label></th>
                        <td><input type="text" id="hero_subtitle_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hero_subtitle_en]" value="<?php echo esc_attr($options['hero_subtitle_en']); ?>" class="large-text" /></td>
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
                        <td><input type="text" id="product_subtitle_hr" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_subtitle_hr]" value="<?php echo esc_attr($options['product_subtitle_hr']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_subtitle_en">Product subtitle (EN)</label></th>
                        <td><input type="text" id="product_subtitle_en" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_subtitle_en]" value="<?php echo esc_attr($options['product_subtitle_en']); ?>" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_page_texts">Per-page product titles</label></th>
                        <td>
                            <textarea id="product_page_texts" name="<?php echo esc_attr(self::OPTION_KEY); ?>[product_page_texts]" rows="6" class="large-text code"><?php echo esc_textarea($options['product_page_texts']); ?></textarea>
                            <p class="description">One line per rule: <code>page_id_or_slug|Croatian title|English title</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="overlay_max_width">Overlay max width (px)</label></th>
                        <td><input type="number" id="overlay_max_width" min="280" max="640" name="<?php echo esc_attr(self::OPTION_KEY); ?>[overlay_max_width]" value="<?php echo (int) $options['overlay_max_width']; ?>" /></td>
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
                            <p class="description">Leave empty if you do not use a sub-source custom field.</p>
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

                <?php submit_button(__('Save Settings', 'emerus-wsforms-overlay')); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        if (is_admin() || !$this->should_render_on_request()) {
            return;
        }

        $options = $this->get_options();

        wp_enqueue_style(
            'emerus-wsforms-overlay',
            plugins_url('assets/css/frontend.css', __FILE__),
            [],
            '0.1.1'
        );

        wp_enqueue_script(
            'emerus-wsforms-overlay',
            plugins_url('assets/js/frontend.js', __FILE__),
            [],
            '0.1.1',
            true
        );

        wp_localize_script('emerus-wsforms-overlay', 'EmerusWsFormsOverlay', [
            'restUrl'     => esc_url_raw(rest_url('emerus-wsforms/v1/zoho-lead')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'maxWidth'    => (int) $options['overlay_max_width'],
            'currentPath' => wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH),
            'lang'        => $this->current_lang_code(),
        ]);
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
        $title   = $this->resolve_title($variant, $options);

        $subtitle = $this->current_lang_code() === 'hr'
            ? ($variant === 'product' ? $options['product_subtitle_hr'] : $options['hero_subtitle_hr'])
            : ($variant === 'product' ? $options['product_subtitle_en'] : $options['hero_subtitle_en']);

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
                    <p class="emerus-wsforms-overlay__subtitle"><?php echo esc_html($subtitle); ?></p>
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

    private function resolve_title($variant, array $options) {
        $lang = $this->current_lang_code();

        if ($variant === 'product') {
            $page_specific = $this->resolve_product_page_title($lang, (string) $options['product_page_texts']);
            if ($page_specific !== '') {
                return $page_specific;
            }
        }

        if ($lang === 'hr') {
            return $variant === 'product' ? $options['product_title_hr'] : $options['hero_title_hr'];
        }

        return $variant === 'product' ? $options['product_title_en'] : $options['hero_title_en'];
    }

    private function resolve_product_page_title($lang, $rules) {
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

        if ((int) $options['zoho_enabled'] !== 1) {
            return new WP_Error('emerus_zoho_disabled', 'Zoho lead sending is disabled in plugin settings.', ['status' => 403]);
        }

        $nonce = $request->get_header('x_wp_nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('emerus_bad_nonce', 'Invalid REST nonce.', ['status' => 403]);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = $request->get_body_params();
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

        $sub_source_field = trim((string) $options['zoho_sub_source_field_api']);
        if ($sub_source_field !== '' && empty($lead[$sub_source_field])) {
            $lead[$sub_source_field] = $variant === 'product' ? (string) $options['zoho_sub_source_product'] : (string) $options['zoho_sub_source_hero'];
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

        $token = $this->zoho_get_access_token($options);
        if (is_wp_error($token)) {
            return $token;
        }

        $api_url = trailingslashit((string) $options['zoho_api_base']) . 'crm/v2/' . rawurlencode((string) $options['zoho_module']);

        $response = wp_remote_post($api_url, [
            'timeout' => (int) $options['zoho_timeout'],
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(['data' => [$lead]]),
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
