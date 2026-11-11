=== Video Ad Manager ===
Tags: vast, video ads, advertising, ad server, video
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create one free self-hosted clickable, skippable VAST 3.0 video ad. Optionally connect to MEDIARAZZI for hosted ad serving.

== Description ==

Video Ad Manager serves one video ad from your own WordPress site, without registration or a paid service. Upload an MP4 to the WordPress Media Library or enter a direct MP4 URL, set its click destination and skip offset, and copy the VAST 3.0 URL to your VAST-compatible video player. One active local commercial is supported; replacing it retains the VAST URL. Basic aggregated request and playback event statistics are stored in your own WordPress database.

MEDIARAZZI is an optional third-party hosted VAST ad server operated at https://mediarazzi.com/ and billed separately ($49.95/month at the time of this release). You must explicitly enter your own MEDIARAZZI developer API key to connect. When connected, WordPress sends authenticated HTTPS requests to https://mediarazzi.com/api/v1/ to retrieve account ads, pods, saved tags, rotation information (if the MEDIARAZZI server API supports it) and statistics, and to create or edit those resources when the administrator submits a form. Requests include the API key, the parameters you enter (such as ad names, MP4 URLs, destination URLs, IDs, pod contents and rotation weights), and normal HTTPS connection metadata such as your server's IP address. Data returned by MEDIARAZZI is displayed to the WordPress administrator. The key is encrypted at rest with WordPress salts where OpenSSL is available. Free features do not require MEDIARAZZI and do not automatically transmit data to it.

When a VAST player requests your locally served VAST tag or event tracker, your WordPress server receives that request and counts the event by day. Public tracking requests may contain IP addresses in your web server's regular access logs. This plugin does not itself persist those IP addresses in its statistics table. The advertiser destination is opened if a viewer clicks the creative. Video files may load from the external MP4 URL you supply. Video playback, skip buttons and click overlays are supplied by your compatible video player and not by this plugin.

MEDIARAZZI service: https://mediarazzi.com/
MEDIARAZZI privacy policy: https://mediarazzi.com/privacy/ . MEDIARAZZI terms: https://mediarazzi.com/terms/ . Confirm these links remain accessible when submitting. An administrator is responsible for obtaining any legally required consent for advertisements and analytics. The free plugin does not automatically submit data to the hosted service.

== Installation ==

1. Install and activate the ZIP via Plugins > Add New > Upload Plugin.
2. Open Video Ad Manager in the WordPress admin menu.
3. Replace example.com URLs with real direct MP4 and destination URLs.
4. Enter accurate duration, dimensions and skip delay, enable the ad, and save.
5. Copy the VAST URL into a compatible VAST video player.
6. Optional: Open Video Ad Manager > MEDIARAZZI Pro to generate and connect a Developer API key using the step-by-step instructions.

== Frequently Asked Questions ==

= Is the free ad served locally? =
Yes. The plugin makes a VAST endpoint on your WordPress site; no MEDIARAZZI account is required.

= How are impressions counted? =
The VAST endpoint counts requests. Player tracking URLs count impressions, quartiles, starts, clicks, errors and completions separately. Metrics are not unique-person counts and may be affected by automated or repeated traffic.

= Can I serve an ad rotation through MEDIARAZZI? =
MEDIARAZZI has hosted weighted rotations. The WordPress Ad Rotations tab requires the matching MEDIARAZZI rotations API extension to be installed on the MEDIARAZZI service. Until then, WordPress displays an API error rather than suggesting rotations were saved.

= Can I upload from WordPress? =
The free editor uses the standard WordPress Media Library. Hosted MEDIARAZZI ad uploads are managed on the MEDIARAZZI website.

= Does connecting send data off my site? =
Only when you explicitly connect and use MEDIARAZZI Pro. See the service disclosure in the Description section.

== Changelog ==

= 1.2.12 =
* Use WordPress admin-page hooks rather than URL parameters for loading admin scripts and styles.


= 1.2.11 =
* Document justified read-only URL checks and prepared analytics queries for WordPress coding standards.


= 1.2.10 =
* Fix Plugin Check translator comments, add explicit nonce validation and sanitize connector inputs.
* Use prepared SQL identifier placeholders, short-lived statistics caching and cache invalidation.
* Remove discouraged manual translation loader; refresh external-service documentation.

= 1.2.9 =
* Translate more WordPress admin labels and localize JavaScript UI strings.
* Harden REST and API form handling and response validation.
* Move connected-mode copy behavior into an external script.
* Preserve the locally served VAST endpoint and MEDIARAZZI integration.

= 1.2.8 =
* Expand translation coverage of the free editor and API messages.
* Refresh service disclosure links and release documentation.

= 1.2.7 =
* Consolidate release documentation and add plugin privacy-policy guidance.
* Add initial internationalization coverage and validate malformed connector input.
* Prevent raw API error details from appearing in WordPress admin notices.
* Reject unknown local tracking events, and harden XML content generation.

= 1.2.6 =
* Connect authenticated storage and calculated bandwidth reporting where the matching server API is installed.
* Preserve unified MEDIARAZZI Overview and dashboard styles.

= 1.2.5 =
* Unify MEDIARAZZI Pro admin forms and data tables with Your Ads.
* Add account overview with API counts and event statistics.

= 1.2.4 =
* Add credential encryption and validation improvements.
* Add optional MEDIARAZZI Ad Rotations controls.

= 1.2.3 =
* Correct stylesheet loading for MEDIARAZZI admin pages.

= 1.2.2 =
* Improve statistics table and summary metrics layout.

= 1.2.1 =
* Rename ad list to Your Ads and add Copy VAST Tag actions.

= 1.2.0 =
* Add MEDIARAZZI setup instructions and connection testing.

= 1.1.0 =
* Add optional MEDIARAZZI API connection.

= 1.0.0 =
* Initial standalone VAST 3.0 single-commercial editor and event tracking.

== Usage Dashboard ==

Storage and calculated bandwidth are retrieved from MEDIARAZZI only when connected and when the matching usage endpoint is installed. Bandwidth is an estimate, not metered CDN transfer. A missing measurement is shown as unavailable, never fabricated.
