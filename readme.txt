=== BytePhase Connector ===
Contributors: bytephase
Tags: crm, leads, contact form, repair shop, integration
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capture WordPress form enquiries straight into BytePhase repair shop management software as leads and repair check-ins — automatically.

== Description ==

BytePhase Connector links your WordPress website to [BytePhase](https://bytephase.com/integrations/), the all-in-one **repair shop management software** for cell phone, computer, and auto repair businesses. When a visitor submits a form on your site, the connector securely forwards it to BytePhase, which turns it into a **lead** or a **repair check-in** — so enquiries from your website land in your workflow instead of an inbox.

The plugin is deliberately simple. It does **not** map fields, detect duplicates, assign staff, or store customer data — all of that happens inside BytePhase's [repair ticket management](https://bytephase.com/features/repair-ticket-management-software/), keyed by each form. You build your form however you like, with whatever field names you like, and map it once inside BytePhase.

**How it works**

1. A visitor submits a form on your WordPress site.
2. The connector captures the fields exactly as they were typed, plus an identifier for that form.
3. It sends them to your BytePhase account over an authenticated HTTPS request.
4. BytePhase matches the fields to your BytePhase fields — you set that up once per form — and creates the lead or repair check-in.

If BytePhase cannot be reached, the submission is queued and retried in the background, so nothing is lost. **BytePhase → Health** shows you what was delivered and lets you retry anything that failed.

**Works with**

* A built-in BytePhase form (shortcodes `[bytephase_lead_form]` and `[bytephase_self_checkin]`) — zero configuration. Change the heading and button with attributes, e.g. `[bytephase_lead_form title="Get a Free Repair Quote" button="Send Request"]` (use `title=""` to hide the heading).
* Contact Form 7
* Elementor Pro Forms
* Any theme or plugin, via `do_action( 'bytephase_submit_form', [ 'form_id' => '...', 'data' => [ ... ] ] )`

Failed submissions (a network blip, BytePhase briefly unavailable) are retried automatically in the background.

== External services ==

This plugin connects to **BytePhase** (bytephase.com), a repair shop management service, to deliver your website's form submissions to your BytePhase account. It is only useful if you have a BytePhase account.

* **What is sent:** when a visitor submits a connected form, the submitted form fields (for example name, email address, phone number, device details, message, and any custom fields your shop has configured), together with a form identifier, are sent over HTTPS to the BytePhase API address you configured (your own BytePhase account).
* **When it is sent:** only at the moment a connected form is submitted, and again if a failed delivery is retried.
* **What is requested:** if you switch custom fields on, the plugin also asks BytePhase for their definitions — the field labels, types and options you created in your own account. This is a read-only request that sends no visitor data, and the answer is cached for 12 hours, so it happens at most twice a day per form type.
* **What is stored in WordPress:** only an operational delivery log (time, status, request id) for up to 30 days — never the submitted customer details.

BytePhase [terms and conditions](https://bytephase.com/terms-conditions/) and [privacy policy](https://bytephase.com/privacy-policy/).

== Installation ==

1. Install and activate the plugin.
2. In BytePhase, go to **Settings → Integrations → Website Forms → WordPress** to get your API address, Store ID, and API key.
3. In WordPress, go to **BytePhase → Connection**, enter those three values exactly as shown there, then click **Test Connection**.
4. Add a form (or use `[bytephase_lead_form]`) and map its fields once in BytePhase.

Full walkthrough with screenshots: [bytephase.com/integrations/wordpress](https://bytephase.com/integrations/wordpress/)

== Frequently Asked Questions ==

= Where do I get my Store ID and API key? =

In BytePhase, open **Settings → Integrations → Website Forms** and choose **WordPress**. BytePhase creates the API key and shows it once — copy it together with your Store ID, then paste both into **BytePhase → Connection** in WordPress. See the [setup guide](https://bytephase.com/integrations/wordpress/) for a walkthrough.

= Does the plugin store my customers' data? =

No. It forwards submissions to BytePhase and keeps only an operational log (status, time, request id) so you can confirm delivery. Customer details live only in BytePhase.

= A submission failed. What happens? =

Temporary failures are queued and retried automatically. You can also retry them manually from **BytePhase → Health**.

= Is this GDPR friendly? What do I tell my visitors? =

The plugin transmits form submissions to BytePhase and stores no customer details in WordPress. It adds suggested wording to **Settings → Privacy** (Policy Guide) that you can copy into your site's privacy policy. Data sent to BytePhase is covered by the [BytePhase privacy policy](https://bytephase.com/privacy-policy/).

= I use Elementor. Anything to watch out for? =

Give every Elementor form a **unique Form Name** and avoid renaming it later. The form name identifies the form to BytePhase — two forms with the same name are treated as one, and renaming a form means re-mapping it in BytePhase.

= Can I add my own fields to the built-in forms? =

Yes. Create them in BytePhase under Settings → Custom fields, then switch them on under BytePhase →
Forms → Custom fields in WordPress. The plugin reads the labels, types, dropdown options and which
are required from BytePhase, so you edit a field in one place and the website follows. You choose
which of them are published on the public form.

= Does it work with caching plugins? =

Yes. The built-in forms are designed to keep working on cached pages, and submissions themselves are never cached.

= Does it slow my website down? =

No. On normal pages the plugin loads nothing. On a page using a built-in form it loads one small stylesheet. Form submissions are forwarded server-side.

== Screenshots ==

1. The Connection screen — API address, Store ID, and API key, with a Test Connection button.
2. The built-in lead form on a page, ready to use with zero configuration.
3. Built-in validation — name plus at least an email or mobile number.
4. The self check-in form for repair bookings, including a serial number field.
5. The Health screen — connection status, success rate, recent activity, and one-click retry.
6. Configurable form title and button text via shortcode attributes.

== Changelog ==

= 1.0.1 =
* New: BytePhase → Forms lets Contact Form 7 and Elementor forms be sent as a Lead or a Self check-in, so a site can run both at once. The native shortcodes already do this and are unaffected.
* Fix: a submission BytePhase rejected outright (invalid key, unmapped field) is now held for retry on the Health screen instead of being discarded.

= 1.0.0 =
* Initial release: connection settings, native form, Contact Form 7 and Elementor connectors, generic hook, background retry, and a health screen.

== Upgrade Notice ==

= 1.0.1 =
Adds a Forms screen to route Contact Form 7 / Elementor submissions to Lead or Self check-in, and stops discarding submissions BytePhase rejected outright.

= 1.0.0 =
Initial release.
