/**
 * LabkiPackManager – Shared Constants
 * ------------------------------------------------------------
 * Centralized definitions for message types, API action names,
 * and other shared constants used across the LabkiPackManager
 * extension. Keeping these here avoids "stringly-typed" code
 * throughout the Vue app and API layer.
 */

/**
 * Supported Codex message types for notifications.
 * These correspond to Codex <cdx-message type="..."> options.
 */
export const MSG_TYPES = Object.freeze({
  SUCCESS: 'success',
  ERROR: 'error',
  INFO: 'info',
  WARNING: 'warning'
});

/**
 * MediaWiki API action identifiers used by the backend.
 * Extend this list if new custom API modules are introduced.
 */
export const API_ACTIONS = Object.freeze({
  MANIFEST: 'labkiManifest'
});

/**
 * Known manifest schema versions supported by the frontend.
 * Useful for validation, feature gating, and migration logic.
 */
export const SCHEMA_VERSIONS = Object.freeze({
  V1: '1.0.0',
  V2: '2.0.0'
});

/**
 * Default user-facing messages and labels.
 * Keep these minimal and replace with mw.msg() for i18n later.
 */
export const DEFAULT_MESSAGES = Object.freeze({
  NO_REPOS: 'No repositories configured in LocalSettings.php.',
  LOAD_SUCCESS: 'Manifest loaded successfully.',
  REFRESH_SUCCESS: 'Manifest refreshed successfully.',
  LOAD_ERROR: 'Failed to load manifest.'
});
