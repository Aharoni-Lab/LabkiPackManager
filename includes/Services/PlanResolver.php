<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;

/**
 * Builds a deterministic import plan with safe defaults and optional renames.
 */
final class PlanResolver {
    /**
     * @param array{packs:string[],pages:string[]} $resolved
     * @param array{globalPrefix?:?string,pages?:array<string,array{action?:string,renameTo?:?string,backup?:bool}>} $actions
     * @param array{lists?:array{create?:string[],update_unchanged?:string[],update_modified?:string[],pack_pack_conflicts?:string[],external_collisions?:string[]}} $preflight
     * @return array{pages:array<int,array{title:string,finalTitle:string,action:string,backup:bool}>,summary:array<string,int>}
     */
    public function resolve( array $resolved, array $actions, array $preflight ): array {
        $globalPrefix = isset($actions['globalPrefix']) && is_string($actions['globalPrefix']) && $actions['globalPrefix'] !== ''
            ? (string)$actions['globalPrefix'] : null;
        $perPage = is_array($actions['pages'] ?? null) ? (array)$actions['pages'] : [];

        $lists = (array)($preflight['lists'] ?? []);
        $createSet = array_flip($lists['create'] ?? []);
        $unchSet = array_flip($lists['update_unchanged'] ?? []);
        $modSet = array_flip($lists['update_modified'] ?? []);
        $ppcSet = array_flip($lists['pack_pack_conflicts'] ?? []);
        $extSet = array_flip($lists['external_collisions'] ?? []);

        $planPages = [];
        $counts = [ 'create' => 0, 'update' => 0, 'skip' => 0, 'rename' => 0, 'backup' => 0 ];

        foreach ( $resolved['pages'] as $prefixed ) {
            $pageAction = $perPage[$prefixed]['action'] ?? null;
            $renameTo = $perPage[$prefixed]['renameTo'] ?? null;
            $backup = (bool)($perPage[$prefixed]['backup'] ?? false);

            // Derive default action by category (safe defaults)
            if ( $pageAction === null ) {
                if ( isset($createSet[$prefixed]) ) { $pageAction = 'create'; }
                elseif ( isset($unchSet[$prefixed]) || isset($modSet[$prefixed]) ) { $pageAction = 'update'; }
                elseif ( isset($ppcSet[$prefixed]) || isset($extSet[$prefixed]) ) { $pageAction = 'skip'; }
                else { $pageAction = 'update'; }
            }

            // Apply renames: explicit per-page wins; else global prefix if provided and action would skip due to collision
            $finalTitle = $prefixed;
            $didRename = false;
            if ( $pageAction === 'rename' ) {
                if ( is_string($renameTo) && $renameTo !== '' ) { $finalTitle = $renameTo; $didRename = true; }
                elseif ( $globalPrefix ) { $finalTitle = $globalPrefix . ':' . $prefixed; $didRename = true; }
            } elseif ( ($pageAction === 'skip') && $globalPrefix && ( isset($ppcSet[$prefixed]) || isset($extSet[$prefixed]) ) ) {
                $finalTitle = $globalPrefix . ':' . $prefixed; $pageAction = 'rename'; $didRename = true;
            }

            $planPages[] = [ 'title' => $prefixed, 'finalTitle' => $finalTitle, 'action' => $pageAction, 'backup' => $backup ];
            if ( $pageAction === 'create' ) { $counts['create']++; }
            elseif ( $pageAction === 'update' ) { $counts['update']++; if ($backup) $counts['backup']++; }
            elseif ( $pageAction === 'rename' ) { $counts['rename']++; }
            else { $counts['skip']++; }
        }

        return [ 'pages' => $planPages, 'summary' => $counts ];
    }
}


