import React, { useMemo } from 'react';
import DocumentsPanel from './components/DocumentsPanel';
import ProjectsPanel from './components/ProjectsPanel';
import ConversationsPanel from './components/ConversationsPanel';
import ActivityPanel from './components/ActivityPanel';
import IntegrationsPanel from './components/IntegrationsPanel';
import AnalyticsPanel from './components/AnalyticsPanel';
import GuidedTour from './components/GuidedTour';
import usePortalApi from './usePortalApi';

const defaultTheme = {
  accent_color: '#2563eb',
  background_color: '#0f172a',
  surface_color: '#1e293b',
  text_color: '#f8fafc',
  muted_text_color: '#cbd5f5',
  logo_url: '',
  heading_font: 'Inter, sans-serif',
  body_font: 'Inter, sans-serif',
  border_radius: '16',
};

const normaliseTheme = (theme) => ({
  accentColor: theme.accent_color,
  backgroundColor: theme.background_color,
  surfaceColor: theme.surface_color,
  textColor: theme.text_color,
  mutedTextColor: theme.muted_text_color,
  logoUrl: theme.logo_url,
  headingFont: theme.heading_font,
  bodyFont: theme.body_font,
  borderRadius: `${parseInt(theme.border_radius, 10) || 16}px`,
});

const PortalApp = () => {
  const boot = window.vhonaClientPortal || {};
  const canManage = Boolean(boot.capabilities?.manageClientPortal);
  const theme = useMemo(() => normaliseTheme({ ...defaultTheme, ...(boot.theme || {}) }), [boot.theme]);
  const {
    projects,
    documents,
    tasks,
    conversations,
    timeline,
    integrations,
    insights,
    loading,
    error,
    analyticsError,
    refresh,
    refreshIntegrations,
    uploadDocument,
    fetchDocumentDetails,
    documentDetails,
    createDocumentVersion,
    approveDocumentVersion,
    createTask,
    updateTask,
    createConversation,
    loadConversation,
    addConversationMessage,
    conversationMessages,
    tour,
    completeTourStep,
    dismissTour,
    notice,
    clearNotice,
  } = usePortalApi();

  if (!boot.restUrl) {
    return (
      <div style={{ padding: '3rem', textAlign: 'center' }}>
        <h1>Client portal assets missing</h1>
        <p>Run <code>yarn build:portal</code> inside the frontend workspace to compile the bundle.</p>
      </div>
    );
  }

  return (
    <div
      style={{
        background: theme.backgroundColor,
        minHeight: '100vh',
        fontFamily: theme.bodyFont,
        color: theme.textColor,
        padding: '2.5rem 1.5rem 4rem',
      }}
    >
      <div style={{ maxWidth: '960px', margin: '0 auto' }}>
        {notice && (
          <div
            role="status"
            style={{
              position: 'fixed',
              top: '1.5rem',
              right: '1.5rem',
              maxWidth: '320px',
              background: notice.type === 'error' ? 'rgba(127,29,29,0.95)' : 'rgba(21,128,61,0.95)',
              color: '#fff',
              padding: '0.75rem 1rem',
              borderRadius: theme.borderRadius,
              boxShadow: '0 10px 25px rgba(15,23,42,0.35)',
              zIndex: 50,
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '0.75rem' }}>
              <div style={{ fontWeight: 600 }}>{notice.message}</div>
              <button
                type="button"
                onClick={clearNotice}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: '#fff',
                  cursor: 'pointer',
                  fontSize: '1rem',
                  lineHeight: 1,
                }}
                aria-label="Dismiss notification"
              >
                ×
              </button>
            </div>
          </div>
        )}
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            {theme.logoUrl ? (
              <img src={theme.logoUrl} alt="Portal logo" style={{ height: '48px', objectFit: 'contain' }} />
            ) : (
              <h1 style={{ fontFamily: theme.headingFont, margin: 0 }}>Client Portal</h1>
            )}
            <p style={{ marginTop: '0.5rem', color: theme.mutedTextColor }}>
              Welcome {boot.user?.name || 'guest'}
            </p>
          </div>
          <button
            type="button"
            onClick={refresh}
            style={{
              padding: '0.5rem 0.9rem',
              borderRadius: theme.borderRadius,
              border: '1px solid rgba(255,255,255,0.2)',
              background: 'transparent',
              color: theme.textColor,
              cursor: 'pointer',
            }}
          >
            {loading ? 'Refreshing…' : 'Refresh'}
          </button>
        </header>

        {error && (
          <div
            style={{
              marginTop: '1rem',
              padding: '0.75rem 1rem',
              background: '#7f1d1d',
              borderRadius: theme.borderRadius,
            }}
          >
            {error}
          </div>
        )}

        <main style={{ marginTop: '2.5rem', display: 'grid', gap: '2rem' }}>
          <ProjectsPanel
            projects={projects}
            tasks={tasks}
            canManage={canManage}
            onCreateTask={createTask}
            onUpdateTask={updateTask}
            theme={theme}
          />
          <DocumentsPanel
            documents={documents}
            canManage={canManage}
            onUpload={uploadDocument}
            theme={theme}
            details={documentDetails}
            onLoadDetails={fetchDocumentDetails}
            onCreateVersion={createDocumentVersion}
            onApproveVersion={approveDocumentVersion}
          />
          <ConversationsPanel
            conversations={conversations}
            messages={conversationMessages}
            onCreateConversation={createConversation}
            onLoadConversation={loadConversation}
            onReply={addConversationMessage}
            theme={theme}
          />
          <div style={{ display: 'grid', gap: '1.5rem', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))' }}>
            <ActivityPanel timeline={timeline} theme={theme} />
            <IntegrationsPanel
              integrations={integrations}
              calendarFeed={boot.integrations?.calendarFeed}
              theme={theme}
              canManage={canManage}
              onRefresh={refreshIntegrations}
            />
            {canManage && (
              <AnalyticsPanel insights={insights} theme={theme} error={analyticsError} />
            )}
          </div>
        </main>
      </div>
      {canManage && (
        <GuidedTour
          theme={theme}
          tour={tour}
          onCompleteStep={completeTourStep}
          onDismiss={dismissTour}
        />
      )}
    </div>
  );
};

export default PortalApp;
