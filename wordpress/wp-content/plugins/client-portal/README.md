# VHONA Client Portal Plugin

This plugin adds a lightweight client portal to WordPress. It ships with:

- Custom roles for **Client Portal Managers** and **Clients**.
- Private custom post types for projects and documents, exposed through a REST API.
- Secure document uploads that can store files in WordPress or an S3-compatible bucket with expiring download links.
- An activity log and admin export so teams can audit uploads and updates.
- A React dashboard that honours admin-configured branding (logo, colours, typography).
- Collaborative project tooling with checklists, threaded conversations, and an activity timeline so clients stay aligned.
- Document analytics, version history, and approval workflows backed by chunked uploads for large assets.
- Integration snapshots that surface connected FastAPI, CRM, billing, and calendar feeds from the settings panel.
- FastAPI-backed integration snapshots with manual refresh controls and cached AI content previews.
- Per-route REST nonces and granular capability checks to protect uploads, tasks, and messaging.
- A lightweight analytics dashboard summarising logins, downloads, and onboarding progress for administrators.
- Configurable data-retention policies with nightly purges, one-click user erasure tooling, and a guided setup tour for portal managers.
- A setup wizard that tracks onboarding progress, captures audit logs for each step, and links directly to the manual testing checklist.
- Filterable activity logging that records every REST mutation, document download, and admin action with CSV export support.

## Installation

1. Copy the `client-portal` directory into `wp-content/plugins/`.
2. Activate **VHONA Client Portal** inside the WordPress admin.
3. Open **Client Portal → Settings** to choose your storage provider, add S3 credentials (optional), and customise the theme.
4. Create or assign users to the **Client Portal Client** role so they can sign in and view the dashboard shortcode `[vhona_client_portal]`.

## Roles & permissions

The plugin introduces a dedicated capability, `access_client_portal`, which is automatically granted to portal clients, managers, and administrators during activation. Custom roles can opt-in by adding the capability manually (e.g. via `map_meta_cap` hooks or the `Members` plugin). All REST API endpoints that surface portal data now require this capability, so non-portal users will receive a `403` response instead of seeing client content.

## Building the React dashboard

The front-end lives under `frontend/src/portal`. To compile and copy the bundle into the plugin:

```bash
cd frontend
yarn install
yarn build:portal
```

The build script writes the assets to `wordpress/wp-content/plugins/client-portal/build/portal/`. When those files exist the shortcode automatically enqueues them.

Need a one-liner? Run the helper script from the repo root:

```bash
./scripts/prepare-portal.sh --wp-dir /absolute/path/to/wordpress
```

It executes the same build step and, when `--wp-dir` is supplied, syncs the plugin into the specified WordPress installation so it is ready to activate.

## Document storage

By default uploaded documents are stored as private WordPress media attachments. You can switch to Amazon S3 (or any compatible service) by providing bucket, region, access key, secret, and an optional object prefix. The plugin signs uploads and downloads with AWS Signature V4 so every link expires after the configured TTL.

All uploads and download renewals are recorded in the activity log shown on the settings page. Use the **Export CSV** button to download the most recent 200 entries for auditing.

## Compliance & retention

Administrators can enable nightly retention sweeps from the settings page to trim document download logs and login metrics beyond the configured window (default 365 days). Manual erasure requests are handled via the **Portal data erasure** form, which removes analytics entries, document download history, and conversation participation for the selected user before optionally deleting their WordPress account. A notification email can be sent automatically when an erasure completes.

Managers also receive a guided in-app tour that walks through branding, project creation, and document upload steps. The tour honours the dismissal toggle in settings and records completion events in the activity log for auditing.

## FastAPI integration

Toggle the FastAPI connection under **Client Portal → Settings** to surface AI content and profile details from the standalone backend. Provide the base URL, a service token, and (optionally) adjust the cache TTL and content limit. Administrators can manually refresh the snapshot from the integrations card in the React dashboard or the settings page.

When enabled, the portal caches the response for the configured number of seconds and exposes the most recent generated assets to managers. Failures or stale responses are logged in the activity timeline for quick auditing.

Large assets are streamed through the `/documents/upload/chunk` endpoint in 5MB slices. Once the final chunk is committed the API returns an `uploadId` that the React dashboard (or external clients) can pass to `POST /documents` or the version endpoint, ensuring multi-hundred-megabyte deliverables complete without exhausting PHP execution limits.

