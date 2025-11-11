import React, { useEffect, useState } from 'react';

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

const ConversationsPanel = ({
  conversations,
  messages,
  onCreateConversation,
  onLoadConversation,
  onReply,
  theme,
}) => {
  const [expandedId, setExpandedId] = useState(null);
  const [draft, setDraft] = useState({ title: '', message: '' });
  const [replyDraft, setReplyDraft] = useState('');
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [replyError, setReplyError] = useState(null);
  const [replySaving, setReplySaving] = useState(false);

  useEffect(() => {
    if (expandedId && !messages[expandedId]) {
      onLoadConversation(expandedId).catch(() => {
        setReplyError('Unable to load conversation');
      });
    }
  }, [expandedId, messages, onLoadConversation]);

  const handleCreate = async (event) => {
    event.preventDefault();
    if (!draft.title || !draft.message) {
      setError('Title and message are required');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await onCreateConversation({ title: draft.title, message: draft.message });
      setDraft({ title: '', message: '' });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to start conversation');
    } finally {
      setSaving(false);
    }
  };

  const handleReply = async (event) => {
    event.preventDefault();
    if (!expandedId || !replyDraft) {
      return;
    }

    setReplySaving(true);
    setReplyError(null);
    try {
      await onReply(expandedId, replyDraft);
      setReplyDraft('');
    } catch (err) {
      setReplyError(err instanceof Error ? err.message : 'Unable to send reply');
    } finally {
      setReplySaving(false);
    }
  };

  const activeMessages = expandedId ? messages[expandedId] || [] : [];

  return (
    <section>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
        <div>
          <h2 style={{ fontFamily: theme.headingFont, color: theme.textColor, marginBottom: '0.25rem' }}>Conversations</h2>
          <p style={{ margin: 0, color: theme.mutedTextColor }}>Discuss deliverables directly with your team.</p>
        </div>
      </header>

      <form
        onSubmit={handleCreate}
        style={{
          marginTop: '1.5rem',
          background: theme.surfaceColor,
          borderRadius: theme.borderRadius,
          padding: '1rem',
          display: 'grid',
          gap: '0.5rem',
        }}
      >
        <input
          type="text"
          value={draft.title}
          placeholder="Conversation subject"
          onChange={(event) => setDraft((prev) => ({ ...prev, title: event.target.value }))}
          style={{ padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none' }}
        />
        <textarea
          value={draft.message}
          placeholder="Kick off the conversation"
          rows={3}
          onChange={(event) => setDraft((prev) => ({ ...prev, message: event.target.value }))}
          style={{ padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none', resize: 'vertical' }}
        />
        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          <button
            type="submit"
            disabled={saving}
            style={{
              background: theme.accentColor,
              color: '#fff',
              padding: '0.5rem 1rem',
              borderRadius: theme.borderRadius,
              border: 'none',
              cursor: 'pointer',
            }}
          >
            {saving ? 'Posting…' : 'Start conversation'}
          </button>
          {error && <span style={{ color: '#fca5a5' }}>{error}</span>}
        </div>
      </form>

      <div style={{ marginTop: '2rem', display: 'grid', gap: '1rem' }}>
        {conversations.length === 0 && <p style={{ color: theme.mutedTextColor }}>No conversations yet.</p>}

        {conversations.map((conversation) => (
          <article
            key={conversation.id}
            style={{
              background: theme.surfaceColor,
              borderRadius: theme.borderRadius,
              padding: '1rem',
              display: 'grid',
              gap: '0.75rem',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <h3 style={{ margin: 0, fontFamily: theme.headingFont }}>{conversation.title}</h3>
                <p style={{ margin: '0.25rem 0 0', color: theme.mutedTextColor }}>
                  Updated {formatDate(conversation.updated_at)}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setExpandedId((prev) => (prev === conversation.id ? null : conversation.id))}
                style={{
                  padding: '0.35rem 0.75rem',
                  borderRadius: theme.borderRadius,
                  border: '1px solid rgba(255,255,255,0.1)',
                  background: 'transparent',
                  color: theme.textColor,
                  cursor: 'pointer',
                }}
              >
                {expandedId === conversation.id ? 'Hide thread' : 'View thread'}
              </button>
            </div>

            <p style={{ margin: 0, lineHeight: 1.5 }}>{conversation.message}</p>

            {expandedId === conversation.id && (
              <div style={{ borderTop: '1px solid rgba(255,255,255,0.08)', paddingTop: '1rem', display: 'grid', gap: '0.75rem' }}>
                <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: '0.5rem' }}>
                  {activeMessages.length === 0 && (
                    <li style={{ color: theme.mutedTextColor }}>No replies yet.</li>
                  )}

                  {activeMessages.map((message) => (
                    <li
                      key={message.id}
                      style={{
                        background: 'rgba(15,23,42,0.5)',
                        borderRadius: theme.borderRadius,
                        padding: '0.5rem 0.75rem',
                      }}
                    >
                      <div
                        style={{ lineHeight: 1.5 }}
                        dangerouslySetInnerHTML={{ __html: message.message }}
                      />
                      <span style={{ display: 'block', marginTop: '0.25rem', color: theme.mutedTextColor, fontSize: '0.85rem' }}>
                        {formatDate(message.created_at)}
                      </span>
                    </li>
                  ))}
                </ul>

                <form onSubmit={handleReply} style={{ display: 'grid', gap: '0.5rem' }}>
                  <textarea
                    value={replyDraft}
                    onChange={(event) => setReplyDraft(event.target.value)}
                    placeholder="Reply to this conversation"
                    rows={3}
                    style={{ padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none', resize: 'vertical' }}
                  />
                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                    <button
                      type="submit"
                      disabled={replySaving}
                      style={{
                        background: theme.accentColor,
                        color: '#fff',
                        padding: '0.35rem 0.75rem',
                        borderRadius: theme.borderRadius,
                        border: 'none',
                        cursor: 'pointer',
                      }}
                    >
                      {replySaving ? 'Sending…' : 'Send reply'}
                    </button>
                    {replyError && <span style={{ color: '#fca5a5' }}>{replyError}</span>}
                  </div>
                </form>
              </div>
            )}
          </article>
        ))}
      </div>
    </section>
  );
};

export default ConversationsPanel;
