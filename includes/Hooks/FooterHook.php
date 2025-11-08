<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use MediaWiki\Html\Html;
use LabkiPackManager\Domain\Page;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Skin\Skin;

/**
 * FooterHook
 *
 * Hook handler that appends metadata about imported pages to the footer so users
 * can easily identify the originating pack, repository, and version details.
 */
final class FooterHook {
    private const MAX_COMMIT_DISPLAY = 12;

    /**
     * Handler for the SkinAfterContent hook.
     *
     * @param string &$data HTML being appended after the page content
     * @param Skin $skin Skin instance used to render the page
     *
     * @return bool Always true to continue hook processing
     */
    public static function onSkinAfterContent( string &$data, Skin $skin ): bool {
        $title = $skin->getTitle();
        /** @var LabkiPageRegistry $pageRegistry */
        $pageRegistry = MediaWikiServices::getInstance()->getService( 'LabkiPageRegistry' );
        
        // This approach of pageID also works
        // $pageId = $title ? $title->getArticleID() : 0;
        // $page = $pageId ? $pageRegistry->getPageByWikiPageId( (int)$pageId ) : null;

        $page = $pageRegistry->getPageByFinalTitle( mb_strtolower( $title->getPrefixedText() ) );
        if ( !$page ) {
            return true;
        }
        if ( !self::shouldDisplayFooter( $title, $skin ) ) {
            return true;
        }

        $skin->getOutput()->addModuleStyles( 'ext.LabkiPackManager.importFooter' );

        $footerHtml = self::buildFooterHtml( $page );
        if ( $footerHtml === null ) {
            return true;
        }

        $data .= $footerHtml;
        return true;
    }

    /**
     * Determine whether the footer should be shown for the current request.
     *
     * @param Title|null $title
     * @param Skin $skin
     *
     * @return bool
     */
    private static function shouldDisplayFooter( ?Title $title, Skin $skin ): bool {
        if ( !$title || !$title->canExist() ) {
            return false;
        }

        // Only show if the config is enabled
        $config = MediaWikiServices::getInstance()->getMainConfig();
        if ( !$config->get( 'LabkiShowImportFooter' ) ) {
            return false;
        }

        // Only show on standard view action to avoid cluttering edit/history pages.
        $request = $skin->getRequest();
        $action = $request->getVal( 'action', 'view' );
        if ( $action !== 'view' && $action !== 'purge' ) {
            return false;
        }

        return true;
    }


    /**
     * Build the HTML snippet describing the import metadata for a page.
     *
     * @param Page $page Page record fetched from the registry
     *
     * @return string|null HTML to append or null if any dependency data is missing
     */
    private static function buildFooterHtml( Page $page ): ?string {
        $services = MediaWikiServices::getInstance();

        /** @var LabkiPackRegistry $packRegistry */
        $packRegistry = $services->getService( 'LabkiPackRegistry' );
        $pack = $packRegistry->getPack( $page->packId() );
        if ( !$pack ) {
            return null;
        }

        /** @var LabkiRefRegistry $refRegistry */
        $refRegistry = $services->getService( 'LabkiRefRegistry' );
        $ref = $refRegistry->getRefById( $pack->contentRefId() );
        if ( !$ref ) {
            return null;
        }

        /** @var LabkiRepoRegistry $repoRegistry */
        $repoRegistry = $services->getService( 'LabkiRepoRegistry' );
        $repo = $repoRegistry->getRepo( $ref->repoId() );
        if ( !$repo ) {
            return null;
        }

        $packName = $pack->name();
        $repoUrl = $repo->url();
        $refLabel = $ref->refName() ?? $ref->sourceRef();
        $version = $pack->version();
        $commit = $pack->sourceCommit() ?? $ref->lastCommit();

        $repoLink = Html::element(
            'a',
            [
                'class' => 'labki-import-footer__repo',
                'href' => $repoUrl,
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ],
            $repoUrl
        );

        $packLabel = Html::element(
            'strong',
            [ 'class' => 'labki-import-footer__pack' ],
            $packName
        );

        $refFragment = Html::element(
            'span',
            [ 'class' => 'labki-import-footer__ref' ],
            $refLabel
        );

        $versionFragment = $version
            ? Html::element(
                'span',
                [ 'class' => 'labki-import-footer__version' ],
                wfMessage( 'labkipackmanager-import-footer-version', $version )->text()
            )
            : '';

        $commitFragment = $commit
            ? Html::element(
                'span',
                [ 'class' => 'labki-import-footer__commit' ],
                wfMessage(
                    'labkipackmanager-import-footer-commit',
                    self::formatCommit( $commit )
                )->text()
            )
            : '';

        $message = wfMessage( 'labkipackmanager-import-footer' )
            ->rawParams(
                $packLabel,
                $repoLink,
                $refFragment,
                $versionFragment,
                $commitFragment
            )
            ->text();

        $ariaLabel = wfMessage(
            'labkipackmanager-import-footer-aria',
            $packName,
            $refLabel
        )->text();

        $icon = Html::element(
            'span',
            [
                'class' => 'labki-import-footer__icon',
                'aria-hidden' => 'true',
            ],
            '📦'
        );

        return Html::rawElement(
            'div',
            [
                'class' => 'labki-import-footer',
                'role' => 'note',
                'aria-label' => $ariaLabel,
            ],
            $icon .
            Html::rawElement(
                'span',
                [ 'class' => 'labki-import-footer__text' ],
                $message
            )
        );
    }

    /**
     * Shorten extended commit hashes for display purposes.
     *
     * @param string $commit
     *
     * @return string
     */
    private static function formatCommit( string $commit ): string {
        $trimmed = trim( $commit );
        if ( strlen( $trimmed ) <= self::MAX_COMMIT_DISPLAY ) {
            return $trimmed;
        }
        return substr( $trimmed, 0, self::MAX_COMMIT_DISPLAY );
    }
}


