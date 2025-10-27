<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use LabkiPackManager\Util\UrlResolver;

/**
 * Base class for Labki Pack Manager API modules.
 *
 * Provides shared functionality for all Labki API endpoints:
 * - URL validation and normalization
 * - Permission checking
 * - Common helper methods
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class LabkiApiBase extends ApiBase {

	/**
	 * Validate a Git repository URL.
	 *
	 * Accepts https/http/git/ssh protocols and SCP-style (e.g., git@github.com:user/repo.git).
	 * Dies with error if URL is invalid.
	 *
	 * @param string $url Repository URL to validate
	 * @return void
	 */
	protected function validateRepoUrl(string $url): void {
		$url = trim($url);

		if ($url === '') {
			$this->dieWithError(['apierror-missingparam', 'url'], 'missing_url');
		}

		$isHttpLike = (bool)filter_var($url, FILTER_VALIDATE_URL);
		$isSchemeGitSsh = (bool)preg_match('/^(git|ssh):\/\/.+/i', $url);
		$isScpStyle = (bool)preg_match('/^[\w.-]+@[\w.-]+:.+$/', $url);

		if (!$isHttpLike && !$isSchemeGitSsh && !$isScpStyle) {
			$this->dieWithError('labkipackmanager-error-invalid-url', 'invalid_url');
		}

		// If a scheme exists, ensure it is allowed
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if ($scheme !== null) {
			$allowed = ['https', 'http', 'git', 'ssh'];
			if (!in_array(strtolower($scheme), $allowed, true)) {
				$this->dieWithError('labkipackmanager-error-invalid-protocol', 'invalid_protocol');
			}
		}
	}

	/**
	 * Validate then normalize to canonical content repo URL.
	 *
	 * Validates and normalizes a repository URL using UrlResolver.
	 * Dies with error if validation fails or URL cannot be normalized.
	 *
	 * @param string $url Repository URL to validate and normalize
	 * @return string Normalized canonical base URL
	 */
	protected function validateAndNormalizeUrl(string $url): string {
		$this->validateRepoUrl($url);

		try {
			$normalized = UrlResolver::resolveContentRepoUrl($url);
		} catch (\Throwable $e) {
			$this->dieWithError('labkipackmanager-error-invalid-url', 'invalid_url');
		}

		$normalized = trim($normalized);
		if ($normalized === '') {
			$this->dieWithError('labkipackmanager-error-invalid-url', 'invalid_url');
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

