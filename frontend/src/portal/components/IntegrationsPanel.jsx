import React, { useState } from 'react';

const hasPayload = (payload) => payload && typeof payload === 'object' && Object.keys(payload).length > 0;

const describeContent = (item) => {
  if (!item) {
    return '—';
  }

  if (typeof item === 'string') {
    return item;
  }

  if (item.content_type && item.business_name) {
    return `${item.content_type} • ${item.business_name}`;
  }

  if (item.content_type) {
    return item.content_type;
  }

  if (item.title) {
    return item.title;
  }

  if (item.id) {
    return `ID ${item.id}`;
  }

  try {
    return JSON.stringify(item);
  } catch (err) {
    return String(item);
  }
};

const renderGenericSummary = (label, payload, theme) => {
  if (!hasPayload(payload)) {
    return (
      <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
        <strong>{label}</strong>
        <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>No data available.</p>
      </div>
    );
  }

  if (payload.error) {
    return (
      <div style={{ background: 'rgba(127,29,29,0.35)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
        <strong>{label}</strong>
        <p style={{ color: '#fecaca', margin: '0.25rem 0 0' }}>{payload.error}</p>
      </div>
    );
  }

  const keys = Object.keys(payload).filter((key) => key !== 'raw');

  return (
    <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
      <strong>{label}</strong>
      <ul style={{ margin: '0.5rem 0 0', paddingLeft: '1rem', color: theme.mutedTextColor }}>
        {keys.length === 0 && <li>Data received</li>}
        {keys.map((key) => (
          <li key={key}>
            {key}: {String(payload[key])}
          </li>
        ))}
      </ul>
    </div>
  );
};

const renderFastApi = (payload, theme) => {
  if (!hasPayload(payload)) {
    return (
      <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
        <strong>FastAPI</strong>
        <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>No data returned yet.</p>
      </div>
    );
  }

  if (payload.enabled === false) {
    return (
      <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
        <strong>FastAPI</strong>
        <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>Integration disabled.</p>
      </div>
    );
  }

  const errorMessage = payload.status_error || payload.profile_error || payload.history_error;
  if (errorMessage) {
    return (
      <div style={{ background: 'rgba(127,29,29,0.35)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
        <strong>FastAPI</strong>
        <p style={{ color: '#fecaca', margin: '0.25rem 0 0' }}>{errorMessage}</p>
      </div>
    );
  }

  const profile = payload.profile || {};
  const status = payload.status;
  const statusMessage = typeof status === 'string' ? status : status?.message;
  const recent = Array.isArray(payload.recent_content) ? payload.recent_content.slice(0, 3) : [];

  return (
    <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
      <strong>FastAPI</strong>
      {statusMessage && (
        <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>{statusMessage}</p>
      )}
      {profile && (profile.name || profile.email) && (
        <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>
          Connected as {profile.name || profile.email}
        </p>
      )}
      <div style={{ marginTop: '0.75rem' }}>
        <p style={{ color: theme.mutedTextColor, margin: 0 }}>Recent AI assets:</p>
        {recent.length === 0 && <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>No content yet.</p>}
        {recent.length > 0 && (
          <ul style={{ margin: '0.25rem 0 0', paddingLeft: '1rem', color: theme.mutedTextColor }}>
            {recent.map((item, index) => (
              <li key={item.id || index}>{describeContent(item)}</li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
};

const IntegrationsPanel = ({ integrations = {}, calendarFeed, theme, canManage, onRefresh }) => {
  const [refreshing, setRefreshing] = useState(false);

  const fastapi = integrations.fastapi || {};
  const crm = integrations.crm || {};
  const billing = integrations.billing || {};
  const cachedAt = integrations.cached_at;

  const handleRefresh = async () => {
    if (!onRefresh) {
      return;
    }

    setRefreshing(true);

    try {
      await onRefresh();
    } catch (err) {
      // Notification handled upstream via toasts.
    } finally {
      setRefreshing(false);
    }
  };

  const formattedCachedAt = cachedAt
    ? new Date(cachedAt.replace(' ', 'T')).toLocaleString()
    : null;

  return (
    <section style={{ background: theme.surfaceColor, borderRadius: theme.borderRadius, padding: '1.25rem', display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '0.5rem' }}>
        <div>
          <h2 style={{ fontFamily: theme.headingFont, color: theme.textColor, margin: 0 }}>Integrations</h2>
          <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>
            Snapshot of connected services and remote data.
          </p>
          {formattedCachedAt && (
            <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0', fontSize: '0.85rem' }}>
              Last refreshed {formattedCachedAt}
            </p>
          )}
        </div>
        {canManage && onRefresh && (
          <button
            type="button"
            onClick={handleRefresh}
            disabled={refreshing}
            style={{
              padding: '0.45rem 0.9rem',
              borderRadius: theme.borderRadius,
              border: '1px solid rgba(255,255,255,0.2)',
              background: 'transparent',
              color: theme.textColor,
              cursor: refreshing ? 'wait' : 'pointer',
            }}
          >
            {refreshing ? 'Refreshing…' : 'Refresh snapshot'}
          </button>
        )}
      </header>

      {renderFastApi(fastapi, theme)}
      {renderGenericSummary('CRM', crm, theme)}
      {renderGenericSummary('Billing', billing, theme)}
      {calendarFeed && (
        <div style={{ background: 'rgba(15,23,42,0.4)', borderRadius: theme.borderRadius, padding: '0.75rem' }}>
          <strong>Calendar feed</strong>
          <p style={{ color: theme.mutedTextColor, margin: '0.25rem 0 0' }}>{calendarFeed}</p>
        </div>
      )}
    </section>
  );
};

export default IntegrationsPanel;
