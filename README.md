# WC GTM Checkout Tracking

Tracks completed WooCommerce orders by injecting a **Google Tag Manager** container and pushing a **GA4 `purchase` event** to the `dataLayer` on the order-received (thank-you) page. Includes an optional **server-side fallback** for gateways that don't return the customer to the site.

- **Version:** 1.0.0
- **Author:** Insaf Inhaam
- **Requires:** WooCommerce

> The GTM Container ID and GA4 Measurement ID are configured in the plugin settings (see screenshot below), not hard-coded here. The **Measurement Protocol API secret** is a real secret — keep it only in the settings, never in docs or version control.

---

## Settings page

**WooCommerce → GTM Checkout**

![GTM Checkout settings page](docs/settings-page.png)

---

## For the SEO / Analytics team

### What this sends
On a successful order, the plugin pushes a standard **GA4 Ecommerce `purchase`** event to the data layer:

```js
dataLayer.push({ ecommerce: null });   // clears any prior ecommerce object
dataLayer.push({
  event: "purchase",
  ecommerce: {
    transaction_id: "3322",      // WooCommerce order number
    value: 45000.00,             // order total
    tax: 0.00,
    shipping: 500.00,
    currency: "LKR",
    coupon: "WELCOME10",         // empty string if none
    items: [
      {
        item_id: "SKU-123",      // product SKU, falls back to product ID
        item_name: "Office Chair",
        quantity: 2,
        price: 22000.00,
        item_category: "Seating",  // first product category (if any)
        item_variant: "456"        // variation ID (only for variable products)
      }
    ]
  }
});
```

### How it reaches GA4 (GTM side)
The data layer push only makes the data **available** — a tag in GTM routes it to GA4:

- **Trigger:** `WC - Purchase` — Custom Event, event name = `purchase`
- **Tag:** `GA4 - Purchase` — GA4 Event tag, event name `purchase`, **"Send Ecommerce data" → from Data Layer**, Measurement ID = the GA4 ID set in the plugin settings

To build conversions/audiences, use the GA4 `purchase` event and its ecommerce parameters (`value`, `transaction_id`, `items`, etc.).

> ⚠️ **Avoid double-counting.** The container previously contained `Purchase-GA4-WLSEO5` / `Purchase-Trigger-WLSEO5`. If that old tag also fires on the `purchase` event, orders will be counted twice in GA4 — keep only **one** purchase tag.

### Verifying data
- The standard **Ecommerce purchases report lags 24–48h** — don't use it to confirm a fresh test order.
- Use **GA4 → Realtime** or **Admin → DebugView**, or **GTM Preview (Tag Assistant)** for live confirmation.

---

## For developers

### What it does
1. Outputs the GTM container snippet in `<head>` and the `<noscript>` fallback after `<body>`.
2. On `woocommerce_thankyou`, prints the `purchase` data layer push (client-side).
3. Optionally sends the purchase **server-side** via the GA4 Measurement Protocol when payment is confirmed — covers customers who never land on the thank-you page (e.g. some bank/redirect gateways).

### Settings
**WooCommerce → GTM Checkout** (`manage_options`). Stored in one option: `wc_gtm_checkout_settings`.

| Field | Key | Notes |
|---|---|---|
| GTM Container ID | `container_id` | Validated against `GTM-XXXX` |
| Enable tracking | `enabled` | Master on/off for container + client event |
| Enable server fallback | `server_fallback` | Requires the two fields below |
| GA4 Measurement ID | `ga4_measurement_id` | Validated against `G-XXXX` |
| Measurement Protocol API secret | `ga4_api_secret` | GA4 → Admin → Data Streams → MP API secrets |

### Hooks used
| Hook | Method | Purpose |
|---|---|---|
| `wp_head` | `output_gtm_head` | GTM container script |
| `wp_body_open` | `output_gtm_body` | GTM `<noscript>` |
| `woocommerce_thankyou` | `push_purchase_event` | Client-side `purchase` push |
| `woocommerce_checkout_order_processed` / `woocommerce_store_api_checkout_order_processed` | `capture_client_id` | Stores the GA client id from the `_ga` cookie |
| `woocommerce_order_status_processing` / `_completed` | `maybe_send_server_purchase` | Server-side Measurement Protocol fallback |

### Order meta written
| Meta key | Values | Purpose |
|---|---|---|
| `_gtm_purchase_tracked` | `client` \| `server` | **Dedup flag.** Whichever path fires first sets it; the other path then skips, so an order is never counted twice. |
| `_gtm_ga_client_id` | `12345.67890` | GA client id captured at checkout, used to attribute the server-side event to the same user/session. |

### Deduplication logic
- Client thank-you push checks `_gtm_purchase_tracked`; if unset, it pushes and sets it to `client`.
- Server fallback checks the same flag; if unset, it sends via Measurement Protocol and sets it to `server`.
- A page refresh therefore won't re-fire the client event, and the server fallback won't duplicate the client event.

### Server-side fallback details
- Endpoint: `https://www.google-analytics.com/mp/collect` (POST, JSON).
- Auth: `measurement_id` + `api_secret` query params.
- `client_id` from `_gtm_ga_client_id`; if missing (guest via redirect gateway), a stable id is synthesised from the order number + creation timestamp.
- Failures are logged as a WooCommerce **order note**.

---

## Testing checklist
1. Set the GTM Container ID and enable tracking.
2. Place a **fresh** test order (the dedup flag means you can't re-test an already-tracked order by refreshing — use a new order, or temporarily clear the `_gtm_purchase_tracked` meta).
3. On the order-received page, open DevTools console → type `dataLayer` → confirm the `purchase` object.
4. Confirm in **GTM Preview** that `GA4 - Purchase` fires, then in **GA4 DebugView/Realtime** that the event arrives.
5. Make sure the report/property you check matches the GA4 Measurement ID set in the plugin settings.

## Troubleshooting
| Symptom | Likely cause |
|---|---|
| No data in Ecommerce report | Report lag (24–48h). Use Realtime/DebugView. |
| Event in dataLayer but not in GA4 | GTM tag missing/not published, or Measurement ID points to a different property. |
| Orders counted twice | A second purchase tag (e.g. `Purchase-GA4-WLSEO5`) also fires on `purchase`. Keep one. |
| Refresh doesn't re-fire | Expected — `_gtm_purchase_tracked` dedup. Use a new order. |
| Missing orders from a gateway | Customer didn't return to thank-you page → enable the server-side fallback. |

## Related tooling
`wp-content/gtm-setup/` contains a Node script (`setup-gtm.js`) that created the GTM trigger/tag via the Tag Manager API, and `inspect-gtm.js` to audit existing purchase tags.
**Do not deploy `gtm-setup/` to production** — it contains OAuth credentials (`credentials.json`, `token.json`).
