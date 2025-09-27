<?php

namespace LabkiPackManager\Special;

use SpecialPage;
use LabkiPackManager\Services\ManifestFetcher;
use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Services\ContentSourceHelper;

class SpecialLabkiPackManager extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LabkiPackManager' );
    }

    public function getGroupName() {
        return 'labki';
    }

    public function getRestriction() {
        return 'labkipackmanager-manage';
    }

    public function execute( $subPage ) {
        $this->checkPermissions();

        $output = $this->getOutput();
        $output->setPageTitle( $this->msg( 'labkipackmanager-special-title' )->text() );
        $output->addModules( [ 'ext.LabkiPackManager.styles' ] );

        $request = $this->getRequest();
        $token = $request->getVal( 'token' );
        $doRefresh = $request->getCheck( 'refresh' );
        $doLoad = $request->getCheck( 'load' );

        // Resolve content sources (required)
        $sources = ContentSourceHelper::getSources();
        if ( !$sources ) {
            $output->addHTML( '<div class="mw-message-box mw-message-box-error">' .
                htmlspecialchars( $this->msg( 'labkipackmanager-error-no-sources' )->text() ) . '</div>' );
            return;
        }

        $session = $request->getSession();
        $requestedRepo = $request->getVal( 'repo' );
        if ( $requestedRepo === null ) {
            $persisted = $session->get( 'labkipackmanager.repo' );
            if ( is_string( $persisted ) ) {
                $requestedRepo = $persisted;
            }
        }
        $repoLabel = ContentSourceHelper::resolveSelectedRepoLabel( $sources, $requestedRepo );
        $session->set( 'labkipackmanager.repo', $repoLabel );
        $manifestUrl = ContentSourceHelper::getManifestUrlForLabel( $sources, $repoLabel );

        $store = new ManifestStore( $manifestUrl );
        $packs = null;
        $statusNote = '';
        $statusError = '';

        if ( $request->wasPosted() && $doRefresh ) {
            if ( !$this->getUser()->isAllowed( 'labkipackmanager-manage' ) ) {
                $output->addHTML( '<div class="cdx-message cdx-message--block cdx-message--warning"><span class="cdx-message__content">' .
                    htmlspecialchars( $this->msg( 'labkipackmanager-error-permission' )->text() ) . '</span></div>' );
                return;
            }
            if ( $this->getContext()->getCsrfTokenSet()->matchToken( $token ) ) {
                // Begin fetch feedback
                $statusNote = $this->msg( 'labkipackmanager-status-loaded-from', $repoLabel )->escaped();
                $fetcher = new ManifestFetcher();
                $status = $fetcher->fetchManifestFromUrl( $manifestUrl );
                if ( $status->isOK() ) {
                    $result = $status->getValue();
                    $packs = is_array( $result ) && isset( $result['packs'] ) ? $result['packs'] : $result;
                    $schemaVersion = is_array( $result ) ? ( $result['schema_version'] ?? null ) : null;
                    $store->savePacks( $packs, [
                        'schema_version' => $schemaVersion,
                        'manifest_url' => $manifestUrl,
                    ] );
                    $statusNote .= ' · ' . $this->msg( 'labkipackmanager-status-fetched' )->escaped();
                } else {
                    $key = null;
                    if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
                        $key = $status->getMessage()->getKey();
                    } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
                        $key = $status->getMessageValue()->getKey();
                    }
                    $statusError = $this->msg( $key ?: 'labkipackmanager-error-unknown' )->escaped();
                }
            } else {
                $statusError = $this->msg( 'sessionfailure' )->escaped();
            }
        }

        // If user clicked "Load" (GET or POST), fetch now
        if ( $doLoad ) {
            if ( !$this->getUser()->isAllowed( 'labkipackmanager-manage' ) ) {
                $output->addHTML( '<div class="cdx-message cdx-message--block cdx-message--warning"><span class="cdx-message__content">' .
                    htmlspecialchars( $this->msg( 'labkipackmanager-error-permission' )->text() ) . '</span></div>' );
                return;
            }
            if ( $request->wasPosted() && !$this->getContext()->getCsrfTokenSet()->matchToken( $token ) ) {
                $statusError = $this->msg( 'sessionfailure' )->escaped();
            } else {
                // Begin fetch feedback
                $statusNote = $this->msg( 'labkipackmanager-status-loaded-from', $repoLabel )->escaped();
                $fetcher = new ManifestFetcher();
                $status = $fetcher->fetchManifestFromUrl( $manifestUrl );
                if ( $status->isOK() ) {
                    $result = $status->getValue();
                    $packs = is_array( $result ) && isset( $result['packs'] ) ? $result['packs'] : $result;
                    $schemaVersion = is_array( $result ) ? ( $result['schema_version'] ?? null ) : null;
                    $store->savePacks( $packs, [
                        'schema_version' => $schemaVersion,
                        'manifest_url' => $manifestUrl,
                    ] );
                    $statusNote .= ' · ' . $this->msg( 'labkipackmanager-status-fetched' )->escaped();
                } else {
                    $key = null;
                    if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
                        $key = $status->getMessage()->getKey();
                    } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
                        $key = $status->getMessageValue()->getKey();
                    }
                    $statusError = $this->msg( $key ?: 'labkipackmanager-error-unknown' )->escaped();
                }
            }
        }

        if ( $packs === null ) {
            $packs = $store->getPacksOrNull();
            if ( is_array( $packs ) ) {
                $statusNote = $this->msg( 'labkipackmanager-status-using-cache' )->escaped();
            } else {
                $statusNote = $this->msg( 'labkipackmanager-status-missing' )->escaped();
            }
        }

        // Append meta details if available
        $meta = $store->getMetaOrNull();
        if ( is_array( $meta ) ) {
            $bits = [];
            if ( !empty( $meta['schema_version'] ) ) {
                $bits[] = 'Schema ' . htmlspecialchars( (string)$meta['schema_version'] );
            }
            if ( !empty( $meta['fetched_at'] ) && is_int( $meta['fetched_at'] ) ) {
                $bits[] = 'Fetched ' . htmlspecialchars( gmdate( 'Y-m-d H:i \U\T\C', $meta['fetched_at'] ) );
            }
            if ( $bits ) {
                $statusNote .= ( $statusNote !== '' ? ' · ' : '' ) . implode( ' · ', $bits );
            }
        }

		// Source selector (GET form) via renderer
        $renderer = new PackListRenderer();
        $output->addHTML( $renderer->renderRepoSelector(
            $sources,
            $repoLabel,
            $this->msg( 'labkipackmanager-button-load' )->text(),
            $this->msg( 'labkipackmanager-button-refresh' )->text(),
            $this->getContext()->getCsrfTokenSet()->getToken()
        ) );

		$output->addWikiTextAsInterface( '== ' . $this->msg( 'labkipackmanager-list-title' )->text() . ' ==' );
        if ( $statusNote !== '' ) {
            $output->addHTML( '<div class="cdx-message cdx-message--block cdx-message--notice"><span class="cdx-message__content">' . $statusNote . '</span></div>' );
        }
        if ( $statusError !== '' ) {
            $output->addHTML( '<div class="cdx-message cdx-message--block cdx-message--error"><span class="cdx-message__content">' . $statusError . '</span></div>' );
        }

        // Separate refresh form no longer needed; actions are part of the selector form

        $output->addHTML( $renderer->renderPacksList( is_array( $packs ) ? $packs : [], $this->getContext()->getCsrfTokenSet()->getToken(), $repoLabel ) );
    }
}


