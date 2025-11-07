/**
 * Labki Pack Manager - Main Entry Point
 *
 * Initializes the Vue application after MediaWiki modules are loaded.
 */

console.log('Labki Pack Manager: main.ts loading...');

import { createApp } from 'vue';
import App from './ui/App.vue';
import './styles/index.scss';

console.log('Labki Pack Manager: imports complete');

/**
 * Initialize the application.
 */
function initApp() {
  console.log('Labki Pack Manager: initApp called');
  const root = document.getElementById('labki-pack-manager-root');

  if (!root) {
    console.error('Labki Pack Manager: Root element not found');
    return;
  }

  console.log('Labki Pack Manager: Root element found, creating app');

  // Create and mount Vue app
  const app = createApp(App);
  app.mount(root);

  console.log('Labki Pack Manager: Application mounted');
}

/**
 * Wait for required MediaWiki modules to load, then initialize.
 */
function bootstrap() {
  console.log('Labki Pack Manager: bootstrap called');
  const requiredModules = ['vue', 'mediawiki.api', '@wikimedia/codex', 'codex-styles'];

  mw.loader
    .using(requiredModules)
    .then(() => {
      console.log('Labki Pack Manager: All modules loaded, initializing app');
      initApp();
    })
    .catch((error) => {
      console.error('Labki Pack Manager: Failed to load required modules', error);
    });
}

// Start the bootstrap process
console.log('Labki Pack Manager: Checking MediaWiki environment...');
if (typeof mw !== 'undefined' && mw.loader) {
  console.log('Labki Pack Manager: MediaWiki environment available, starting bootstrap');
  bootstrap();
} else {
  console.error('Labki Pack Manager: MediaWiki environment not available');
}
