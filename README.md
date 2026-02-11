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
  - Optional custom JS hook template
- Zoho helper for WS forms already on page:
  - `window.EmerusZoho.sendWsForm(form, options)`
  - Does not require plugin-injected overlay form
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

## WS Field Defaults (Hidden Field Support)

Rule format in plugin settings:

- `page_refs|field_name|value|variant`

Examples:

- `industrijski-profili,solarni-sustavi|Interes|Industrijski profili|product`
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

Use helper in your WS submit JS:

```js
await window.EmerusZoho.sendWsForm('#ws-form-123', {
  formVariant: 'product',
  mode: 'rows', // rows|lead|both
  mapFields: {
    full_name: 'Last_Name',
    email: 'Email',
    phone: 'Phone'
  },
  staticLead: {
    Lead_Source: 'Website'
  }
});
```

Main options accepted by `sendWsForm(form, options)`:

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
