=== Supertab Connect ===
Contributors: getsupertab
Tags: licensing, bot protection, rsl, crawlers, content protection
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.0-beta.2
License: GPL 2.0 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to the Supertab Connect platform for RSL license serving and enabling crawler authentication protocol.

== Description ==

[Supertab Connect](https://www.supertab.co/supertab-connect) lets you define how AI systems and automated scrapers can access your content. You can define licensing terms, manage partner access, and track usage without building custom infrastructure.

Supertab Connect handles the underlying systems, including license servers, token management, and request verification, so you can focus on controlling who accesses your content and how it’s used. It provides two core capabilities:

**RSL License Serving**

Automatically serves your site’s RSL license at /license.xml. The license is fetched from the Supertab Connect API and cached locally for performance, enabling crawlers and AI agents to discover your content’s licensing terms.

**Crawler Authentication Protocol (CAP)**

Verify that connected partners are accessing your content present valid license tokens.

This allows you to:

* confirm which customers are accessing your content
* monitor usage against declared licensing terms
* control access for specific parts of your site

**How It Works**

1. Click **Connect my website**, sign in to Supertab Connect or create an account, and approve the connection
2. Your `license.xml` is immediately served at your site root and listed in your robots.txt
3. Optionally enable license verification
4. Configure which parts of your site require verification via the Crawler Authentication Protocol

**Features**

* Automatically serves your license file at /license.xml
* Adds the License directive to your robots.txt so crawlers can find your license
* Caches license XML locally for performance
* No infrastructure changes required
* Optional verification for connected partners via the Crawler Authentication Protocol
* Flexible control over which parts of your site are covered

== External Services ==

This plugin relies on Supertab Connect, a service operated by Supertab ([supertab.co](https://www.supertab.co/)). You need a Supertab Connect account to use it. The plugin uses the service to publish your license file, to connect your site to your Supertab Connect account and, if you turn them on, to verify license tokens and report crawler traffic. It contacts only the three Supertab Connect addresses below and no other external service. Requests from your server identify themselves with the user agent `supertab-connect-sdk-php` followed by a version number.

= Supertab Connect API =

Address: [https://api-connect.supertab.co](https://api-connect.supertab.co). Used for your license file, for connecting and disconnecting your site, and for checking license tokens.

* License file: once a Website URN is saved, when someone requests `/license.xml` on your site and no copy is cached, the plugin requests your license from the API. Only your Website URN is sent, as part of the request address. The license is cached on your site for 12 hours, and fetched again after that or after you change your credentials or purge the cache.
* Connecting your site: after an administrator approves the connection in the Supertab Connect Merchant Portal, your site sends the API one-time codes that confirm the approval, your Site Title and your site address. The API returns your Website URN and an API key, which the plugin stores in your WordPress database.
* Disconnecting: when an administrator clicks Disconnect or deletes the plugin, an API key issued by Connect my website is sent to the API for Supertab Connect to revoke it. Keys entered by hand are never sent.
* Checking license tokens: this happens only after an administrator enables the Crawler Authentication Protocol (CAP), which is off by default. The plugin downloads Supertab's public signing keys from the API, caches them for up to 48 hours and checks each license token on your server. For every request to the paths covered by CAP that presents a license token, the plugin then sends a usage event to the API, authenticated with your API key. The event contains the full address of the requested page (including its query string), the requester's user agent, the license ID, the result of the token check and the request headers. Cookie, authorization, API key, security token and Forwarded headers are left out, but headers that can carry IP addresses, such as X-Forwarded-For and X-Real-IP, are included.
* Status check: once a Website URN is saved, the plugin answers requests to `/.well-known/supertab/status` for Supertab to confirm which plugin version and settings your site runs. Only a request signed by Supertab gets an answer, which contains the plugin and SDK versions, the CAP enforcement mode and whether analytics is on. To check the signature, the plugin downloads Supertab's public signing keys from the API and caches them for up to 48 hours. This download carries no information about your site or its visitors.

= Supertab Connect Merchant Portal =

Address: [https://merchant-connect.supertab.co](https://merchant-connect.supertab.co). Used only when an administrator clicks Connect my website (or Reconnect), to sign in or create an account and approve the connection.

* The administrator's browser is sent to the portal with your site address, your Site Title, an address in your WordPress admin to return to, and one-time security codes. After the administrator approves or cancels, the portal sends the browser back to that address.

= Supertab Connect analytics =

Address: [https://ingest-connect.supertab.co](https://ingest-connect.supertab.co). Used only when an administrator turns on both CAP and "Enable agent & bot classification". Both are off by default.

* While both are on, the plugin records one event for each front-end request to the paths covered by CAP, from people and bots alike, and sends the events to Supertab with your API key. Supertab uses them to classify AI agent and bot traffic and report it in your Supertab Connect account.
* Each event contains the visitor's IP address and user agent, the requested path (without the query string), the request method, the referring page, the Accept-Language, Accept and browser hint headers, your host name, the names of the other request headers, whether cookies were present, the length and number of query parameters, any HTTP message signature headers, the time and the result of the license check.
* Events are kept briefly in a table in your WordPress database and sent in batches every 15 minutes. If the background queue is turned off with `SUPERTAB_CONNECT_USE_WP_QUEUE` in wp-config.php, each event is sent during the request instead.

= Visitor data =

The plugin sends information about the people visiting your site only when an administrator turns on both CAP and "Enable agent & bot classification", as described under Supertab Connect analytics. Both are off by default. With CAP on by itself, only requests that present a Supertab license token are reported, and those come from licensed crawlers and AI agents, not from people's browsers.

= Other environments =

The addresses above are the defaults. A site administrator can point the plugin at another Supertab environment by defining `SUPERTAB_CONNECT_ENVIRONMENT`, `SUPERTAB_CONNECT_API_BASE_URL`, `SUPERTAB_CONNECT_DASHBOARD_URL` or `SUPERTAB_CONNECT_ANALYTICS_BASE_URL` in wp-config.php.

= Terms and privacy =

All three services are provided by Supertab. The Supertab Terms of Service and Privacy Policy are both published on one page:

* Terms of Service: [https://www.supertab.co/legal](https://www.supertab.co/legal)
* Privacy Policy: [https://www.supertab.co/legal](https://www.supertab.co/legal)

== Installation ==

1. Upload the `supertab-connect` folder to the `/wp-content/plugins/` directory, or install through the WordPress plugin screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Navigate to Settings > Supertab Connect and click Connect my website. You'll sign in to Supertab Connect or create an account, then come straight back to WordPress.
4. (Optional) Enable the Crawler Authentication Protocol.

Connect my website requires a Supertab Connect account: it signs you in to yours or creates one. To set the plugin up without it, click Enter them manually under the card and paste the Website URN and Merchant API Key from the [Supertab Connect dashboard](https://merchant-connect.supertab.co/).

== Frequently Asked Questions ==

= What is an RSL license? =

An RSL is a machine-readable license file that declares how crawlers and AI agents may access your content. Serving it at /license.xml makes your licensing terms discoverable.

= Do I need to edit my robots.txt? =

Usually not. The plugin adds a `License:` line pointing to your license.xml to the robots.txt that WordPress generates, unless it already has one. If your server has a physical robots.txt file, WordPress can't change it, so the plugin settings page shows the line to add by hand, or tells you when the file points to a different license.

= What is Crawler Authentication Protocol (CAP)? =

CAP is a protocol that requires bots to present license tokens when accessing your content. This lets you verify that automated systems accessing your content are doing so under agreed terms. It allows you to track and manage access without changing your site infrastructure.

= Can I control which pages are protected by CAP? =

Yes. When CAP is enabled, you can specify path patterns with wildcard support to control exactly which URLs require license token verification.

== Changelog ==

= 1.2.2 =
* The served /license.xml is now cached for 12 hours instead of indefinitely, so updates to your licensing terms are picked up automatically.

= 1.2.1 =
* Added compatibility with WordPress 7.0.

= 1.2.0 =
* Updated Supertab Connect PHP SDK.

= 1.1.2 =
* Added plugin graphics and screenshot.
* Fixed readme copy.

= 1.1.1 =
* Fixed readme copy.

= 1.1.0 =
* Added links to documentation.
* Fixed copy in the admin.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.2 =
The local /license.xml cache now refreshes every 12 hours instead of being stored indefinitely.

= 1.2.1 =
WP 7.0 compatibility.

= 1.2.0 =
Updated Supertab Connect PHP SDK.

= 1.1.2 =
Readme and graphics.

= 1.1.1 =
Improved readme.

= 1.1.0 =
Admin copy, links to documentation.

= 1.0.0 =
Initial release.
