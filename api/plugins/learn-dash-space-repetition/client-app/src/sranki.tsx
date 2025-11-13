import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './sranki.css'
import SpacedRepetition from './components/SpacedRepetition.tsx'

// Wait for DOM to be ready and container to exist
const initApp = () => {
  const container = document.getElementById('ld-sr-container');

  if (container) {
    createRoot(container).render(
      <StrictMode>
        <SpacedRepetition />
      </StrictMode>,
    );
  } else {
    console.error('Container #ld-sr-container not found');
  }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp);
} else {
  initApp();
}

