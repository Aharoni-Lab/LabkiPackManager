<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use MediaWiki\Title\Title;
use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Jobs\LabkiPackApplyJob;

/**
 * Handles applying pack operations.
 *
 * Command: "apply"
 *
 * Expected payload:
 * {
 *   "command": "apply",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {}
 * }
 *
 * Behavior:
 * - Builds operations array from session state
 * - Validates there are changes to apply
 * - Creates operation record
 * - Queues background job
 * - Clears session state after applying
 * - Returns operation_id and summary
 */
final class ApplyHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'ApplyHandler: state cannot be null' );
		}

		$userId = $context['user_id'];
		$refId = $context['ref_id'];
		$services = $context['services'];

		// Verify state hash matches - prevents tampering and ensures sync
		$frontendStateHash = $data['state_hash'];
		$backendStateHash = $state->hash();
		if ( $frontendStateHash !== $backendStateHash ) {
			throw new \RuntimeException( 
				"ApplyHandler: state hash mismatch. Frontend hash: {$frontendStateHash}, Backend hash: {$backendStateHash}. " .
				"Please refresh and try again."
			);
		}

		// Use packs data from state. Front and backend are in sync.
		$packsData = $state->packs();

		// Build operations array from state
		$operations = [];
		$summary = [
			'installs' => 0,
			'updates'  => 0,
			'removes'  => 0,
		];

		foreach ( $packsData as $packName => $packState ) {
			$action = $packState['action'] ?? 'unchanged';
			// Skip unchanged packs	
			if ( $action === 'unchanged' ) {
				continue;
			}

			// Build operation based on action
			if ( $action === 'install' ) {
				$operations[] = [
					'action'         => 'install',
					'pack_name'      => $packName,
					'target_version' => $packState['target_version'],
					'pages'          => $this->buildPagesArray( $packState['pages'] ),
				];
				$summary['installs']++;
			} elseif ( $action === 'update' ) {
				$operations[] = [
					'action'         => 'update',
					'pack_name'      => $packName,
					'target_version' => $packState['target_version'],
					'pages'          => $this->buildPagesArray( $packState['pages'] ),
				];
				$summary['updates']++;
			} elseif ( $action === 'remove' ) {
				// Remove operations for packs marked for removal
				// We need the pack_id from the registry
				$packRegistry = $services->getService( 'LabkiPackManager.PackRegistry' );
				if ( !$packRegistry ) {
					throw new \RuntimeException( 'ApplyHandler: PackRegistry service not found' );
				}

				$packId = $packRegistry->getPackIdByName( $refId, $packName );
				if ( $packId !== null ) {
					$operations[] = [
						'action'  	=> 'remove',
						'pack_name'	=> $packName,
						'pack_id' 	=> $packId->toInt(),
					];
					$summary['removes']++;
				}
			}
		}

		if ( empty( $operations ) ) {
			throw new \RuntimeException( 'ApplyHandler: no operations to apply' );
		}

		// Generate operation ID
		$operationIdStr = 'pack_apply_' . substr( md5( $refId->toInt() . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );

		// Create operation record
		$operationRegistry = new LabkiOperationRegistry();
		$operationMessage = sprintf(
			'Pack operations queued: %d installs, %d updates, %d removes',
			$summary['installs'],
			$summary['updates'],
			$summary['removes']
		);
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			$operationMessage
		);

		// Queue background job
		$jobParams = [
			'ref_id'       => $refId->toInt(),
			'operations'   => $operations,
			'operation_id' => $operationIdStr,
			'user_id'      => $userId,
		];

		$title = Title::newFromText( 'LabkiPackApplyJob' );
		$job = new LabkiPackApplyJob( $title, $jobParams );

		$services->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApplyHandler: queued job with operation_id={$operationIdStr}" );

		// Clear state from storage (after queuing job successfully)
		$this->stateStore->clear( $userId, $refId );

		// Create a new empty state to return
		$newState = new PackSessionState( $refId, $userId, [] );

		// Store the operation summary in state for frontend access
		$operationInfo = [
			'operation_id' => $operationIdStr,
			'status'       => LabkiOperationRegistry::STATUS_QUEUED,
			'summary'      => [
				'total_operations' => count( $operations ),
				'installs'         => $summary['installs'],
				'updates'          => $summary['updates'],
				'removes'          => $summary['removes'],
			],
		];

		// Don't persist this state, it's just for the response
		return [
			'state'            => $newState,
			'warnings'         => [],
			'save'             => false,
			'operation_info'   => $operationInfo,
		];
	}

	/**
	 * Build pages array for job operations.
	 *
	 * @param array $pages Pages from state
	 * @return array Pages array for job
	 */
	private function buildPagesArray( array $pages ): array {
		$result = [];
		foreach ( $pages as $pageName => $pageState ) {
			$result[] = [
				'name'        => $pageName,
				'final_title' => $pageState['final_title'] ?? $pageName,
			];
		}
		return $result;
	}
}
