import { useCallback, useEffect, useMemo, useState } from 'react';

const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB

const getBootData = () => window.vhonaClientPortal || {};

const buildUrl = (path) => {
  const base = getBootData().restUrl?.replace(/\/$/, '') || '';
  const normalised = path.startsWith('/') ? path : `/${path}`;
  return `${base}${normalised}`;
};

const defaultTour = {
  enabled: false,
  dismissible: true,
  steps: [],
  completed: [],
  dismissed: false,
};

const defaultState = {
  projects: [],
  documents: [],
  tasks: [],
  conversations: [],
  timeline: [],
  integrations: {},
  insights: null,
  tour: defaultTour,
};

export default function usePortalApi() {
  const boot = getBootData();
  const bootTour = boot.guidedTour ? { ...defaultTour, ...boot.guidedTour } : defaultTour;
  const [state, setState] = useState({ ...defaultState, tour: bootTour });
  const [documentDetails, setDocumentDetails] = useState({});
  const [conversationMessages, setConversationMessages] = useState({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [analyticsError, setAnalyticsError] = useState(null);
  const [notice, setNotice] = useState(null);
  const isManager = Boolean(boot.capabilities?.manageClientPortal);

  const authHeaders = useMemo(
    () => ({
      'X-WP-Nonce': boot.nonce,
    }),
    [boot.nonce]
  );

  const callApi = useCallback(
    async (path, options = {}) => {
      if (!boot.restUrl) {
        throw new Error('Portal not configured');
      }

      const { nonceKey, headers: extraHeaders, ...rest } = options;
      const headers = {
        ...authHeaders,
        ...(extraHeaders || {}),
      };

      if (nonceKey && boot.nonces?.[nonceKey]) {
        headers['X-Portal-Nonce'] = boot.nonces[nonceKey];
      }

      const response = await fetch(buildUrl(path), {
        credentials: 'include',
        ...rest,
        headers,
      });

      if (!response.ok) {
        let message = '';
        let raw = '';

        try {
          raw = await response.text();
        } catch (err) {
          raw = '';
        }

        if (raw) {
          const responseType = response.headers.get('Content-Type') || '';
          if (responseType.includes('application/json')) {
            try {
              const payload = JSON.parse(raw);
              message = payload?.message || payload?.data?.message || '';
            } catch (err) {
              message = raw;
            }
          } else {
            message = raw;
          }
        }

        if (!message && response.status === 403) {
          message = 'You do not have access to the client portal yet.';
        }

        throw new Error(message || `Request failed (${response.status})`);
      }

      if (response.status === 204) {
        return null;
      }

      const contentType = response.headers.get('Content-Type') || '';
      if (contentType.includes('application/json')) {
        return response.json();
      }

      return response.text();
    },
    [authHeaders, boot.nonces, boot.restUrl]
  );

  const normaliseTour = useCallback(
    (payload) => ({
      ...defaultTour,
      ...(payload || {}),
    }),
    []
  );

  const fetchAll = useCallback(async () => {
    if (!boot.restUrl) {
      return;
    }

    setLoading(true);
    setError(null);
    setAnalyticsError(null);

    try {
      const tourPromise = isManager ? callApi('/tour') : Promise.resolve(null);
      const [projects, documents, tasks, conversations, timeline, integrations, tourPayload] = await Promise.all([
        callApi('/projects'),
        callApi('/documents'),
        callApi('/tasks'),
        callApi('/conversations'),
        callApi('/activity'),
        callApi('/integrations/overview'),
        tourPromise,
      ]);

      let insights = null;
      if (isManager) {
        try {
          insights = await callApi('/analytics/insights');
        } catch (analyticsError) {
          insights = null;
          setAnalyticsError(analyticsError instanceof Error ? analyticsError.message : 'Unable to load analytics');
        }
      }

      setState((prev) => ({
        projects: projects?.projects || [],
        documents: documents?.documents || [],
        tasks: tasks?.tasks || [],
        conversations: conversations?.conversations || [],
        timeline: timeline?.timeline || [],
        integrations: integrations || {},
        insights: insights,
        tour: normaliseTour(tourPayload || prev.tour),
      }));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unexpected error');
    } finally {
      setLoading(false);
    }
  }, [boot.restUrl, callApi, isManager, normaliseTour]);

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  useEffect(() => {
    if (!notice) {
      return undefined;
    }

    const timeout = setTimeout(() => {
      setNotice(null);
    }, 5000);

    return () => clearTimeout(timeout);
  }, [notice]);

  const refresh = useCallback(() => {
    fetchAll();
  }, [fetchAll]);

  const uploadWithChunks = useCallback(
    async (file) => {
      let uploadId = '';
      for (let offset = 0; offset < file.size; offset += CHUNK_SIZE) {
        const chunk = file.slice(offset, Math.min(offset + CHUNK_SIZE, file.size));
        const form = new FormData();
        form.append('chunk', chunk, file.name);
        form.append('name', file.name);
        form.append('index', String(Math.floor(offset / CHUNK_SIZE)));
        if (uploadId) {
          form.append('upload_id', uploadId);
        }
        if (offset + CHUNK_SIZE >= file.size) {
          form.append('is_last', '1');
        }

        const chunkHeaders = {
          ...authHeaders,
        };
        if (boot.nonces?.documentsChunk) {
          chunkHeaders['X-Portal-Nonce'] = boot.nonces.documentsChunk;
        }

        const response = await fetch(buildUrl('/documents/upload/chunk'), {
          method: 'POST',
          credentials: 'include',
          headers: chunkHeaders,
          body: form,
        });

        if (!response.ok) {
          const message = await response.text();
          throw new Error(message || 'Chunk upload failed');
        }

        const payload = await response.json();
        uploadId = payload.uploadId;
      }

      if (!uploadId) {
        throw new Error('Upload failed');
      }

      return uploadId;
    },
    [authHeaders, boot.nonces]
  );

  const uploadDocument = useCallback(
    async (title, file) => {
      if (!boot.restUrl) {
        return null;
      }

      const shouldChunk = file.size > CHUNK_SIZE;
      const form = new FormData();
      form.append('title', title || file.name);

      if (shouldChunk) {
        const uploadId = await uploadWithChunks(file);
        form.append('upload_id', uploadId);
      } else {
        form.append('file', file);
      }

      const payload = await callApi('/documents', {
        method: 'POST',
        body: form,
        nonceKey: 'documentsCreate',
      });
      setState((prev) => ({
        ...prev,
        documents: [payload, ...prev.documents],
      }));
      return payload;
    },
    [boot.restUrl, callApi, uploadWithChunks]
  );

  const fetchDocumentDetails = useCallback(
    async (documentId) => {
      let analytics = null;
      let versions = [];

      try {
        analytics = await callApi(`/documents/${documentId}/analytics`);
      } catch (err) {
        analytics = null;
      }

      try {
        const payload = await callApi(`/documents/${documentId}/versions`);
        versions = payload?.versions || [];
      } catch (err) {
        versions = [];
      }

      setDocumentDetails((prev) => ({
        ...prev,
        [documentId]: {
          analytics,
          versions,
        },
      }));
    },
    [callApi]
  );

  const createDocumentVersion = useCallback(
    async (documentId, { label, notes, file }) => {
      let uploadId = null;
      const form = new FormData();
      if (label) {
        form.append('label', label);
      }
      if (notes) {
        form.append('notes', notes);
      }

      if (file) {
        if (file.size > CHUNK_SIZE) {
          uploadId = await uploadWithChunks(file);
          form.append('upload_id', uploadId);
        } else {
          form.append('file', file);
        }
      }

      const payload = await callApi(`/documents/${documentId}/versions`, {
        method: 'POST',
        body: form,
        nonceKey: 'documentsVersion',
      });
      setDocumentDetails((prev) => {
        const previous = prev[documentId] || { analytics: null, versions: [] };
        return {
          ...prev,
          [documentId]: {
            ...previous,
            versions: [payload, ...(previous.versions || [])],
          },
        };
      });
      return payload;
    },
    [callApi, uploadWithChunks]
  );

  const approveDocumentVersion = useCallback(
    async (documentId, versionId) => {
      const payload = await callApi(`/documents/${documentId}/versions/${versionId}/approve`, {
        method: 'POST',
        nonceKey: 'documentsApprove',
      });

      setDocumentDetails((prev) => {
        const previous = prev[documentId] || { analytics: null, versions: [] };
        const versions = (previous.versions || []).map((version) =>
          version.id === versionId ? payload : version
        );
        return {
          ...prev,
          [documentId]: {
            ...previous,
            versions,
          },
        };
      });

      return payload;
    },
    [callApi]
  );

  const createTask = useCallback(
    async (task) => {
      const payload = await callApi('/tasks', {
        method: 'POST',
        body: JSON.stringify(task),
        headers: {
          'Content-Type': 'application/json',
        },
        nonceKey: 'tasksCreate',
      });

      setState((prev) => ({
        ...prev,
        tasks: [payload, ...prev.tasks],
      }));
      return payload;
    },
    [callApi]
  );

  const updateTask = useCallback(
    async (taskId, updates) => {
      const payload = await callApi(`/tasks/${taskId}`, {
        method: 'PATCH',
        body: JSON.stringify(updates),
        headers: {
          'Content-Type': 'application/json',
        },
        nonceKey: 'tasksUpdate',
      });

      setState((prev) => ({
        ...prev,
        tasks: prev.tasks.map((task) => (task.id === taskId ? payload : task)),
      }));

      return payload;
    },
    [callApi]
  );

  const createConversation = useCallback(
    async (payload) => {
      const response = await callApi('/conversations', {
        method: 'POST',
        body: JSON.stringify(payload),
        headers: {
          'Content-Type': 'application/json',
        },
        nonceKey: 'conversationCreate',
      });

      setState((prev) => ({
        ...prev,
        conversations: [response, ...prev.conversations],
      }));
      return response;
    },
    [callApi]
  );

  const loadConversation = useCallback(
    async (conversationId) => {
      const payload = await callApi(`/conversations/${conversationId}`);
      setConversationMessages((prev) => ({
        ...prev,
        [conversationId]: payload?.messages || [],
      }));
      return payload;
    },
    [callApi]
  );

  const addConversationMessage = useCallback(
    async (conversationId, message) => {
      const payload = await callApi(`/conversations/${conversationId}/messages`, {
        method: 'POST',
        body: JSON.stringify({ message }),
        headers: {
          'Content-Type': 'application/json',
        },
        nonceKey: 'conversationReply',
      });

      setConversationMessages((prev) => ({
        ...prev,
        [conversationId]: payload?.messages || [],
      }));
      return payload;
    },
    [callApi]
  );

  const refreshIntegrations = useCallback(async () => {
    try {
      const payload = await callApi('/integrations/refresh', {
        method: 'POST',
        nonceKey: 'integrationsRefresh',
      });

      setState((prev) => ({
        ...prev,
        integrations: payload || {},
      }));

      setNotice({ type: 'success', message: 'Integration snapshot refreshed.' });
      return payload;
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Unable to refresh integrations';
      setNotice({ type: 'error', message });
      throw err;
    }
  }, [callApi]);

  const completeTourStep = useCallback(
    async (stepId) => {
      try {
        const payload = await callApi('/tour/progress', {
          method: 'POST',
          nonceKey: 'tourUpdate',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ action: 'complete', step: stepId }),
        });

        setState((prev) => ({
          ...prev,
          tour: normaliseTour(payload),
        }));
        setNotice({ type: 'success', message: 'Tour step marked complete.' });
        return payload;
      } catch (err) {
        const message = err instanceof Error ? err.message : 'Unable to update tour progress';
        setNotice({ type: 'error', message });
        throw err;
      }
    },
    [callApi, normaliseTour]
  );

  const dismissTour = useCallback(async () => {
    try {
      const payload = await callApi('/tour/progress', {
        method: 'POST',
        nonceKey: 'tourUpdate',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'dismiss' }),
      });

      setState((prev) => ({
        ...prev,
        tour: normaliseTour(payload),
      }));
      setNotice({ type: 'success', message: 'Tour dismissed.' });
      return payload;
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Unable to dismiss tour';
      setNotice({ type: 'error', message });
      throw err;
    }
  }, [callApi, normaliseTour]);

  const resetTour = useCallback(async () => {
    try {
      const payload = await callApi('/tour/progress', {
        method: 'POST',
        nonceKey: 'tourUpdate',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'reset' }),
      });

      setState((prev) => ({
        ...prev,
        tour: normaliseTour(payload),
      }));
      return payload;
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Unable to reset tour';
      setNotice({ type: 'error', message });
      throw err;
    }
  }, [callApi, normaliseTour]);

  const clearNotice = useCallback(() => {
    setNotice(null);
  }, []);

  return {
    ...state,
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
    completeTourStep,
    dismissTour,
    resetTour,
    notice,
    clearNotice,
  };
}
