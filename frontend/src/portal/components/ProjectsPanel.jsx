import React, { useMemo, useState } from 'react';

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

const statusLabels = {
  open: 'Open',
  in_progress: 'In progress',
  complete: 'Complete',
};

const ProjectsPanel = ({ projects, tasks, canManage, onCreateTask, onUpdateTask, theme }) => {
  const [taskDraft, setTaskDraft] = useState({ title: '', projectId: '', dueAt: '' });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const tasksByProject = useMemo(() => {
    return tasks.reduce((acc, task) => {
      const projectId = task.project_id || 'unassigned';
      if (!acc[projectId]) {
        acc[projectId] = [];
      }
      acc[projectId].push(task);
      return acc;
    }, {});
  }, [tasks]);

  const handleCreateTask = async (event) => {
    event.preventDefault();
    if (!taskDraft.title) {
      setError('Task title is required');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await onCreateTask({
        title: taskDraft.title,
        project_id: taskDraft.projectId ? Number(taskDraft.projectId) : undefined,
        due_at: taskDraft.dueAt || undefined,
      });
      setTaskDraft({ title: '', projectId: '', dueAt: '' });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to create task');
    } finally {
      setSaving(false);
    }
  };

  const toggleTaskStatus = (task) => {
    const nextStatus = task.status === 'complete' ? 'open' : 'complete';
    onUpdateTask(task.id, { status: nextStatus }).catch(() => {
      // ignore errors for now; optimistic update happens via API state refresh
    });
  };

  return (
    <section>
      <header style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ fontFamily: theme.headingFont, color: theme.textColor, marginBottom: '0.25rem' }}>Projects</h2>
          <p style={{ margin: 0, color: theme.mutedTextColor }}>Track deliverables and progress at a glance.</p>
        </div>
      </header>

      {canManage && (
        <form
          onSubmit={handleCreateTask}
          style={{
            marginTop: '1.5rem',
            background: theme.surfaceColor,
            borderRadius: theme.borderRadius,
            padding: '1rem',
            display: 'grid',
            gap: '0.5rem',
          }}
        >
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <input
              type="text"
              value={taskDraft.title}
              placeholder="New task title"
              onChange={(event) => setTaskDraft((prev) => ({ ...prev, title: event.target.value }))}
              style={{ flex: '1 1 200px', padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none' }}
            />
            <select
              value={taskDraft.projectId}
              onChange={(event) => setTaskDraft((prev) => ({ ...prev, projectId: event.target.value }))}
              style={{ flex: '0 0 160px', padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none' }}
            >
              <option value="">Assign to project</option>
              {projects.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.title}
                </option>
              ))}
            </select>
            <input
              type="date"
              value={taskDraft.dueAt}
              onChange={(event) => setTaskDraft((prev) => ({ ...prev, dueAt: event.target.value }))}
              style={{ flex: '0 0 160px', padding: '0.5rem', borderRadius: theme.borderRadius, border: 'none' }}
            />
            <button
              type="submit"
              disabled={saving}
              style={{
                background: theme.accentColor,
                color: '#fff',
                padding: '0 1rem',
                borderRadius: theme.borderRadius,
                border: 'none',
                cursor: 'pointer',
              }}
            >
              {saving ? 'Saving…' : 'Add task'}
            </button>
          </div>
          {error && <p style={{ color: '#fca5a5', margin: 0 }}>{error}</p>}
        </form>
      )}

      <div style={{ marginTop: '2rem', display: 'grid', gap: '1.5rem' }}>
        {projects.length === 0 && (
          <p style={{ color: theme.mutedTextColor }}>No projects yet.</p>
        )}

        {projects.map((project) => {
          const projectTasks = tasksByProject[project.id] || [];
          const completed = projectTasks.filter((task) => task.status === 'complete').length;
          const progress = projectTasks.length ? Math.round((completed / projectTasks.length) * 100) : 0;

          return (
            <article
              key={project.id}
              style={{
                background: theme.surfaceColor,
                borderRadius: theme.borderRadius,
                padding: '1.25rem',
              }}
            >
              <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                <div>
                  <h3 style={{ margin: 0, fontFamily: theme.headingFont }}>{project.title}</h3>
                  <p style={{ margin: '0.25rem 0 0', color: theme.mutedTextColor }}>
                    Updated {formatDate(project.updated_at)}
                  </p>
                </div>
                {projectTasks.length > 0 && (
                  <span style={{ color: theme.mutedTextColor }}>{progress}% complete</span>
                )}
              </header>

              <p style={{ marginTop: '1rem', lineHeight: 1.5 }}>{project.summary}</p>

              <ul style={{ margin: '1rem 0 0', padding: 0, listStyle: 'none', display: 'grid', gap: '0.5rem' }}>
                {projectTasks.length === 0 && (
                  <li style={{ color: theme.mutedTextColor }}>No tasks yet.</li>
                )}

                {projectTasks.map((task) => (
                  <li
                    key={task.id}
                    style={{
                      background: 'rgba(15, 23, 42, 0.5)',
                      borderRadius: theme.borderRadius,
                      padding: '0.5rem 0.75rem',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                    }}
                  >
                    <div>
                      <strong>{task.title}</strong>
                      <div style={{ fontSize: '0.85rem', color: theme.mutedTextColor }}>
                        {statusLabels[task.status] || task.status}
                        {task.due_at && ` · Due ${formatDate(task.due_at)}`}
                      </div>
                    </div>
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => toggleTaskStatus(task)}
                        style={{
                          background: task.status === 'complete' ? 'rgba(34,197,94,0.2)' : 'rgba(59,130,246,0.2)',
                          border: '1px solid rgba(255,255,255,0.1)',
                          borderRadius: theme.borderRadius,
                          color: theme.textColor,
                          padding: '0.25rem 0.75rem',
                          cursor: 'pointer',
                        }}
                      >
                        {task.status === 'complete' ? 'Reopen' : 'Mark complete'}
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            </article>
          );
        })}
      </div>
    </section>
  );
};

export default ProjectsPanel;
