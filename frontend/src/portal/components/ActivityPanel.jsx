import React from 'react';

const formatDate = (value) => {
  if (!value) {
    return '';
  }

  try {
    return new Date(value).toLocaleString();
  } catch (error) {
    return value;
  }
};

const ActivityPanel = ({ timeline, theme }) => (
  <section style={{ background: theme.surfaceColor, borderRadius: theme.borderRadius, padding: '1.25rem' }}>
    <h2 style={{ fontFamily: theme.headingFont, marginTop: 0, color: theme.textColor }}>Recent activity</h2>
    {timeline.length === 0 && <p style={{ color: theme.mutedTextColor }}>No activity logged yet.</p>}
    <ul style={{ listStyle: 'none', margin: '1rem 0 0', padding: 0, display: 'grid', gap: '0.75rem' }}>
      {timeline.map((entry) => (
        <li key={entry.id} style={{ borderLeft: '3px solid rgba(59,130,246,0.4)', paddingLeft: '0.75rem' }}>
          <strong style={{ display: 'block', color: theme.textColor }}>{entry.message}</strong>
          <span style={{ color: theme.mutedTextColor, fontSize: '0.85rem' }}>{formatDate(entry.created_at)}</span>
        </li>
      ))}
    </ul>
  </section>
);

export default ActivityPanel;
