# True RUM Monitor (Mudrava)

Real User Monitoring plugin for WordPress by Mudrava (mudrava.com). Collects TTFB, LCP, server generation time, total load, memory peak, device, network, country, and session ID from real visitors. Includes admin UI, filters, on-demand reports, and scheduled email summaries.

## Status
- Free plugin, single-site support (no multisite handling documented).
- Current version: 0.1.8.

## Requirements
- WordPress with REST API enabled.
- WP-Cron running for scheduled emails.
- PHP 7.4+ recommended.

## Installation
1. Copy the `true-rum-monitor` folder to `wp-content/plugins/`.
2. Activate “True RUM” in WordPress Admin → Plugins.
3. No theme edits required. Frontend collector loads automatically for sampled, non-excluded traffic.

## What is collected (stored in plugin table)
- `event_time`, `url`
- `server_time` (PHP render), `ttfb`, `lcp`, `total_load`
- `memory_peak`, `device`, `net`, `country`
- `session_id`, `user_role`, `meta`

## Admin: Live Monitor
- Menu: WordPress Admin → True RUM → Live Monitor.
- Filters: Session ID, URL contains, Device (desktop/tablet/mobile), Network (4g/3g/2g/slow-2g), per-page size; sortable columns.
- Metrics shown per row: Time, URL, Server Gen, TTFB, LCP, Total Load, Device, Net, Session.
- Color cues for good/warn/poor thresholds (TTFB/LCP).

## Reports (modal)
- Button “Generate Report” builds a modal using current filters.
- Shows: Avg TTFB, Avg LCP, Avg Server Gen, Avg Total Load, P75 LCP, Total Views.
- Shows “Top Slowest Pages (by LCP)” with average LCP, hits, and links.
- Button “Send Report to Email” triggers the email report immediately.

## Email reports
- Schedule: daily or weekly (WP-Cron).
- Content: averages (LCP, TTFB, Total Load), top slow pages (LCP/TTFB, hits), device split.
- Recipient: configured in settings. Test manually via “Test Email” button on the settings page or via the modal button.

## Settings (WordPress Admin → True RUM → Settings)
- Retention: max records, purge older than N days.
- Sampling rate.
- Excluded roles.
- Blacklist URL prefixes (one per line).
- Email reports: schedule (daily/weekly), recipient email.
- Alerts: TTFB threshold, consecutive slow requests, cooldown between alerts.

## REST API
- `POST /wp-json/true-rum/v1/collect`
  - Public; expects header `X-TRM-Nonce` or query `trm_token`; body JSON with the collected metrics.
- `GET /wp-json/true-rum/v1/logs`
  - Auth (manage_options). Query params: page, per_page, order, order_by, session_id, url, device, net.
- `GET /wp-json/true-rum/v1/stats`
  - Auth. Same filters as `/logs`. Returns aggregates and slowest pages.
- `POST /wp-json/true-rum/v1/send-report`
  - Auth. Triggers the email report immediately.

## Data handling and sampling
- Requests are skipped if the user role is excluded, the URL path matches blacklist prefixes, or random sampling excludes it.
- Retention: FIFO by max records and purge by retention days.

## How to validate
1. Open Live Monitor and confirm rows appear for new visits; use filters to narrow results.
2. Click “Generate Report” to see aggregates and “Top Slowest Pages (by LCP)”.
3. Click “Send Report to Email” in the modal to test immediate delivery.
4. On Settings, click “Test Email” to validate scheduled-report delivery and mail setup.

## Support and license
- Vendor: Mudrava (mudrava.com).
- License: GPL-2.0-or-later (WordPress-compatible).
