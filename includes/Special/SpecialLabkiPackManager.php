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

        $request = $this->getRequest();
        $token = $request->getVal( 'token' );
        $doRefresh = $request->getCheck( 'refresh' );

        // Resolve content sources (required)
        $sources = ContentSourceHelper::getSources();
        if ( !$sources ) {
            $output->addHTML( '<div class="mw-message-box mw-message-box-error">' .
                htmlspecialchars( $this->msg( 'labkipackmanager-error-no-sources' )->text() ) . '</div>' );
            return;
        }

        $repoLabel = ContentSourceHelper::resolveSelectedRepoLabel( $sources, $request->getVal( 'repo' ) );
        $manifestUrl = ContentSourceHelper::getManifestUrlForLabel( $sources, $repoLabel );

		$store = new ManifestStore( $manifestUrl );
        $packs = null;
        $statusNote = '';

		if ( $request->wasPosted() && $doRefresh ) {
            if ( $this->getContext()->getCsrfTokenSet()->matchToken( $token ) ) {
				$fetcher = new ManifestFetcher();
				$status = $fetcher->fetchManifestFromUrl( $manifestUrl );
                if ( $status->isOK() ) {
                    $packs = $status->getValue();
                    $store->savePacks( $packs );
                    $statusNote = $this->msg( 'labkipackmanager-status-fetched' )->escaped();
                } else {
                    $output->addWikiTextAsInterface( $this->msg( $status->getMessage()->getKey() )->text() );
                    return;
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

		// Source selector (GET form) via renderer
		$renderer = new PackListRenderer();
		$output->addHTML( $renderer->renderRepoSelector( $sources, $repoLabel, $this->msg( 'labkipackmanager-button-load' )->text() ) );

		$output->addWikiTextAsInterface( '== ' . $this->msg( 'labkipackmanager-list-title' )->text() . ' ==' );
        if ( $statusNote !== '' ) {
            $output->addHTML( '<div class="mw-message-box mw-message-box-notice">' . $statusNote . '</div>' );
        }

		$output->addHTML( $renderer->renderRefreshForm( $this->getContext()->getCsrfTokenSet()->getToken(), $this->msg( 'labkipackmanager-button-refresh' )->text(), $repoLabel ) );

        if ( !is_array( $packs ) || !$packs ) {
            return;
        }

		$output->addHTML( $renderer->renderPacksList( $packs, $this->getContext()->getCsrfTokenSet()->getToken(), $repoLabel ) );
    }
}


