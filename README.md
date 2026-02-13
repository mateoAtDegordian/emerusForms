# Emerus WS Forms Overlay

Self-injecting WordPress plugin for Bricks sites that renders WS Form as a right-side hero overlay.

## Features

- Two WS Form variants:
  - Hero: `Brzi upit`
  - Product inquiry
- Page targeting:
  - Select pages in plugin settings
  - Optional regex URL rules (one per line)
- Global + per-page copy control (Croatian + English):
  - Title and subtitle for hero/product
  - Per-page override tables for both variants
  - `hide` keyword support to hide subtitle
- WS default field values by page/variant:
  - Define rules in plugin settings
  - Auto-fill hidden/empty fields in WS Form
  - Optional custom JS hook template (run only when enabled)
- Zoho helper for WS forms already on page:
  - `window.EmerusZoho.sendWsForm(form, options)`
  - Does not require plugin-injected overlay form
- Global metadata injection for all helper submissions:
  - `Landing Page` (first session URL)
  - `Page Title`
  - `UTM polja`
  - Editable field API names in plugin options
- GTM / Data Layer events for helper submissions:
  - Success + error event push
  - Editable Data Layer object and event names
  - Optional payload inclusion
- Auto-injection in first hero-like Bricks section, fallback to fixed right panel
- Optional Zoho CRM backend sending endpoint (disabled by default)

## Install

1. Copy folder `emerus-wsforms-overlay` into `wp-content/plugins/`.
   - Folder must contain `emerus-wsforms-overlay.php` and `assets/`.
2. Activate **Emerus WS Forms Overlay** in WordPress admin.
3. Open `Settings -> Emerus WS Forms Overlay`.

## Configure

1. Set `Hero "Brzi upit" WS Form ID`.
2. Set `Product inquiry WS Form ID`.
3. Choose page targeting for product variant.
4. Keep `Hero show everywhere` enabled so hero appears on all pages.
5. Add EN/HR text values.
6. Optional: fill per-page title/subtitle override tables.
7. Optional: add `WS default field rules by page`.
8. Optional: enable `Load JS integration helpers on all frontend pages`.
9. Optional: configure `Global Metadata Injection` field names and UTM keys.
10. Optional: configure `GTM / Data Layer` event settings.

## WS Field Defaults (Hidden Field Support)

Rule format in plugin settings:

- `page_refs|field_name|value|variant`

Examples:

- `industrijski-profili,solarni-sustavi|Interes|Industrijski profili|product`
- `parent:standardni-alu-profili|Interes|Industrijski profili|product`
- `42|Lead_Type|Hero Inquiry|hero`
- `*|Lead_Source|Website|both`

How WS Form should receive the value:

1. Create a field (hidden or normal) with name exactly matching `field_name` (for example `Interes`).
2. The plugin auto-fills matching fields when the form renders.
3. For custom/advanced markup, you can use attribute `data-emerus-default="Interes"`.

Custom JS hook:

- Event name: `emerus-ws-defaults-applied`
- Detail includes: `defaults`, `variant`, `pageId`, `pageSlug`, `appliedCount`
- You can edit the `Custom JS hook` textarea in plugin settings as needed.

## WS Submit Integration (Existing WS Forms)

You can send directly by field refs (raw ID like `351`, `#id`, or CSS selector):

```js
function getByRef(ref) {
  const value = String(ref || '').trim();
  if (!value) return '';

  if (value.startsWith('#')) {
    const byHashId = document.getElementById(value.slice(1));
    if (byHashId) return byHashId.value || '';
  }

  if (/^[A-Za-z0-9_-]+$/.test(value)) {
    const byId = document.getElementById(value);
    if (byId) return byId.value || '';
  }

  try {
    return document.querySelector(value)?.value || '';
  } catch {
    return '';
  }
}

var lead = {
  Last_Name: getByRef('PLACEHOLDER_FULL_NAME_ID_OR_SELECTOR'),
  Email: getByRef('PLACEHOLDER_EMAIL_ID_OR_SELECTOR'),
  Phone: getByRef('PLACEHOLDER_PHONE_ID_OR_SELECTOR'),
  Interes: getByRef('PLACEHOLDER_INTEREST_ID_OR_SELECTOR')
};

var rows = Object.keys(lead)
  .filter((k) => String(lead[k] || '').trim() !== '')
  .map((k) => ({ k, v: String(lead[k]) }));

await window.EmerusZoho.sendLead({
  form_variant: 'product',
  form_key: 'services_products_en',
  page_url: window.location.href,
  page_title: document.title,
  rows,
  lead
});
```

If `Global Metadata Injection` is enabled, `Landing Page`, `Page Title`, and `UTM polja` are appended automatically even when not present in the form.
`Lead_Source` is injected from plugin settings (usually `Website`), so you do not need it in JS template.
Zoho sub-source can be grouped with `Sub-source mapping table` using matches like `form_key:services_products_en` or `variant:product`.

If `GTM / Data Layer` is enabled, each helper send pushes a Data Layer event with form variant and status.

Alternative helper still available: `window.EmerusZoho.sendWsForm(form, options)`.

Main options for `sendWsForm(form, options)`:

- `formKey`: key used for sub-source mapping (recommended)
- `formVariant`: `hero` or `product`
- `mode`: `rows`, `lead`, `both`
- `includeEmpty`: `true` / `false`
- `mapFields`: object for field name mapping
- `staticLead`: object merged into `lead`
- `extraPayload`: object merged at payload root

## Zoho Backend API

Route:

- `POST /wp-json/emerus-wsforms/v1/zoho-lead`

Requirements:

- Toggle `Enable Zoho sending` ON.
- Add Zoho credentials in plugin settings.
- Send request with `X-WP-Nonce` header.

You can call it from frontend JS with helper exposed by plugin:

```js
// Available globally after plugin script loads
// window.EmerusZoho.sendLead(payload)

window.EmerusZoho.sendLead({
  form_variant: 'hero', // or 'product'
  page_url: window.location.href,
  page_title: document.title,
  lead: {
    Last_Name: 'Website Lead',
    Email: 'john@example.com',
    Phone: '+38599111222'
  }
}).then((data) => {
  console.log('Zoho success', data);
}).catch((error) => {
  console.error('Zoho error', error.message);
});
```

Alternative payload (same as your tested PHP):

```js
window.EmerusZoho.sendLead({
  form_variant: 'product',
  rows: [
    { k: 'Last_Name', v: 'Website Lead' },
    { k: 'Email', v: 'john@example.com' }
  ]
});
```

## Notes

- If both variants target a page, product variant can replace hero (setting available).
