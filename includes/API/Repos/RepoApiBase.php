<?php
declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use ApiBase;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Util\UrlResolver;

/**
 * Abstract base for repository-related API modules.
 *
 * Provides:
 * - Registry accessors
 * - URL validation + normalization
 * - Permission gating
 * - Param extraction helper
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class RepoApiBase extends ApiBase {

	/** Get the repository registry. */
	protected function getRepoRegistry(): LabkiRepoRegistry {
		return new LabkiRepoRegistry();
	}

	/** Get the ref registry. */
	protected function getRefRegistry(): LabkiRefRegistry {
		return new LabkiRefRegistry();
	}

	/**
	 * Validate a Git repository URL.
	 * Accepts https/http/git/ssh and SCP-style (e.g., git@github.com:user/repo.git).
	 * Dies on error.
	 */
	protected function validateRepoUrl( string $url ): void {
		$url = trim( $url );

		if ( $url === '' ) {
			$this->dieWithError( [ 'apierror-missingparam', 'url' ], 'missing_url' );
		}

		$isHttpLike = (bool)filter_var( $url, FILTER_VALIDATE_URL );
		$isSchemeGitSsh = (bool)preg_match( '/^(git|ssh):\/\/.+/i', $url );
		$isScpStyle = (bool)preg_match( '/^[\w.-]+@[\w.-]+:.+$/', $url );

		if ( !$isHttpLike && !$isSchemeGitSsh && !$isScpStyle ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-url', 'invalid_url' );
		}

		// If a scheme exists, ensure it is allowed.
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		if ( $scheme !== null ) {
			$allowed = [ 'https', 'http', 'git', 'ssh' ];
			if ( !in_array( strtolower( $scheme ), $allowed, true ) ) {
				$this->dieWithError( 'labkipackmanager-error-invalid-protocol', 'invalid_protocol' );
			}
		}
	}

	/**
	 * Validate then normalize to canonical content repo URL.
	 * Returns normalized URL or dies on error.
	 */
	protected function validateAndNormalizeUrl( string $url ): string {
		$this->validateRepoUrl( $url );

		try {
			$normalized = UrlResolver::resolveContentRepoUrl( $url );
		} catch ( \Throwable $e ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-url', 'invalid_url' );
		}

		$normalized = trim( (string)$normalized );
		if ( $normalized === '' ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-url', 'invalid_url' );
		}

		return $normalized;
	}

	/** Require the 'labkipackmanager-manage' right. Dies if missing. */
	protected function requireManagePermission(): void {
		if ( !$this->getAuthority()->isAllowed( 'labkipackmanager-manage' ) ) {
			$this->dieWithError( 'labkipackmanager-error-permission', 'permission_denied' );
		}
	}
}
