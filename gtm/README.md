# Emerus GTM Import

Import files:

- `/Users/mateo/Development/emerus/gtm/emerus-gtm-import.json` (default, HTML-only, safest import)
- `/Users/mateo/Development/emerus/gtm/emerus-gtm-import-html.json` (same as default)
- `/Users/mateo/Development/emerus/gtm/emerus-gtm-import-template.json` (uses GTM Community Template tag types)

## What this import includes

- Data layer variables for Emerus payload keys:
  - `emerus_status`
  - `emerus_form_variant`
  - `emerus_form_key`
  - `emerus_page_url`
  - `emerus_page_title`
  - `emerus_http_status`
  - `emerus_error_message`
  - `emerus_zoho_id`
  - `emerus_rows_count`
- Triggers:
  - `emerus_zoho_submit_success`
  - `emerus_zoho_submit_error`
  - Link clicks: `tel:`, `mailto:`, social domains
- Tags:
  - Google tag base (GA4 + Google Ads via gtag)
  - GA4 events (lead success/error, tel/email/social clicks)
  - Google Ads conversions (lead, phone click, email click)
  - Meta Pixel base + events
  - LinkedIn Insight base + conversions

## Required variable setup after import

Fill these constants:

- `C - GA4 Measurement ID`
- `C - Google Ads Conversion ID`
- `C - Google Ads Label - Lead`
- `C - Google Ads Label - Phone Click`
- `C - Google Ads Label - Email Click`
- `C - Meta Pixel ID`
- `C - LinkedIn Partner ID`
- `C - LinkedIn Conversion ID - Lead`
- `C - LinkedIn Conversion ID - Phone Click`
- `C - LinkedIn Conversion ID - Email Click`
- `C - LinkedIn Conversion ID - Social Click`

## Import notes

- GTM import mode: use `Merge` first, then review conflicts.
- Preview in GTM debug and verify:
  - `emerus_zoho_submit_success` events trigger lead tags.
  - `tel:` / `mailto:` / social links trigger click tags.
- HTML import uses portable Custom HTML tags for Meta/LinkedIn, so it does not depend on container-specific Community Template IDs.
- Template import now includes `customTemplate` definitions from your GTM export, so import parser accepts template tag types (`cvt_...`) in most containers.
