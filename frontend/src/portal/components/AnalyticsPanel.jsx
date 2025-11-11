import React from 'react';

const listStyle = {
  margin: '0.5rem 0 0',
  paddingLeft: '1.1rem',
};

const AnalyticsPanel = ({ insights, theme, error }) => {
  if (!insights && !error) {
    return null;
  }

  const logins = insights?.logins || {};
  const documents = insights?.documents || {};
  const setup = Array.isArray(insights?.setup) ? insights.setup : [];
  const pending = setup.filter((step) => !step.completed);

  return (
    <section style={{ background: theme.surfaceColor, borderRadius: theme.borderRadius, padding: '1.25rem' }}>
      <header>
        <h2 style={{ fontFamily: theme.headingFont, color: theme.textColor, margin: 0 }}>Analytics</h2>
        <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>
          Recent engagement and onboarding progress.
        </p>
      </header>

      {error && (
        <p style={{
          marginTop: '1rem',
          background: 'rgba(127,29,29,0.35)',
          color: '#fecaca',
          padding: '0.6rem 0.75rem',
          borderRadius: theme.borderRadius,
        }}>
          {error}
        </p>
      )}

      {insights && (
        <div style={{ marginTop: '1rem', display: 'grid', gap: '0.75rem' }}>
        <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
          <strong>Logins</strong>
          <p style={{ color: theme.mutedTextColor, margin: '0.35rem 0 0' }}>
            Last 7 days: {logins.last_7_days || 0}
          </p>
          <p style={{ color: theme.mutedTextColor, margin: '0.15rem 0 0' }}>
            Last 30 days: {logins.last_30_days || 0}
          </p>
          <p style={{ color: theme.mutedTextColor, margin: '0.15rem 0 0' }}>
            Total recorded: {logins.total || 0}
          </p>
        </div>

        <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
          <strong>Documents</strong>
          <p style={{ color: theme.mutedTextColor, margin: '0.35rem 0 0' }}>
            Published: {documents.total_documents || 0}
          </p>
          <p style={{ color: theme.mutedTextColor, margin: '0.15rem 0 0' }}>
            Downloads (7 days): {documents.downloads_last_7_days || 0}
          </p>
          <p style={{ color: theme.mutedTextColor, margin: '0.15rem 0 0' }}>
            Total downloads: {documents.total_downloads || 0}
          </p>
        </div>

        <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
          <strong>Onboarding</strong>
          {pending.length === 0 ? (
            <p style={{ color: theme.mutedTextColor, margin: '0.35rem 0 0' }}>
              All setup steps complete—great work!
            </p>
          ) : (
            <>
              <p style={{ color: theme.mutedTextColor, margin: '0.35rem 0 0' }}>
                Pending steps:
              </p>
              <ul style={{ ...listStyle, color: theme.mutedTextColor }}>
                {pending.map((step) => (
                  <li key={step.label}>{step.label}</li>
                ))}
              </ul>
            </>
          )}
        </div>
        </div>
      )}
    </section>
  );
};

export default AnalyticsPanel;
