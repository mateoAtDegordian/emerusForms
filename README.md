# Emerus WS Forms Overlay

Self-injecting WordPress plugin for Bricks sites that renders WS Form as a right-side hero overlay.

## Features

- Two WS Form variants:
  - Hero: `Brzi upit`
  - Product inquiry
- Page targeting:
  - Select pages in plugin settings
  - Optional regex URL rules (one per line)
- Language-ready frontend text (Croatian + English)
- Auto-injection in first hero-like Bricks section, fallback to fixed right panel
- Optional Zoho CRM backend sending endpoint (disabled by default)

## Install

1. Copy folder `emerus-wsforms-overlay` into `wp-content/plugins/`.
2. Activate **Emerus WS Forms Overlay** in WordPress admin.
3. Open `Settings -> Emerus WS Forms Overlay`.

## Configure

1. Set `Hero "Brzi upit" WS Form ID`.
2. Set `Product inquiry WS Form ID`.
3. Choose page targeting for product variant.
4. Keep `Hero show everywhere` enabled so hero appears on all pages.
5. Add EN/HR text values.

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
- For page-specific product titles, use format:
  - `page_id_or_slug|Croatian title|English title`
