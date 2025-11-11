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

const DocumentsPanel = ({
  documents,
  canManage,
  onUpload,
  theme,
  details,
  onLoadDetails,
  onCreateVersion,
  onApproveVersion,
}) => {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState(null);
  const [expandedId, setExpandedId] = useState(null);
  const [versionDraft, setVersionDraft] = useState({ label: '', notes: '', file: null });
  const [versionError, setVersionError] = useState(null);
  const [savingVersion, setSavingVersion] = useState(false);

  const expandedDetails = expandedId ? details[expandedId] : null;
  const analytics = expandedDetails?.analytics;
  const versions = expandedDetails?.versions || [];

  useEffect(() => {
    if (expandedId && !details[expandedId]) {
      onLoadDetails(expandedId).catch(() => {
        // swallow errors here; the panel will show lack of analytics
      });
    }
  }, [details, expandedId, onLoadDetails]);

  const downloadCount = analytics?.downloads || 0;
  const lastDownload = analytics?.last_download_at;

  const handleFileChange = async (event) => {
    const [file] = event.target.files || [];
    if (!file) {
      return;
    }

    setUploading(true);
    setError(null);

    try {
      await onUpload(file.name.replace(/\.[^.]+$/, ''), file);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Upload failed');
    } finally {
      setUploading(false);
      event.target.value = '';
    }
  };

  const handleCreateVersion = async (event) => {
    event.preventDefault();
    if (!expandedId) {
      return;
    }

    setSavingVersion(true);
    setVersionError(null);
    try {
      await onCreateVersion(expandedId, {
        label: versionDraft.label,
        notes: versionDraft.notes,
        file: versionDraft.file,
      });
      setVersionDraft({ label: '', notes: '', file: null });
    } catch (err) {
      setVersionError(err instanceof Error ? err.message : 'Unable to create version');
    } finally {
      setSavingVersion(false);
    }
  };

  return (
    <section>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h2 style={{ fontFamily: theme.headingFont, color: theme.textColor }}>Documents</h2>
        {canManage && (
          <label
            style={{
              cursor: 'pointer',
              padding: '0.5rem 1rem',
              background: theme.accentColor,
              borderRadius: theme.borderRadius,
              color: '#fff',
              fontWeight: 600,
            }}
          >
            {uploading ? 'Uploading…' : 'Upload'}
            <input type="file" onChange={handleFileChange} style={{ display: 'none' }} />
          </label>
        )}
      </header>

      {error && (
        <p style={{ color: '#ef4444', marginTop: '0.75rem' }}>{error}</p>
      )}

      <div style={{ marginTop: '1rem', display: 'grid', gap: '0.75rem' }}>
        {documents.length === 0 && (
          <p style={{ color: theme.mutedTextColor }}>No documents available.</p>
        )}

        {documents.map((document) => (
          <article
            key={document.id}
            style={{
              background: theme.surfaceColor,
              color: theme.textColor,
              borderRadius: theme.borderRadius,
              padding: '1rem',
              display: 'grid',
              gap: '0.75rem',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: '1rem', fontFamily: theme.headingFont }}>{document.title}</h3>
                <p style={{ margin: '0.25rem 0 0', color: theme.mutedTextColor }}>
                  Updated {formatDate(document.updated_at)}
                </p>
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                {document.downloadUrl && (
                  <a
                    href={document.downloadUrl}
                    style={{
                      padding: '0.35rem 0.75rem',
                      borderRadius: theme.borderRadius,
                      background: 'rgba(255,255,255,0.08)',
                      color: theme.textColor,
                    }}
                  >
                    Download
                  </a>
                )}
                <button
                  type="button"
                  onClick={() => setExpandedId((prev) => (prev === document.id ? null : document.id))}
                  style={{
                    padding: '0.35rem 0.75rem',
                    borderRadius: theme.borderRadius,
                    border: '1px solid rgba(255,255,255,0.1)',
                    background: 'transparent',
                    color: theme.textColor,
                    cursor: 'pointer',
                  }}
                >
                  {expandedId === document.id ? 'Hide details' : 'View details'}
                </button>
              </div>
            </div>

            {expandedId === document.id && (
              <div style={{ borderTop: '1px solid rgba(255,255,255,0.08)', paddingTop: '1rem', display: 'grid', gap: '1rem' }}>
                <div>
                  <h4 style={{ margin: 0, fontFamily: theme.headingFont }}>Engagement</h4>
                  <p style={{ margin: '0.25rem 0 0', color: theme.mutedTextColor }}>
                    {downloadCount} downloads
                    {lastDownload && ` · Last accessed ${formatDate(lastDownload)}`}
                  </p>
                </div>

                <div>
                  <h4 style={{ margin: 0, fontFamily: theme.headingFont }}>Versions</h4>
                  {versions.length === 0 && <p style={{ color: theme.mutedTextColor }}>No versions yet.</p>}
                  <ul style={{ listStyle: 'none', margin: '0.5rem 0 0', padding: 0, display: 'grid', gap: '0.5rem' }}>
                    {versions.map((version) => (
                      <li
                        key={version.id}
                        style={{
                          background: 'rgba(15,23,42,0.5)',
                          borderRadius: theme.borderRadius,
                          padding: '0.5rem 0.75rem',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                        }}
                      >
                        <div>
                          <strong>{version.label}</strong>
                          <div style={{ fontSize: '0.85rem', color: theme.mutedTextColor }}>
                            Created {formatDate(version.created_at)}
                            {version.approved_at && ` · Approved ${formatDate(version.approved_at)}`}
                          </div>
                        </div>
                        <div style={{ display: 'flex', gap: '0.5rem' }}>
                          {version.downloadUrl && (
                            <a
                              href={version.downloadUrl}
                              style={{
                                padding: '0.25rem 0.6rem',
                                borderRadius: theme.borderRadius,
                                background: 'rgba(255,255,255,0.08)',
                                color: theme.textColor,
                              }}
                            >
                              Download
                            </a>
                          )}
                          {canManage && version.status !== 'approved' && (
                            <button
                              type="button"
                              onClick={() => onApproveVersion(document.id, version.id)}
                              style={{
                                padding: '0.25rem 0.6rem',
                                borderRadius: theme.borderRadius,
                                border: '1px solid rgba(255,255,255,0.1)',
                                background: 'transparent',
                                color: theme.textColor,
                                cursor: 'pointer',
                              }}
                            >
                              Approve
                            </button>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>

                  {canManage && (
                    <form
                      onSubmit={handleCreateVersion}
                      style={{
                        marginTop: '1rem',
                        background: 'rgba(15,23,42,0.4)',
                        borderRadius: theme.borderRadius,
                        padding: '0.75rem',
                        display: 'grid',
                        gap: '0.5rem',
                      }}
                    >
                      <input
                        type="text"
                        value={versionDraft.label}
                        placeholder="Version label (e.g. v2)"
                        onChange={(event) => setVersionDraft((prev) => ({ ...prev, label: event.target.value }))}
                        style={{ padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none' }}
                      />
                      <textarea
                        value={versionDraft.notes}
                        placeholder="Notes"
                        onChange={(event) => setVersionDraft((prev) => ({ ...prev, notes: event.target.value }))}
                        rows={3}
                        style={{ padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none', resize: 'vertical' }}
                      />
                      <input
                        type="file"
                        onChange={(event) =>
                          setVersionDraft((prev) => ({ ...prev, file: event.target.files?.[0] || null }))
                        }
                        style={{ color: theme.mutedTextColor }}
                      />
                      <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                        <button
                          type="submit"
                          disabled={savingVersion}
                          style={{
                            background: theme.accentColor,
                            color: '#fff',
                            padding: '0.35rem 0.75rem',
                            borderRadius: theme.borderRadius,
                            border: 'none',
                            cursor: 'pointer',
                          }}
                        >
                          {savingVersion ? 'Saving…' : 'Add version'}
                        </button>
                        {versionError && <span style={{ color: '#fca5a5' }}>{versionError}</span>}
                      </div>
                    </form>
                  )}
                </div>
              </div>
            )}
          </article>
        ))}
      </div>
    </section>
  );
};

export default DocumentsPanel;
