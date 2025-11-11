import React, { useEffect, useMemo, useState } from 'react';

const overlayStyle = {
  position: 'fixed',
  top: 0,
  left: 0,
  right: 0,
  bottom: 0,
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  background: 'rgba(15,23,42,0.88)',
  zIndex: 40,
  padding: '1.5rem',
};

const GuidedTour = ({ tour, theme, onCompleteStep, onDismiss }) => {
  const steps = useMemo(() => (Array.isArray(tour?.steps) ? tour.steps : []), [tour?.steps]);
  const completed = useMemo(() => new Set(Array.isArray(tour?.completed) ? tour.completed : []), [tour?.completed]);
  const remaining = useMemo(() => steps.filter((step) => step && !completed.has(step.id)), [steps, completed]);
  const [index, setIndex] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (remaining.length === 0) {
      setIndex(0);
      return;
    }

    if (index >= remaining.length) {
      setIndex(0);
    }
  }, [remaining, index]);

  if (!tour?.enabled || tour.dismissed || remaining.length === 0) {
    return null;
  }

  const step = remaining[index] || remaining[0];

  const handleComplete = async () => {
    if (!step || !onCompleteStep) {
      return;
    }

    setSubmitting(true);
    setError('');

    try {
      await onCompleteStep(step.id);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to update tour');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDismiss = async () => {
    if (!tour?.dismissible || !onDismiss) {
      return;
    }

    setSubmitting(true);
    setError('');

    try {
      await onDismiss();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to dismiss tour');
    } finally {
      setSubmitting(false);
    }
  };

  const handleNext = () => {
    if (remaining.length <= 1) {
      return;
    }

    setIndex((prev) => (prev + 1) % remaining.length);
    setError('');
  };

  return (
    <div style={overlayStyle} role="dialog" aria-modal="true" aria-label="Client portal guided tour">
      <div
        style={{
          background: theme.surfaceColor,
          color: theme.textColor,
          padding: '2rem',
          borderRadius: theme.borderRadius,
          width: '100%',
          maxWidth: '500px',
          boxShadow: '0 20px 45px rgba(15,23,42,0.45)',
        }}
      >
        <h2 style={{ margin: 0, fontFamily: theme.headingFont }}>{step.title}</h2>
        <p style={{ marginTop: '0.75rem', color: theme.mutedTextColor }}>{step.description}</p>

        {step.cta_url && (
          <p style={{ marginTop: '0.75rem' }}>
            <a
              href={step.cta_url}
              style={{
                color: theme.accentColor,
                textDecoration: 'underline',
              }}
              target="_blank"
              rel="noreferrer"
            >
              {step.cta_label || 'Open link'}
            </a>
          </p>
        )}

        {error && (
          <p style={{
            marginTop: '0.75rem',
            background: 'rgba(127,29,29,0.35)',
            color: '#fecaca',
            padding: '0.5rem 0.75rem',
            borderRadius: theme.borderRadius,
          }}>
            {error}
          </p>
        )}

        <div style={{ marginTop: '1.5rem', display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
          <button
            type="button"
            onClick={handleComplete}
            disabled={submitting}
            style={{
              background: theme.accentColor,
              color: '#fff',
              border: 'none',
              padding: '0.55rem 1.1rem',
              borderRadius: theme.borderRadius,
              cursor: submitting ? 'wait' : 'pointer',
            }}
          >
            {submitting ? 'Saving…' : 'Mark complete'}
          </button>

          {remaining.length > 1 && (
            <button
              type="button"
              onClick={handleNext}
              disabled={submitting}
              style={{
                background: 'transparent',
                border: '1px solid rgba(255,255,255,0.25)',
                color: theme.textColor,
                padding: '0.55rem 1.1rem',
                borderRadius: theme.borderRadius,
                cursor: submitting ? 'wait' : 'pointer',
              }}
            >
              Next tip
            </button>
          )}

          {tour.dismissible && (
            <button
              type="button"
              onClick={handleDismiss}
              disabled={submitting}
              style={{
                background: 'transparent',
                border: 'none',
                color: theme.mutedTextColor,
                padding: '0.55rem 0.75rem',
                cursor: submitting ? 'wait' : 'pointer',
                marginLeft: 'auto',
              }}
            >
              Skip tour
            </button>
          )}
        </div>

        <p style={{ marginTop: '1rem', color: theme.mutedTextColor, fontSize: '0.9rem' }}>
          Step {steps.findIndex((item) => item.id === step.id) + 1} of {steps.length}
        </p>
      </div>
    </div>
  );
};

export default GuidedTour;
