import React from 'react';
import { createRoot } from 'react-dom/client';
import PortalApp from './PortalApp';
import '../index.css';

const containerId = window.vhonaClientPortal?.capabilities?.manageClientPortal
  ? 'vhona-client-portal-admin'
  : 'vhona-client-portal-app';

const container = document.getElementById(containerId);

if (container) {
  const root = createRoot(container);
  root.render(<PortalApp />);
}