## REST API

The plugin registers REST endpoints under `/wp-json/client-portal/v1/`:

- `GET /projects` – returns the latest projects for the current user.
- `GET /documents` – lists documents with provider metadata and a download URL.
- `POST /documents` – managers can upload a new document (multipart form with `file` or an `upload_id`).
- `POST /documents/upload/chunk` – stream large uploads in 5MB slices and receive an `uploadId` on completion.
- `GET /documents/{id}/download` – returns an expiring URL for local storage or proxies an attachment download.
- `GET /documents/{id}/analytics` – expose download counts and history for managers.
- `GET /documents/{id}/versions` / `POST /documents/{id}/versions` – list or add document revisions (supports file uploads).
- `POST /documents/{id}/versions/{version}/approve` – mark a version as approved.
- `GET /tasks` / `POST /tasks` / `PATCH /tasks/{id}` – manage project checklist items.
- `GET /conversations` / `POST /conversations` – start new discussion threads.
- `GET /conversations/{id}` / `POST /conversations/{id}/messages` – fetch or reply within a conversation.
- `GET /activity` – return the 20 most recent activity log entries.
- `GET /integrations/overview` – snapshot connected FastAPI/CRM/billing feeds (cached for 15 minutes).
- `POST /integrations/refresh` – managers with a valid portal nonce can refresh the integration cache immediately.
- `GET /analytics/insights` – managers only; returns login, download, and onboarding summaries for the analytics panel.

> **Security note:** Every mutating route (`POST`/`PATCH`) requires both the WordPress REST nonce (`X-WP-Nonce`) and a portal-specific nonce exposed via `vhonaClientPortal.nonces`. Requests lacking the portal nonce—or originating from users without the necessary capability—receive a `403` response before any file or payload is processed.

The bundled React app consumes these endpoints, but they can also power external integrations.

## Activity logging

Every upload and document update is stored as a private log entry. Administrators can review the last few entries from the settings page or export a CSV snapshot for compliance.

Use the filter form above the log table to search by type, keyword, or date range before exporting a CSV. All filter selections and exports are captured in the audit trail so teams can trace who accessed sensitive records.

### Command-line tools

WP-CLI users can pull the same activity data without leaving the terminal:

```bash
# List the latest entries (defaults to table output)
wp vhona-portal logs list --type=document_upload --after="2024-01-01"

# Export filtered entries to CSV and write to /tmp/logs.csv
wp vhona-portal logs export --search=download --limit=500 --file=/tmp/logs.csv
```

Both commands understand the same filters as the settings screen (`--type`, `--search`, `--after`, `--before`) so automated audits can stay in lockstep with the UI.

For a full end-to-end exercise of the portal, follow the [manual testing guide](./MANUAL-TESTING.md) after compiling the React bundle.

## Manual testing

Refer to [`MANUAL-TESTING.md`](./MANUAL-TESTING.md) for a step-by-step script that covers the setup wizard, manager/client workflows, document analytics, and audit-log verification.

## Analytics & onboarding

Administrators can review a setup checklist and live analytics directly on the settings page or via the React dashboard. Login events are tracked automatically whenever a portal-capable user signs in, and document download counts are rolled up with rolling 7-day/30-day windows. Pending onboarding steps (branding, integrations, client invitations, and project creation) are highlighted until complete.

## Development notes

- The plugin uses plain PHP (no external SDKs) to sign S3 requests. Ensure the configured IAM user has permission to `PutObject` and `GetObject` on the target bucket.
- React styles rely on basic inline CSS so theme changes immediately affect the dashboard without rebuilding.
- Run `php -l` against the main classes if you make changes: `php -l includes/class-vhona-client-portal.php`.
- PHPUnit coverage for permission and nonce enforcement lives under `tests/rest/test-permissions.php`—run the suite once the WordPress test library is installed to ensure locked-down endpoints stay protected.

## Roadmap

See [`ROADMAP.md`](./ROADMAP.md) for a detailed view of what has already shipped and which initiatives remain—collaboration tooling, document analytics, external integrations, deeper security hardening, and onboarding enhancements.

Contributions are welcome!
