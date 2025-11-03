<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Json\FormatJson;
use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\PackStateStore;
use MediaWiki\Status\Status;

/**
 * Unified, intent-driven endpoint for pack interactions.
 *
 * Frontend sends a single JSON payload describing:
 *   { "command": "<name>", "repo_url": "<url>", "ref": "<ref>", "data": { ... } }
 *
 * Backend resolves repo/ref, loads manifest + session state, dispatches to a handler,
 * persists authoritative state, and returns a diff + new state hash.
 *
 * Action: labkiPacksAction
 *
 * Response schema (always):
 * {
 *   "ok": true,
 *   "diff": {...},              // deep diff of changed fields; full state on init if no prior state
 *   "warnings": [],             // array of strings
 *   "state_hash": "abc123",     // authoritative server hash after applying command
 *   "meta": { "schemaVersion": 1, "timestamp": 1730312345 }
 * }
 *
 * Handlers live in includes/Handlers/Packs/ and implement PackCommandHandler.
 * Example handler class names (registered in $this->handlers):
 *   - InitHandler
 *   - SetPackActionHandler
 *   - RenamePageHandler
 *   - SetPackPrefixHandler
 *   - RefreshHandler
 *   - ClearHandler
 *   - ApplyHandler
 *
 * All commands require permission.
 *
 * @ingroup API
 */
final class ApiLabkiPacksAction extends PackApiBase {

	/** @var array<string, class-string> */
	private array $handlers;

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
        
