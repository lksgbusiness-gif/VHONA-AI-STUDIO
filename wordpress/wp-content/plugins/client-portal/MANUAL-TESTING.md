# Client portal manual testing guide

Use this checklist to exercise the full portal experience after building the front-end bundle and activating the plugin inside a staging WordPress instance.

## Prerequisites
- Client Portal plugin activated.
- React bundle compiled into `build/portal/`.
- At least one **Client Portal Manager** and one **Client Portal Client** user account.
- Storage configuration (local uploads or S3 credentials) entered on the settings screen.

## Admin setup wizard
1. Open **Client Portal → Setup Wizard** and progress through each step, verifying that:
   - Branding updates persist to the main settings page.
   - Role guidance and skip behaviour update the completion meter.
   - Storage and integration forms save the expected values.
2. Confirm the settings dashboard reports the wizard as complete once the **Finish** step is submitted.

## Manager experience
1. Log in as a manager and visit the shortcode page embedding `[vhona_client_portal]`.
2. Verify that projects, documents, tasks, conversations, and analytics load without console errors.
3. Upload a document, approve a version, and download the file to ensure analytics counters and audit logs increment.
4. Create and edit tasks, start a conversation, and confirm real-time updates appear in the activity timeline.
5. Trigger FastAPI-powered integrations (if enabled) and confirm snapshot data renders.

## Client experience
1. Log in as a client user and confirm read-only access to projects, documents, and conversations.
2. Attempt restricted actions (creating tasks, uploading documents) and verify the UI displays permission errors without crashing.
3. Download a document and ensure the audit log reflects the client download.

## Admin dashboards and logs
1. From **Client Portal → Settings**, filter the activity log by event type and export a CSV.
2. Visit the analytics snapshot to confirm login counts and download statistics include the sessions performed during testing.
3. Use the portal data erasure form on a test account and verify the audit log records both the request and the outcome.

Document any unexpected behaviour alongside screenshots or console errors to streamline follow-up debugging.
