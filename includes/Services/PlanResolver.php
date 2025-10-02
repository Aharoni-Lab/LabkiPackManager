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

        // Try to use MediaWiki services when available; otherwise fall back to a simple parser
        $titleFactory = null; $nsInfo = null;
        try {
            if ( class_exists( MediaWikiServices::class ) ) {
                $services = MediaWikiServices::getInstance();
                // When unit tests run without MW bootstrap, this may throw; keep it guarded
                $titleFactory = $services->getTitleFactory();
                $nsInfo = $services->getNamespaceInfo();
            }
        } catch ( \Throwable $e ) {
            $titleFactory = null; $nsInfo = null;
        }

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

            // Apply renames with namespace-preserving behavior for namespaced content
            $finalTitle = $prefixed;
            if ( $pageAction === 'rename' || ( ($pageAction === 'skip') && $globalPrefix && ( isset($ppcSet[$prefixed]) || isset($extSet[$prefixed]) ) ) ) {
                // If skipping due to collision but global prefix provided, turn into rename
                if ( $pageAction === 'skip' ) { $pageAction = 'rename'; }
                if ( is_string($renameTo) && $renameTo !== '' ) {
                    $finalTitle = $renameTo;
                } else {
                    if ( $titleFactory && $nsInfo ) {
                        $t = $titleFactory->newFromText( $prefixed );
                        if ( $t ) {
                            $ns = $t->getNamespace();
                            $text = $t->getText();
                            if ( $ns !== NS_MAIN ) {
                                // Preserve original namespace; add prefix as subpage component
                                $finalTitle = $titleFactory->makeTitle( $ns, $globalPrefix . '/' . $text )->getPrefixedText();
                            } else {
                                // Try to interpret prefix as real namespace; otherwise treat as literal prefix in main
                                $targetNs = null;
                                if ( is_string($globalPrefix) && $globalPrefix !== '' ) {
                                    $idx = $nsInfo->getCanonicalIndex( $globalPrefix );
                                    if ( is_int( $idx ) ) { $targetNs = $idx; }
                                }
                                if ( $targetNs !== null ) {
                                    $finalTitle = $titleFactory->makeTitle( $targetNs, $text )->getPrefixedText();
                                } else {
                                    $finalTitle = $globalPrefix . ':' . $prefixed;
                                }
                            }
                        } else {
                            $finalTitle = $globalPrefix ? ($globalPrefix . ':' . $prefixed) : $prefixed;
                        }
                    } else {
                        // Simple fallback without MW services: preserve first-segment namespace if present
                        $nsName = null; $rest = $prefixed;
                        $pos = strpos( $prefixed, ':' );
                        if ( $pos !== false ) {
                            $nsName = substr( $prefixed, 0, $pos );
                            $rest = substr( $prefixed, $pos + 1 );
                        }
                        if ( $nsName !== null && $nsName !== '' ) {
                            $finalTitle = $nsName . ':' . $globalPrefix . '/' . $rest;
                        } else {
                            $finalTitle = $globalPrefix . ':' . $prefixed;
                        }
                    }
                }
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


