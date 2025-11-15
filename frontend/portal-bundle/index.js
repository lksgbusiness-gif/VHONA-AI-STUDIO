(function () {
    'use strict';

    if (typeof window === 'undefined') {
        return;
    }

    const config = window.vhonaClientPortal || null;
    const root = document.getElementById('vhona-client-portal-app');

    if (!config || !root) {
        console.warn('VHONA client portal boot payload missing.');
        return;
    }

    const restBase = (config.restUrl || '').replace(/\/$/, '');
    const capabilities = Object.assign({ manageClientPortal: false }, config.capabilities || {});
    const portalUserHasAccess = true;
    const nonces = Object.assign({
        documentsCreate: null,
        documentsChunk: null,
        documentsVersion: null,
        documentsApprove: null,
        tasksCreate: null,
        tasksUpdate: null,
        conversationCreate: null,
        conversationReply: null,
        integrationsRefresh: null,
        tourUpdate: null
    }, config.nonces || {});

    const state = {
        tab: 'projects',
        data: {
            projects: { items: [], loading: false, loaded: false, error: null },
            documents: { items: [], loading: false, loaded: false, error: null },
            tasks: { items: [], loading: false, loaded: false, error: null },
            conversations: { items: [], loading: false, loaded: false, error: null },
            activity: { items: [], loading: false, loaded: false, error: null },
            analytics: { item: null, loading: false, loaded: false, error: null },
            integrations: { item: null, loading: false, loaded: false, error: null }
        },
        conversationMessages: {},
        uploads: {},
        initialised: false
    };

    const toastContainer = document.createElement('div');
    toastContainer.className = 'vhona-toast-container';
    document.body.appendChild(toastContainer);

    function applyTheme() {
        if (!config.theme) {
            return;
        }

        const theme = config.theme;
        const target = root.style;
        if (theme.accent_color) {
            target.setProperty('--vhona-accent', theme.accent_color);
        }
        if (theme.surface_color) {
            target.setProperty('--vhona-bg', theme.surface_color);
        }
        if (theme.border_radius) {
            root.style.borderRadius = parseInt(theme.border_radius, 10) + 'px';
        }
        if (theme.text_color) {
            root.style.color = theme.text_color;
        }
        if (theme.muted_text_color) {
            root.style.setProperty('--vhona-muted', theme.muted_text_color);
        }
        if (theme.body_font) {
            root.style.setProperty('--vhona-font-family', theme.body_font);
        }
    }

    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'vhona-toast' + (type === 'error' ? ' is-error' : '');
        toast.textContent = message;
        toastContainer.appendChild(toast);
        setTimeout(function () {
            toast.classList.add('vhona-toast-leave');
            toast.remove();
        }, 4000);
    }

    function createEl(tag, attrs) {
        const el = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                if (key === 'className') {
                    el.className = attrs[key];
                } else if (key === 'text') {
                    el.textContent = attrs[key];
                } else if (key === 'html') {
                    el.innerHTML = attrs[key];
                } else if (key === 'dataset') {
                    Object.keys(attrs.dataset).forEach(function (dataKey) {
                        el.dataset[dataKey] = attrs.dataset[dataKey];
                    });
                } else if (key === 'on') {
                    Object.keys(attrs.on).forEach(function (eventName) {
                        el.addEventListener(eventName, attrs.on[eventName]);
                    });
                } else if (key === 'attrs') {
                    Object.keys(attrs.attrs).forEach(function (attrKey) {
                        el.setAttribute(attrKey, attrs.attrs[attrKey]);
                    });
                } else {
                    el[key] = attrs[key];
                }
            });
        }
        for (let i = 2; i < arguments.length; i++) {
            const child = arguments[i];
            if (child === null || typeof child === 'undefined') {
                continue;
            }
            if (Array.isArray(child)) {
                child.forEach(function (nested) {
                    if (nested instanceof Node) {
                        el.appendChild(nested);
                    } else if (typeof nested === 'string') {
                        el.appendChild(document.createTextNode(nested));
                    }
                });
            } else if (child instanceof Node) {
                el.appendChild(child);
            } else if (typeof child === 'string' || typeof child === 'number') {
                el.appendChild(document.createTextNode(String(child)));
            }
        }
        return el;
    }

    function setTab(nextTab) {
        if (state.tab === nextTab) {
            return;
        }
        state.tab = nextTab;
        render();
        loadTab(nextTab);
    }

    function setLoading(key, loading) {
        state.data[key].loading = loading;
        render();
    }

    function setError(key, message) {
        state.data[key].error = message || null;
        render();
    }

    function apiRequest(path, options) {
        const opts = options || {};
        const url = restBase + path;
        const fetchOptions = {
            method: opts.method || 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        };

        const nonceKey = opts.nonceKey;
        if (nonceKey && nonces[nonceKey]) {
            fetchOptions.headers['X-WP-Nonce'] = nonces[nonceKey];
        } else if (config.nonce) {
            fetchOptions.headers['X-WP-Nonce'] = config.nonce;
        }

        if (opts.body) {
            fetchOptions.headers['Content-Type'] = 'application/json';
            fetchOptions.body = JSON.stringify(opts.body);
        }

        if (opts.formData) {
            delete fetchOptions.headers['Content-Type'];
            fetchOptions.body = opts.formData;
        }

        return fetch(url, fetchOptions).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (text) {
                    let message = text;
                    try {
                        const parsed = JSON.parse(text);
                        message = parsed.message || parsed.error || text;
                    } catch (err) {
                        // ignore
                    }
                    const error = new Error(message || 'Request failed');
                    error.status = response.status;
                    throw error;
                });
            }

            if (opts.raw) {
                return response;
            }

            const contentType = response.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                return response.json();
            }

            return response.text();
        });
    }

    function loadTab(tab) {
        switch (tab) {
            case 'projects':
                if (!state.data.projects.loaded && !state.data.projects.loading) {
                    fetchProjects();
                }
                break;
            case 'documents':
                if (!state.data.documents.loaded && !state.data.documents.loading) {
                    fetchDocuments();
                }
                break;
            case 'tasks':
                if (!state.data.tasks.loaded && !state.data.tasks.loading) {
                    fetchTasks();
                }
                break;
            case 'conversations':
                if (!state.data.conversations.loaded && !state.data.conversations.loading) {
                    fetchConversations();
                }
                break;
            case 'activity':
                if (!state.data.activity.loaded && !state.data.activity.loading) {
                    fetchActivity();
                }
                break;
            case 'analytics':
                if (!state.data.analytics.loaded && !state.data.analytics.loading) {
                    fetchAnalytics();
                }
                break;
            case 'integrations':
                if (!state.data.integrations.loaded && !state.data.integrations.loading) {
                    fetchIntegrations();
                }
                break;
            default:
                break;
        }
    }

    function fetchProjects() {
        setLoading('projects', true);
        setError('projects', null);
        apiRequest('/projects', { nonceKey: 'conversationReply' })
            .then(function (data) {
                state.data.projects.items = Array.isArray(data.projects) ? data.projects : [];
                state.data.projects.loaded = true;
            })
            .catch(function (error) {
                state.data.projects.error = error.message || 'Unable to load projects.';
                showToast(state.data.projects.error, 'error');
            })
            .finally(function () {
                setLoading('projects', false);
            });
    }

    function fetchDocuments() {
        setLoading('documents', true);
        setError('documents', null);
        apiRequest('/documents')
            .then(function (data) {
                state.data.documents.items = Array.isArray(data.documents) ? data.documents : [];
                state.data.documents.loaded = true;
            })
            .catch(function (error) {
                state.data.documents.error = error.message || 'Unable to load documents.';
                showToast(state.data.documents.error, 'error');
            })
            .finally(function () {
                setLoading('documents', false);
            });
    }

    function fetchTasks() {
        setLoading('tasks', true);
        setError('tasks', null);
        apiRequest('/tasks')
            .then(function (data) {
                state.data.tasks.items = Array.isArray(data.tasks) ? data.tasks : [];
                state.data.tasks.loaded = true;
            })
            .catch(function (error) {
                state.data.tasks.error = error.message || 'Unable to load tasks.';
                showToast(state.data.tasks.error, 'error');
            })
            .finally(function () {
                setLoading('tasks', false);
            });
    }

    function fetchConversations() {
        setLoading('conversations', true);
        setError('conversations', null);
        apiRequest('/conversations')
            .then(function (data) {
                state.data.conversations.items = Array.isArray(data.conversations) ? data.conversations : [];
                state.data.conversations.loaded = true;
            })
            .catch(function (error) {
                state.data.conversations.error = error.message || 'Unable to load conversations.';
                showToast(state.data.conversations.error, 'error');
            })
            .finally(function () {
                setLoading('conversations', false);
            });
    }

    function fetchActivity() {
        setLoading('activity', true);
        setError('activity', null);
        apiRequest('/activity')
            .then(function (data) {
                state.data.activity.items = Array.isArray(data.timeline) ? data.timeline : [];
                state.data.activity.loaded = true;
            })
            .catch(function (error) {
                state.data.activity.error = error.message || 'Unable to load activity.';
                showToast(state.data.activity.error, 'error');
            })
            .finally(function () {
                setLoading('activity', false);
            });
    }

    function fetchAnalytics() {
        setLoading('analytics', true);
        setError('analytics', null);
        apiRequest('/analytics/insights')
            .then(function (data) {
                state.data.analytics.item = data || {};
                state.data.analytics.loaded = true;
            })
            .catch(function (error) {
                state.data.analytics.error = error.message || 'Unable to load analytics.';
                showToast(state.data.analytics.error, 'error');
            })
            .finally(function () {
                setLoading('analytics', false);
            });
    }

    function fetchIntegrations(force) {
        setLoading('integrations', true);
        setError('integrations', null);
        const opts = force
            ? { method: 'POST', nonceKey: 'integrationsRefresh' }
            : {};
        const path = force ? '/integrations/refresh' : '/integrations/overview';
        apiRequest(path, opts)
            .then(function (data) {
                state.data.integrations.item = data || {};
                state.data.integrations.loaded = true;
            })
            .catch(function (error) {
                state.data.integrations.error = error.message || 'Unable to load integrations.';
                showToast(state.data.integrations.error, 'error');
            })
            .finally(function () {
                setLoading('integrations', false);
            });
    }

    function handleDocumentUpload(form) {
        const titleInput = form.querySelector('input[name="title"]');
        const fileInput = form.querySelector('input[type="file"]');
        if (!titleInput || !fileInput || !fileInput.files.length) {
            showToast('Document title and file are required.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('title', titleInput.value);
        formData.append('file', fileInput.files[0]);

        setLoading('documents', true);
        apiRequest('/documents', {
            method: 'POST',
            formData: formData,
            nonceKey: 'documentsCreate'
        })
            .then(function (documentItem) {
                state.data.documents.items.unshift(documentItem);
                showToast('Document uploaded successfully.');
                form.reset();
            })
            .catch(function (error) {
                showToast(error.message || 'Unable to upload document.', 'error');
            })
            .finally(function () {
                fetchDocuments();
            });
    }

    function handleDocumentDownload(documentItem, button) {
        button.disabled = true;
        apiRequest('/documents/' + documentItem.id + '/download', { raw: true })
            .then(function (response) {
                const contentType = response.headers.get('content-type') || '';
                if (contentType.indexOf('application/json') !== -1) {
                    return response.json().then(function (payload) {
                        if (payload && payload.url) {
                            window.open(payload.url, '_blank');
                        }
                    });
                }

                return response.blob().then(function (blob) {
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = documentItem.title || 'document';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                });
            })
            .catch(function (error) {
                showToast(error.message || 'Unable to download document.', 'error');
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    function fetchDocumentAnalytics(id, container) {
        container.innerHTML = '<div class="vhona-loading">Loading analytics…</div>';
        apiRequest('/documents/' + id + '/analytics', { nonceKey: 'documentsVersion' })
            .then(function (data) {
                const total = data && data.downloads ? data.downloads.total : 0;
                const recent = data && data.downloads ? data.downloads.last_30_days : 0;
                const table = createEl('div', { className: 'vhona-meta' },
                    createEl('div', { className: 'vhona-pill', text: total + ' total downloads' }),
                    createEl('div', { className: 'vhona-pill', text: recent + ' in last 30 days' })
                );
                container.innerHTML = '';
                container.appendChild(table);
            })
            .catch(function (error) {
                container.innerHTML = '';
                container.appendChild(createEl('div', { className: 'vhona-error', text: error.message || 'Unable to load analytics.' }));
            });
    }

    function fetchDocumentVersions(id, container) {
        container.innerHTML = '<div class="vhona-loading">Loading versions…</div>';
        apiRequest('/documents/' + id + '/versions')
            .then(function (data) {
                const versions = Array.isArray(data.versions) ? data.versions : [];
                if (!versions.length) {
                    container.innerHTML = '';
                    container.appendChild(createEl('div', { className: 'vhona-empty', text: 'No document versions found.' }));
                    return;
                }
                const list = createEl('div', { className: 'vhona-list' });
                versions.forEach(function (version) {
                    const actions = [];
                    if (capabilities.manageClientPortal && version.status !== 'approved') {
                        const approveButton = createEl('button', {
                            className: 'vhona-button',
                            text: 'Approve version'
                        });
                        approveButton.addEventListener('click', function () {
                            approveButton.disabled = true;
                            apiRequest('/documents/' + id + '/versions/' + encodeURIComponent(version.id) + '/approve', {
                                method: 'POST',
                                nonceKey: 'documentsChunk'
                            })
                                .then(function () {
                                    showToast('Document version approved.');
                                    fetchDocumentVersions(id, container);
                                })
                                .catch(function (error) {
                                    showToast(error.message || 'Unable to approve version.', 'error');
                                })
                                .finally(function () {
                                    approveButton.disabled = false;
                                });
                        });
                        actions.push(approveButton);
                    }

                    const versionMeta = createEl('div', { className: 'vhona-meta' },
                        createEl('span', { className: 'vhona-pill', text: 'Status: ' + (version.status || 'pending') }),
                        version.created_at ? createEl('span', { text: 'Created ' + new Date(version.created_at).toLocaleString() }) : null
                    );

                    const versionNode = createEl('div', { className: 'vhona-list-item' },
                        createEl('h3', { text: version.label || version.id }),
                        version.notes ? createEl('p', { text: version.notes }) : null,
                        versionMeta,
                        actions.length ? createEl('div', { className: 'vhona-actions' }, actions) : null
                    );
                    list.appendChild(versionNode);
                });
                container.innerHTML = '';
                container.appendChild(list);
            })
            .catch(function (error) {
                container.innerHTML = '';
                container.appendChild(createEl('div', { className: 'vhona-error', text: error.message || 'Unable to load versions.' }));
            });
    }

    function handleTaskCreate(form) {
        const title = form.querySelector('input[name="task_title"]').value;
        const description = form.querySelector('textarea[name="task_description"]').value;
        const dueAt = form.querySelector('input[name="task_due"]') ? form.querySelector('input[name="task_due"]').value : '';
        if (!title) {
            showToast('Task title is required.', 'error');
            return;
        }
        const payload = {
            title: title,
            description: description,
            due_at: dueAt
        };
        const projectSelect = form.querySelector('select[name="task_project"]');
        if (projectSelect && projectSelect.value) {
            payload.project_id = parseInt(projectSelect.value, 10);
        }
        apiRequest('/tasks', {
            method: 'POST',
            nonceKey: 'tasksCreate',
            body: payload
        })
            .then(function () {
                showToast('Task created successfully.');
                form.reset();
                fetchTasks();
            })
            .catch(function (error) {
                showToast(error.message || 'Unable to create task.', 'error');
            });
    }

    function handleTaskStatusChange(task, select) {
        const value = select.value;
        apiRequest('/tasks/' + task.id, {
            method: 'PATCH',
            nonceKey: 'tasksUpdate',
            body: { status: value }
        })
            .then(function (updated) {
                showToast('Task updated.');
                task.status = updated.status;
            })
            .catch(function (error) {
                showToast(error.message || 'Unable to update task.', 'error');
                select.value = task.status;
            });
    }

    function handleConversationCreate(form) {
        const title = form.querySelector('input[name="conversation_title"]').value;
        const message = form.querySelector('textarea[name="conversation_message"]').value;
        if (!title || !message) {
            showToast('Conversation title and message are required.', 'error');
            return;
        }
        const payload = { title: title, message: message };
        const project = form.querySelector('select[name="conversation_project"]');
        if (project && project.value) {
            payload.project_id = parseInt(project.value, 10);
        }
        apiRequest('/conversations', {
            method: 'POST',
            nonceKey: 'conversationCreate',
            body: payload
        })
            .then(function (response) {
                showToast('Conversation created.');
                form.reset();
                state.data.conversations.items.unshift(response);
                render();
            })
            .catch(function (error) {
                showToast(error.message || 'Unable to create conversation.', 'error');
            });
    }

    function ensureConversationMessages(conversationId, container) {
        const cache = state.conversationMessages[conversationId];
        if (cache && cache.loaded && !cache.refresh) {
            renderConversationMessages(conversationId, container, cache.items);
            return;
        }

        container.innerHTML = '<div class="vhona-loading">Loading messages…</div>';
        apiRequest('/conversations/' + conversationId)
            .then(function (data) {
                const messages = Array.isArray(data.messages) ? data.messages : [];
                state.conversationMessages[conversationId] = {
                    items: messages,
                    loaded: true,
                    refresh: false
                };
                renderConversationMessages(conversationId, container, messages);
            })
            .catch(function (error) {
                container.innerHTML = '';
                container.appendChild(createEl('div', { className: 'vhona-error', text: error.message || 'Unable to load messages.' }));
            });
    }

    function renderConversationMessages(conversationId, container, messages) {
        if (!messages.length) {
            container.innerHTML = '';
            container.appendChild(createEl('div', { className: 'vhona-empty', text: 'No messages yet.' }));
            return;
        }
        const list = createEl('div', { className: 'vhona-messages' });
        messages.forEach(function (message) {
            list.appendChild(createEl('div', { className: 'vhona-message', html: message.message + '<time>' + new Date(message.created_at).toLocaleString() + '</time>' }));
        });
        container.innerHTML = '';
        container.appendChild(list);
    }

    function handleConversationReply(conversationId, textarea) {
        const message = textarea.value.trim();
        if (!message) {
            showToast('Message cannot be empty.', 'error');
            return;
        }
        apiRequest('/conversations/' + conversationId + '/messages', {
            method: 'POST',
            body: { message: message }
        })
            .then(function (data) {
                showToast('Reply posted.');
                textarea.value = '';
                const messages = Array.isArray(data.messages) ? data.messages : [];
                state.conversationMessages[conversationId] = {
                    items: messages,
                    loaded: true,
                    refresh: false
                };
                render();
            })
            .catch(function (error) {
                showToast(error.message || 'Unable to send message.', 'error');
            });
    }

    function renderHeader() {
        const left = createEl('div', null,
            createEl('div', { className: 'vhona-portal-title', text: 'Client portal' }),
            config.theme && config.theme.logo_url ? createEl('img', {
                attrs: { src: config.theme.logo_url, alt: 'Portal logo' },
                className: 'vhona-portal-logo'
            }) : null
        );

        const right = createEl('div', { className: 'vhona-portal-user', text: config.user && config.user.name ? 'Signed in as ' + config.user.name : '' });
        const header = createEl('div', { className: 'vhona-portal-header' }, left, right);
        return header;
    }

    const TABS = [
        { id: 'projects', label: 'Projects' },
        { id: 'documents', label: 'Documents' },
        { id: 'tasks', label: 'Tasks' },
        { id: 'conversations', label: 'Conversations' },
        { id: 'activity', label: 'Activity' },
        { id: 'analytics', label: 'Analytics' },
        { id: 'integrations', label: 'Integrations' }
    ];

    function renderTabs() {
        const nav = createEl('div', { className: 'vhona-tab-nav' });
        TABS.forEach(function (tab) {
            const button = createEl('button', {
                className: 'vhona-tab-button' + (state.tab === tab.id ? ' is-active' : ''),
                text: tab.label,
                on: {
                    click: function () {
                        setTab(tab.id);
                    }
                }
            });
            nav.appendChild(button);
        });
        return nav;
    }

    function renderProjectsPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Projects' }));
        if (state.data.projects.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.projects.error }));
        }
        if (state.data.projects.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading projects…' }));
            return panel;
        }
        if (!state.data.projects.items.length) {
            panel.appendChild(createEl('div', { className: 'vhona-empty', text: 'No projects available yet.' }));
            return panel;
        }
        const list = createEl('div', { className: 'vhona-list' });
        state.data.projects.items.forEach(function (project) {
            list.appendChild(createEl('div', { className: 'vhona-list-item' },
                createEl('h3', { text: project.title }),
                project.summary ? createEl('p', { text: project.summary }) : null,
                createEl('div', { className: 'vhona-meta', text: 'Updated ' + new Date(project.updated_at).toLocaleString() })
            ));
        });
        panel.appendChild(list);
        return panel;
    }

    function renderDocumentsPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Documents' }));
        if (state.data.documents.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.documents.error }));
        }
        if (state.data.documents.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading documents…' }));
            return panel;
        }
        if (capabilities.manageClientPortal) {
            const form = createEl('form', { className: 'vhona-form' });
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Document title' }),
                createEl('input', { name: 'title', required: true })
            ));
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Upload file' }),
                createEl('input', { type: 'file', required: true })
            ));
            form.appendChild(createEl('button', { type: 'submit', className: 'vhona-button', text: 'Upload document' }));
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                handleDocumentUpload(form);
            });
            panel.appendChild(form);
        }
        if (!state.data.documents.items.length) {
            panel.appendChild(createEl('div', { className: 'vhona-empty', text: 'No documents uploaded yet.' }));
            return panel;
        }
        const list = createEl('div', { className: 'vhona-list' });
        state.data.documents.items.forEach(function (documentItem) {
            const analyticsContainer = createEl('div');
            const versionContainer = createEl('div');
            const actions = [];
            const downloadButton = createEl('button', { className: 'vhona-button', text: 'Download' });
            downloadButton.addEventListener('click', function () {
                handleDocumentDownload(documentItem, downloadButton);
            });
            actions.push(downloadButton);
            if (capabilities.manageClientPortal) {
                const analyticsButton = createEl('button', { className: 'vhona-button secondary', text: 'View analytics' });
                analyticsButton.addEventListener('click', function () {
                    fetchDocumentAnalytics(documentItem.id, analyticsContainer);
                });
                const versionsButton = createEl('button', { className: 'vhona-button secondary', text: 'View versions' });
                versionsButton.addEventListener('click', function () {
                    fetchDocumentVersions(documentItem.id, versionContainer);
                });
                actions.push(analyticsButton, versionsButton);
            }
            const item = createEl('div', { className: 'vhona-list-item' },
                createEl('h3', { text: documentItem.title }),
                createEl('div', { className: 'vhona-meta', text: 'Updated ' + new Date(documentItem.updated_at).toLocaleString() }),
                createEl('div', { className: 'vhona-actions' }, actions),
                analyticsContainer,
                versionContainer
            );
            list.appendChild(item);
        });
        panel.appendChild(list);
        return panel;
    }

    function renderTasksPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Tasks' }));
        if (state.data.tasks.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.tasks.error }));
        }
        if (state.data.tasks.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading tasks…' }));
            return panel;
        }
        if (capabilities.manageClientPortal) {
            const form = createEl('form', { className: 'vhona-form' });
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Task title' }),
                createEl('input', { name: 'task_title', required: true })
            ));
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Description' }),
                createEl('textarea', { name: 'task_description', rows: 3 })
            ));
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Due date' }),
                createEl('input', { name: 'task_due', type: 'date' })
            ));
            if (state.data.projects.items.length) {
                const select = createEl('select', { name: 'task_project' });
                select.appendChild(createEl('option', { value: '', text: 'No project' }));
                state.data.projects.items.forEach(function (project) {
                    select.appendChild(createEl('option', { value: project.id, text: project.title }));
                });
                form.appendChild(createEl('label', null,
                    createEl('span', { text: 'Project' }),
                    select
                ));
            }
            form.appendChild(createEl('button', { type: 'submit', className: 'vhona-button', text: 'Create task' }));
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                handleTaskCreate(form);
            });
            panel.appendChild(form);
        }
        if (!state.data.tasks.items.length) {
            panel.appendChild(createEl('div', { className: 'vhona-empty', text: 'No tasks yet.' }));
            return panel;
        }
        const list = createEl('div', { className: 'vhona-list' });
        state.data.tasks.items.forEach(function (task) {
            const statusSelect = capabilities.manageClientPortal ? createEl('select', { value: task.status }) : null;
            if (statusSelect) {
                ['open', 'in_progress', 'blocked', 'completed'].forEach(function (status) {
                    const option = createEl('option', { value: status, text: status.replace('_', ' ') });
                    if (status === task.status) {
                        option.selected = true;
                    }
                    statusSelect.appendChild(option);
                });
                statusSelect.addEventListener('change', function () {
                    handleTaskStatusChange(task, statusSelect);
                });
            }
            list.appendChild(createEl('div', { className: 'vhona-list-item' },
                createEl('h3', { text: task.title }),
                task.description ? createEl('p', { text: task.description }) : null,
                createEl('div', { className: 'vhona-meta' },
                    createEl('span', { text: 'Status: ' + task.status }),
                    task.due_at ? createEl('span', { text: 'Due ' + new Date(task.due_at).toLocaleDateString() }) : null
                ),
                statusSelect ? createEl('div', { className: 'vhona-actions' }, statusSelect) : null
            ));
        });
        panel.appendChild(list);
        return panel;
    }

    function renderConversationsPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Conversations' }));
        if (state.data.conversations.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.conversations.error }));
        }
        if (state.data.conversations.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading conversations…' }));
            return panel;
        }
        if (capabilities.manageClientPortal || portalUserHasAccess) {
            const form = createEl('form', { className: 'vhona-form' });
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Conversation title' }),
                createEl('input', { name: 'conversation_title', required: true })
            ));
            form.appendChild(createEl('label', null,
                createEl('span', { text: 'Initial message' }),
                createEl('textarea', { name: 'conversation_message', rows: 3, required: true })
            ));
            if (state.data.projects.items.length) {
                const select = createEl('select', { name: 'conversation_project' });
                select.appendChild(createEl('option', { value: '', text: 'No project' }));
                state.data.projects.items.forEach(function (project) {
                    select.appendChild(createEl('option', { value: project.id, text: project.title }));
                });
                form.appendChild(createEl('label', null,
                    createEl('span', { text: 'Linked project' }),
                    select
                ));
            }
            form.appendChild(createEl('button', { type: 'submit', className: 'vhona-button', text: 'Start conversation' }));
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                handleConversationCreate(form);
            });
            panel.appendChild(form);
        }
        if (!state.data.conversations.items.length) {
            panel.appendChild(createEl('div', { className: 'vhona-empty', text: 'No conversations yet.' }));
            return panel;
        }
        const list = createEl('div', { className: 'vhona-list' });
        state.data.conversations.items.forEach(function (conversation) {
            const messagesContainer = createEl('div');
            const toggle = createEl('button', { className: 'vhona-button secondary', text: 'View messages' });
            toggle.addEventListener('click', function () {
                ensureConversationMessages(conversation.id, messagesContainer);
            });
            const replyForm = createEl('div');
            if (capabilities.manageClientPortal || portalUserHasAccess) {
                const textarea = createEl('textarea', { rows: 3, placeholder: 'Write a reply…' });
                const submit = createEl('button', { className: 'vhona-button', text: 'Send reply', type: 'button' });
                submit.addEventListener('click', function () {
                    handleConversationReply(conversation.id, textarea);
                });
                replyForm.appendChild(textarea);
                replyForm.appendChild(createEl('div', { className: 'vhona-actions' }, submit));
            }
            list.appendChild(createEl('div', { className: 'vhona-list-item' },
                createEl('h3', { text: conversation.title }),
                conversation.message ? createEl('p', { text: conversation.message }) : null,
                conversation.last_reply ? createEl('div', { className: 'vhona-meta', text: 'Last reply ' + new Date(conversation.last_reply.created_at).toLocaleString() }) : null,
                createEl('div', { className: 'vhona-actions' }, toggle),
                messagesContainer,
                replyForm
            ));
        });
        panel.appendChild(list);
        return panel;
    }

    function renderActivityPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Recent activity' }));
        if (state.data.activity.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.activity.error }));
        }
        if (state.data.activity.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading activity…' }));
            return panel;
        }
        if (!state.data.activity.items.length) {
            panel.appendChild(createEl('div', { className: 'vhona-empty', text: 'No activity recorded yet.' }));
            return panel;
        }
        const list = createEl('div', { className: 'vhona-list' });
        state.data.activity.items.forEach(function (entry) {
            list.appendChild(createEl('div', { className: 'vhona-list-item' },
                createEl('h3', { text: entry.type.replace(/_/g, ' ') }),
                createEl('p', { text: entry.message }),
                createEl('div', { className: 'vhona-meta', text: new Date(entry.created_at).toLocaleString() })
            ));
        });
        panel.appendChild(list);
        return panel;
    }

    function renderAnalyticsPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Insights' }));
        if (state.data.analytics.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.analytics.error }));
        }
        if (state.data.analytics.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading analytics…' }));
            return panel;
        }
        const insights = state.data.analytics.item || {};
        const summary = createEl('div', { className: 'vhona-analytics-summary' });
        const logins = insights.logins || {};
        const documents = insights.documents || {};
        summary.appendChild(createEl('div', { className: 'vhona-analytics-card' },
            createEl('h4', { text: 'Logins (30 days)' }),
            createEl('p', { text: String(logins.last_30_days || 0) })
        ));
        summary.appendChild(createEl('div', { className: 'vhona-analytics-card' },
            createEl('h4', { text: 'Total logins' }),
            createEl('p', { text: String(logins.total || 0) })
        ));
        summary.appendChild(createEl('div', { className: 'vhona-analytics-card' },
            createEl('h4', { text: 'Downloads (30 days)' }),
            createEl('p', { text: String(documents.downloads_last_30_days || 0) })
        ));
        summary.appendChild(createEl('div', { className: 'vhona-analytics-card' },
            createEl('h4', { text: 'Total downloads' }),
            createEl('p', { text: String(documents.total_downloads || 0) })
        ));
        panel.appendChild(summary);

        const checklist = insights.setup || [];
        if (Array.isArray(checklist) && checklist.length) {
            const table = createEl('table', { className: 'vhona-table' });
            const head = createEl('thead');
            head.appendChild(createEl('tr', null,
                createEl('th', { text: 'Setup step' }),
                createEl('th', { text: 'Status' }),
                createEl('th', { text: 'Details' })
            ));
            table.appendChild(head);
            const body = createEl('tbody');
            checklist.forEach(function (step) {
                body.appendChild(createEl('tr', null,
                    createEl('td', { text: step.label }),
                    createEl('td', { text: step.completed ? 'Complete' : 'Pending' }),
                    createEl('td', { text: step.description })
                ));
            });
            table.appendChild(body);
            panel.appendChild(table);
        }
        return panel;
    }

    function renderIntegrationsPanel() {
        const panel = createEl('div', { className: 'vhona-panel' }, createEl('h2', { text: 'Integrations' }));
        if (state.data.integrations.error) {
            panel.appendChild(createEl('div', { className: 'vhona-error', text: state.data.integrations.error }));
        }
        if (state.data.integrations.loading) {
            panel.appendChild(createEl('div', { className: 'vhona-loading', text: 'Loading integrations…' }));
            return panel;
        }
        const actions = [];
        if (capabilities.manageClientPortal) {
            const refreshButton = createEl('button', { className: 'vhona-button', text: 'Refresh snapshot' });
            refreshButton.addEventListener('click', function () {
                fetchIntegrations(true);
            });
            actions.push(refreshButton);
        }
        if (actions.length) {
            panel.appendChild(createEl('div', { className: 'vhona-actions' }, actions));
        }
        const data = state.data.integrations.item || {};
        const grid = createEl('div', { className: 'vhona-grid two-columns' });
        grid.appendChild(renderIntegrationCard('FastAPI', data.fastapi));
        grid.appendChild(renderIntegrationCard('CRM', data.crm));
        grid.appendChild(renderIntegrationCard('Billing', data.billing));
        grid.appendChild(renderIntegrationCard('Calendar', data.calendar));
        if (data.cached_at) {
            panel.appendChild(createEl('p', { className: 'vhona-meta', text: 'Last refreshed ' + new Date(data.cached_at).toLocaleString() }));
        }
        panel.appendChild(grid);
        return panel;
    }

    function renderIntegrationCard(title, payload) {
        const card = createEl('div', { className: 'vhona-list-item' },
            createEl('h3', { text: title }),
            payload && payload.error ? createEl('div', { className: 'vhona-error', text: payload.error }) : null,
            payload && payload.enabled === false ? createEl('p', { text: 'Disabled' }) : null
        );
        if (payload && typeof payload === 'object') {
            const list = createEl('ul');
            Object.keys(payload).forEach(function (key) {
                if (key === 'error') {
                    return;
                }
                const value = payload[key];
                if (typeof value === 'object') {
                    return;
                }
                list.appendChild(createEl('li', { text: key + ': ' + value }));
            });
            if (list.childNodes.length) {
                card.appendChild(list);
            }
        }
        return card;
    }

    function renderContent() {
        switch (state.tab) {
            case 'projects':
                return renderProjectsPanel();
            case 'documents':
                return renderDocumentsPanel();
            case 'tasks':
                return renderTasksPanel();
            case 'conversations':
                return renderConversationsPanel();
            case 'activity':
                return renderActivityPanel();
            case 'analytics':
                return renderAnalyticsPanel();
            case 'integrations':
                return renderIntegrationsPanel();
            default:
                return createEl('div', { className: 'vhona-panel', text: 'Select a section to begin.' });
        }
    }

    function render() {
        root.innerHTML = '';
        root.appendChild(renderHeader());
        root.appendChild(renderTabs());
        root.appendChild(renderContent());
    }

    applyTheme();
    render();
    loadTab(state.tab);
})();
