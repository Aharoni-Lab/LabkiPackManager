<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use LabkiPackManager\Util\UrlResolver;
use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * Base class for Labki Pack Manager API modules.
 *
 * Provides shared functionality for all Labki API endpoints:
 * - URL validation and normalization
 * - Repository validation and normalization
 * - Permission checking
 * - Common helper methods
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class LabkiApiBase extends ApiBase {

	/**
	 * Resolve and validate a repository URL.
	 *
	 * Validates the URL format, normalizes it to a canonical form using UrlResolver,
	 * and optionally verifies that the repository exists in the registry.
	 *
	 * Dies with error if validation fails, normalization fails, or repository is not found
	 * (when $requireExists is true).
	 *
	 * @param string $repoUrl Repository URL to resolve and validate
	 * @param bool $requireExists If true, ensures the repository exists in the registry (default: false)
	 * @return string Normalized repository URL
	 */
	protected function resolveRepoUrl(string $repoUrl, bool $requireExists = false): string {
		$repoUrl = trim($repoUrl);

		// Validate basic URL format
		if ($repoUrl === '') {
			$this->dieWithError(['apierror-missingparam', 'repo_url'], 'missing_repo_url');
		}

		$isHttpLike = (bool)filter_var($repoUrl, FILTER_VALIDATE_URL);
		$isSchemeGitSsh = (bool)preg_match('/^(git|ssh):\/\/.+/i', $repoUrl);
		$isScpStyle = (bool)preg_match('/^[\w.-]+@[\w.-]+:.+$/', $repoUrl);

		if (!$isHttpLike && !$isSchemeGitSsh && !$isScpStyle) {
			$this->dieWithError('labkipackmanager-error-invalid-repo_url', 'invalid_repo_url');
		}

		// Validate scheme if present
		$scheme = parse_url($repoUrl, PHP_URL_SCHEME);
		if ($scheme !== null) {
			$allowed = ['https', 'http', 'git', 'ssh'];
			if (!in_array(strtolower($scheme), $allowed, true)) {
				$this->dieWithError('labkipackmanager-error-invalid-protocol', 'invalid_protocol');
			}
		}

		// Normalize to canonical URL
		try {
			$normalized = UrlResolver::resolveContentRepoUrl($repoUrl);
		} catch (\Throwable $e) {
			$this->dieWithError('labkipackmanager-error-invalid-url', 'invalid_url');
		}

		// Optionally verify repository exists in registry
		if ($requireExists) {
			$repoRegistry = new LabkiRepoRegistry();
			if ($repoRegistry->getRepo($normalized) === null) {
				$this->dieWithError('labkipackmanager-error-repo-not-found', 'repo_not_found');
			}
		}

		return $normalized;
	}

	/**
	 * Require the 'labkipackmanager-manage' right.
	 *
	 * Dies with error if the current user does not have the required permission.
	 *
	 * @return void
	 */
	protected function requireManagePermission(): void {
		if (!$this->getAuthority()->isAllowed('labkipackmanager-manage')) {
			$this->dieWithError('labkipackmanager-error-permission', 'permission_denied');
		}
	}
}

