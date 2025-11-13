import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './importAnki.css';
import AnkiImport from './components/AnkiImport';

// Wait for DOM to be ready and container to exist
const initApp = () => {
  const container = document.getElementById('ld-anki-import-container');
  
  if (container) {
    createRoot(container).render(
      <StrictMode>
        <AnkiImport />
      </StrictMode>,
    );
  } else {
    console.error('Container #ld-anki-import-container not found');
  }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp);
} else {
  initApp();
}
