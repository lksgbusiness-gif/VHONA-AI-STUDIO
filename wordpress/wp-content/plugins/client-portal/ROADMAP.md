# Client Portal Roadmap

This roadmap highlights what has already shipped and what remains to deliver a full-featured client portal experience. Use it
to prioritise work after the recent rebuild of storage, theming, and activity logging.

## ✅ Delivered foundations

- Secure document uploads with optional S3-compatible offload and expiring links.
- Custom roles, private post types, and REST endpoints that back the React dashboard.
- Activity logging with CSV export and settings-based conflict resolution.
- Basic white-labelling controls (logo, colours, typography) applied across the portal UI.

## 🚧 Remaining focus areas

### 1. Deepen collaboration features
- [x] Introduce task checklists tied to projects, with status workflows and due date reminders.
- [x] Layer in threaded conversations so clients and managers can discuss deliverables in context.
- [x] Provide activity timelines ("last updated", "recent uploads") across the dashboard to keep clients informed.

### 2. Enhance document automation & analytics
- [x] Support large-file uploads, chunked transfers, and background processing for heavy assets.
- [x] Track download analytics and client engagement for each document, surfacing reports to managers.
- [x] Add version history and approval/e-signature workflows so clients can review and sign off on revisions.

### 3. Integrate external services
- [x] Sync with the FastAPI backend for AI-generated assets or centralised authentication when enabled in settings.
- [x] Surface CRM, billing, or project-management data by consuming REST/webhook feeds from existing tools.
- [x] Add calendar sync (Google, Outlook) to push project milestones or upcoming meetings.

### 4. Harden security and compliance
- [x] Expand audit logging to include REST mutations, document downloads, and administrative actions.
- [x] Add granular capability checks and short-lived nonces across every REST route and front-end mutation.
- [x] Document retention/erasure workflows and export processes to align with GDPR/CCPA requirements (nightly purges, manual erase tools, updated README guidance).

### 5. Streamline onboarding & analytics
- [x] Build guided setup wizards that walk admins through role assignment, branding, and integration toggles.
- [x] Offer client usage reports (login frequency, document views) to measure adoption.
- [x] Publish contextual help or tours inside the dashboard so new clients understand portal features immediately (manager-only guided tour with completion tracking).

Review and update this roadmap at the end of each iteration so stakeholders can see how close the portal is to parity with
Suitdash-style client workspaces.