		// Register command → handler class map.
		// Add new commands here without touching endpoint logic.
		$this->handlers = [
			'init'            => \LabkiPackManager\Handlers\Packs\InitHandler::class,
			'set_pack_action' => \LabkiPackManager\Handlers\Packs\SetPackActionHandler::class,
			'rename_page'     => \LabkiPackManager\Handlers\Packs\RenamePageHandler::class,
			'set_pack_prefix' => \LabkiPackManager\Handlers\Packs\SetPackPrefixHandler::class,
			'refresh'         => \LabkiPackManager\Handlers\Packs\RefreshHandler::class,
			'clear'           => \LabkiPackManager\Handlers\Packs\ClearHandler::class,
			'apply'           => \LabkiPackManager\Handlers\Packs\ApplyHandler::class,
		];
	}

	/** Execute the API request. */
	public function execute(): void {
        // Right now we require manage permission for all commands.
        // TODO: Should we require manage permission for all commands?
		$this->requireManagePermission();

		$params = $this->extractRequestParams();
		$payloadString = $params['payload'];
		
		// Decode JSON string to array since we use 'string' type
		$payload = FormatJson::parse( $payloadString, FormatJson::FORCE_ASSOC );
		if ( !$payload->isOK() ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-payload', 'invalid_payload' );
		}
		$payload = $payload->getValue();
		if ( !is_array( $payload ) ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-payload', 'invalid_payload' );
		}
        // ------------------------------------------------------------
        // Validate payload fields
		$command = $payload['command'];
		$repoUrl = $payload['repo_url'];
		$refName = $payload['ref'];
		$data    = $payload['data'];

		if ( !is_string( $command ) || $command === '' ) {
			$this->dieWithError( 'labkipackmanager-error-unknown-command', 'unknown_command' );
		}
		if ( !is_string( $repoUrl ) || $repoUrl === '' ) {
			$this->dieWithError( 'labkipackmanager-error-repo-required', 'missing_repo' );
		}
		if ( !is_string( $refName ) || $refName === '' ) {
			$this->dieWithError( 'labkipackmanager-error-ref-required', 'missing_ref' );
		}
		if ( !is_array( $data ) ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-payload', 'invalid_payload' );
		}
        // ------------------------------------------------------------

        // ------------------------------------------------------------
		// Resolve repo/ref and manifest
        $repoRegistry = new LabkiRepoRegistry();
		$refRegistry  = new LabkiRefRegistry();

		$repoId = $repoRegistry->getRepoId( $repoUrl );
		if ( $repoId === null ) {
			$this->dieWithError( 'labkipackmanager-error-repo-not-found', 'repo_not_found' );
		}

		$refId = $refRegistry->getRefIdByRepoAndRef( $repoUrl, $refName );
		if ( $refId === null ) {
			$this->dieWithError( 'labkipackmanager-error-ref-not-found', 'ref_not_found' );
		}

		$manifestStore = new ManifestStore( $repoUrl, $refName );
		$status = $manifestStore->getManifest();
		if ( !$status->isOK() ) {
			$this->dieWithError( 'labkipackmanager-error-manifest-not-found', 'manifest_not_found' );
		}
		$manifest = $status->getValue();
		// ------------------------------------------------------------

		// Load or create state per command
		// init, refresh, and clear can work without existing state (they rebuild from scratch)
		// Other commands require existing state to operate on
		$userId = $this->getUser()->getId();
		$stateStore = new PackStateStore();
		$state = $stateStore->get( $userId, $refId );

		$commandsAllowedWithoutState = [ 'init', 'refresh', 'clear' ];
		if ( !in_array( $command, $commandsAllowedWithoutState, true ) && $state === null ) {
			$this->dieWithError( 'labkipackmanager-error-invalid-state', 'no_state' );
		}

		// Lookup handler
		$handlerClass = $this->handlers[$command];
		if ( $handlerClass === null ) {
			$this->dieWithError( 'labkipackmanager-error-unknown-command', 'unknown_command' );
		}

		// Capture old state for diff computation
		// Pages are nested inside packs
		// For init command, state is null so oldPacks will be empty
		$oldPacks = $state ? $state->packs() : [];

		// Build context for handlers
        // TODO: make sure we need to pass all this context to the handler
		$ctx = [
			'user_id'  => $userId,
			'repo_url' => $repoUrl,
			'ref'      => $refName,
			'repo_id'  => $repoId, // domain id object
			'ref_id'   => $refId,  // domain id object
			'services' => MediaWikiServices::getInstance(),
		];

		// Dispatch with error handling
		/** @var \LabkiPackManager\Handlers\Packs\PackCommandHandler $handler */
		$handler = new $handlerClass();

		try {
			// Handlers mutate or create PackSessionState and return warnings + optional flags.
			$result = $handler->handle( $state, $manifest, $data, $ctx );
		} catch ( \InvalidArgumentException $e ) {
			$this->dieWithError( $e->getMessage(), 'invalid_argument' );
		} catch ( \RuntimeException $e ) {
			$this->dieWithError( $e->getMessage(), 'handler_error' );
		} catch ( \Exception $e ) {
			wfDebugLog( 'labkipack', "ApiLabkiPacksAction: unexpected error: " . $e->getMessage() );
			$this->dieWithError( 'labkipackmanager-error-internal', 'internal_error' );
		}

		/** @var PackSessionState $newState */
		$newState = $result['state'];
		$warnings = $result['warnings'];
		$operationInfo = $result['operation_info'];

		// Persist unless handler requested otherwise
		if ($result['save'] ) {
			$stateStore->save( $newState );
		}

		// Compute diff: if no prior state, return full packs as diff
		$newPacks = $newState->packs();
		// For init and clear commands, always return full state as diff, ignoring old state
		$diff = ($command === 'init' || $command === 'clear' || empty( $oldPacks )) ? $newPacks : $this->computePacksDiff( $oldPacks, $newPacks );

		// Build response
		$responseData = [
			'ok'         => true,
			'diff'       => $diff,
			'warnings'   => $warnings,
			'state_hash' => $newState->hash(),
		];

		// Add operation info if handler provided it (e.g., from apply command)
		if ( $operationInfo !== null && is_array( $operationInfo ) ) {
			$responseData['operation'] = $operationInfo;
		}

		// Respond uniformly - use addValue() with proper boolean conversion
		$result = $this->getResult();
		$responseData['meta'] = [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		];
		
		// Convert all booleans to integers for proper API serialization
		$responseData = $this->prepareDiffForApi( $responseData );
		
		// Add the entire response as a single nested value
		$result->addValue( null, 'labkiPacksAction', $responseData );
	}

	/** Compute deep diff between two pack states. */
	private function computePacksDiff( array $oldPacks, array $newPacks ): array {
		$diff = [];
		foreach ( $newPacks as $packName => $newPack ) {
			$oldPack = $oldPacks[$packName] ?? null;
			if ( $oldPack === null ) {
				$diff[$packName] = $newPack;
				continue;
			}
			$packDiff = $this->computePackDiff( $oldPack, $newPack );
			if ( !empty( $packDiff ) ) {
				$diff[$packName] = $packDiff;
			}
		}
		return $diff;
	}

	/** Compute diff for one pack. */
	private function computePackDiff( array $oldPack, array $newPack ): array {
		$diff = [];

		foreach ( PackSessionState::PACK_FIELDS as $field ) {
			$ov = $oldPack[$field] ?? null;
			$nv = $newPack[$field] ?? null;
			if ( $ov !== $nv ) {
				$diff[$field] = $nv;
			}
		}

		$oldPages = $oldPack['pages'];
		$newPages = $newPack['pages'];
		$pagesDiff = $this->computePagesDiff( $oldPages, $newPages );
		if ( !empty( $pagesDiff ) ) {
			$diff['pages'] = $pagesDiff;
		}

		return $diff;
	}

	/** Compute diff for pages within a pack. */
	private function computePagesDiff( array $oldPages, array $newPages ): array {
		$diff = [];
		foreach ( $newPages as $pageName => $newPage ) {
			$oldPage = $oldPages[$pageName] ?? null;
			if ( $oldPage === null ) {
				$diff[$pageName] = $newPage;
				continue;
			}
			$pageDiff = [];
			foreach ( PackSessionState::PAGE_FIELDS as $f ) {
				$ov = $oldPage[$f] ?? null;
				$nv = $newPage[$f] ?? null;
				if ( $ov !== $nv ) {
					$pageDiff[$f] = $nv;
				}
			}
			if ( !empty( $pageDiff ) ) {
				$diff[$pageName] = $pageDiff;
			}
		}
		return $diff;
	}

	/** POST is required for all commands. */
	public function mustBePosted(): bool {
		return true;
	}

	/** All commands are write-mode. */
	public function isWriteMode(): bool {
		return true;
	}

	/** Internal API. */
	public function isInternal(): bool {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'payload' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-action-param-payload',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			// Example: init
			'action=labkiPacksAction&payload={"command":"init","repo_url":"https://github.com/Aharoni-Lab/labki-packs","ref":"main","data":{}}'
				=> 'apihelp-labkipacksaction-example-init',

			// Example: set pack action (install)
			'action=labkiPacksAction&payload={"command":"set_pack_action","repo_url":"https://github.com/Aharoni-Lab/labki-packs","ref":"main","data":{"pack_name":"Advanced Imaging","action":"install"}}'
				=> 'apihelp-labkipacksaction-example-setpackaction',

			// Example: rename page
			'action=labkiPacksAction&payload={"command":"rename_page","repo_url":"https://github.com/Aharoni-Lab/labki-packs","ref":"main","data":{"pack_name":"test pack","page_name":"test page","new_title":"Custom/Title"}}'
				=> 'apihelp-labkipacksaction-example-renamepage',

			// Example: apply
			'action=labkiPacksAction&payload={"command":"apply","repo_url":"https://github.com/Aharoni-Lab/labki-packs","ref":"main","data":{}}'
				=> 'apihelp-labkipacksaction-example-apply',
		];
	}

	/**
	 * Convert booleans in nested array to integers (0/1) for API serialization.
	 *
	 * @param array $data
	 * @return array
	 */
	private function prepareDiffForApi( array $data ): array {
		foreach ( $data as $k => &$v ) {
			if ( is_bool( $v ) ) {
				$v = $v ? 1 : 0;
			} elseif ( is_array( $v ) ) {
				$v = $this->prepareDiffForApi( $v );
			}
		}
		return $data;
	}
}
