<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

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
        $counts = [ 'create' => 0, 'update' => 0, 'skip' => 0, 'rename' => 0, 'backup' => 0, 'collision' => 0 ];

        // Compute titles

        foreach ( $resolved['pages'] as $prefixed ) {
            $pageAction = $perPage[$prefixed]['action'] ?? null;
            $renameTo = $perPage[$prefixed]['renameTo'] ?? null;
            $backup = (bool)($perPage[$prefixed]['backup'] ?? false);

            // Derive default action by category (safe defaults)
            if ( $pageAction === null ) {
                if ( isset($createSet[$prefixed]) ) { $pageAction = 'create'; }
                elseif ( isset($unchSet[$prefixed]) || isset($modSet[$prefixed]) ) { $pageAction = 'update'; }
                elseif ( isset($ppcSet[$prefixed]) || isset($extSet[$prefixed]) ) { $pageAction = 'collision'; }
                else { $pageAction = 'update'; }
            }

            // Apply renames with namespace-preserving behavior for namespaced content
            $finalTitle = $prefixed;
            if ( $pageAction === 'rename' || ( ($pageAction === 'collision') && $globalPrefix && ( isset($ppcSet[$prefixed]) || isset($extSet[$prefixed]) ) ) ) {
                // If skipping due to collision but global prefix provided, turn into rename
                if ( $pageAction === 'collision' ) { $pageAction = 'rename'; }
                if ( is_string($renameTo) && $renameTo !== '' ) {
                    // Combine per-page rename with optional global prefix
                    [ $nsName, $leaf ] = self::splitNamespace( $prefixed );
                    if ( $nsName !== null && $nsName !== '' && $nsName !== 'Main' ) {
                        $newLeaf = $globalPrefix ? ($globalPrefix . '/' . (string)$renameTo) : (string)$renameTo;
                        $finalTitle = $nsName . ':' . $newLeaf;
                    } else {
                        $finalTitle = $globalPrefix ? ($globalPrefix . ':' . (string)$renameTo) : (string)$renameTo;
                    }
                } else {
                    [ $nsName, $leaf ] = self::splitNamespace( $prefixed );
                    if ( $nsName !== null && $nsName !== '' && $nsName !== 'Main' ) {
                        // Preserve original namespace; add prefix as subpage component
                        $finalTitle = $globalPrefix ? ($nsName . ':' . $globalPrefix . '/' . $leaf) : $prefixed;
                    } else {
                        $finalTitle = $globalPrefix ? ($globalPrefix . ':' . $prefixed) : $prefixed;
                    }
                }
            }

            $planPages[] = [ 'title' => $prefixed, 'finalTitle' => $finalTitle, 'action' => $pageAction, 'backup' => $backup ];
            if ( $pageAction === 'create' ) { $counts['create']++; }
            elseif ( $pageAction === 'update' ) { $counts['update']++; if ($backup) $counts['backup']++; }
            elseif ( $pageAction === 'rename' ) { $counts['rename']++; }
            elseif ( $pageAction === 'skip' ) { $counts['skip']++; }
            else { $counts['collision']++; }
        }

        return [ 'pages' => $planPages, 'summary' => $counts ];
    }

    /**
     * Split a prefixed title into namespace name and leaf title using the first colon.
     * Returns array [ ?string $namespaceName, string $leafTitle ]. If no namespace, first element is null.
     * This simple splitter avoids MediaWiki service dependencies for unit-test friendliness.
     *
     * @param string $prefixed
     * @return array{0:?string,1:string}
     */
    private static function splitNamespace( string $prefixed ): array {
        $pos = strpos( $prefixed, ':' );
        if ( $pos === false ) { return [ null, $prefixed ]; }
        $ns = substr( $prefixed, 0, $pos );
        $leaf = substr( $prefixed, $pos + 1 );
        return [ $ns, $leaf ];
    }
}


