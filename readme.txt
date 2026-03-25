=== Supertab Connect ===
Contributors: getsupertab
Tags: licensing, bot protection, rsl, crawlers, content protection
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to the Supertab Connect platform for RSL license serving and enabling crawler authentication protocol.

== Description ==

Supertab Connect integrates your WordPress site with the [Supertab Connect](https://www.supertab.co/supertab-connect) platform, providing two key capabilities:

**RSL License Serving**

Automatically serves your site's RSL license at `/license.xml`. The license is fetched from the Supertab Connect API and cached locally for performance, enabling crawlers and AI agents to discover your content's licensing terms.

**Crawler Authentication Protocol (CAP)**

Verifies that bots accessing your content present valid license tokens. This allows you to:

* Distinguish licensed access from unauthorized scraping
* Monitor usage against declared licensing terms
* Optionally enforce access controls without blocking compliant bots

**How It Works**

1. Enter your Website URN from the [Supertab Connect dashboard](https://merchant-connect.supertab.co/)
2. Your `license.xml` is immediately served at your site root
3. Optionally add your Merchant API Key to enable license verification
4. Configure which paths should enforce the Crawler Authentication Protocol

**Features**

* Serves RSL `license.xml` at your site root, proxied from the Supertab API
* Caches license XML locally for performance
* Optional bot protection via the Crawler Authentication Protocol
* Configurable path patterns for selective enforcement (supports wildcards)

== External Services ==

This plugin connects to the [Supertab Connect API](https://api-connect.supertab.co) to provide its functionality:

* **RSL License Serving** — Your Website URN is sent to retrieve your site's license XML file. This happens on every request to `/license.xml` (cached locally after first fetch).
* **Crawler Authentication Protocol** — When enabled by the site administrator, page URLs and user agent strings from bot requests are sent to verify license tokens and record usage events.

No personal data from your site visitors is collected or transmitted.

[Supertab Terms of Use and Privacy Policy](https://www.supertab.co/legal)

== Installation ==

1. Upload the `supertab-connect` folder to the `/wp-content/plugins/` directory, or install through the WordPress plugin screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Navigate to Settings > Supertab Connect to configure your Website URN.
4. (Optional) Enter your Merchant API Key and enable the Crawler Authentication Protocol.

You will need a Supertab Connect account. Visit [Supertab Connect](https://merchant-connect.supertab.co/) to get started.

== Frequently Asked Questions ==

= What is an RSL license? =

An RSL is a machine-readable license file that declares how crawlers and AI agents may access your content. Serving it at `/license.xml` makes your licensing terms discoverable.

= What is the Crawler Authentication Protocol (CAP)? =

CAP is a protocol that requires bots to present license tokens when accessing your content. This lets you verify that crawlers are operating under agreed-upon terms.

= Can I control which pages are protected by CAP? =

Yes. When CAP is enabled, you can specify path patterns with wildcard support to control exactly which URLs require license token verification.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
