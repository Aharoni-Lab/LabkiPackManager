<?php

namespace LabkiPackManager\Special;

use SpecialPage;
use LabkiPackManager\Services\ManifestFetcher;
use LabkiPackManager\Services\ManifestStore;

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

        $configUrl = $this->getConfig()->get( 'LabkiContentManifestURL' );
        $store = new ManifestStore( $configUrl );
        $packs = null;
        $statusNote = '';

        if ( $request->wasPosted() && $doRefresh ) {
            if ( $this->getContext()->getCsrfTokenSet()->matchToken( $token ) ) {
                $fetcher = new ManifestFetcher();
                $status = $fetcher->fetchRootManifest();
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

        $output->addWikiTextAsInterface( '== ' . $this->msg( 'labkipackmanager-list-title' )->text() . ' ==' );
        if ( $statusNote !== '' ) {
            $output->addHTML( '<div class="mw-message-box mw-message-box-notice">' . $statusNote . '</div>' );
        }

        $html = '<form method="post" style="margin-bottom:12px">';
        $html .= \Html::hidden( 'token', $this->getContext()->getCsrfTokenSet()->getToken() );
        $html .= '<button class="mw-htmlform-submit" type="submit" name="refresh" value="1">' .
            htmlspecialchars( $this->msg( 'labkipackmanager-button-refresh' )->text() ) . '</button>';
        $html .= '</form>';
        $output->addHTML( $html );

        if ( !is_array( $packs ) || !$packs ) {
            return;
        }

        $html = '<form method="post">';
        $html .= \Html::hidden( 'token', $this->getContext()->getCsrfTokenSet()->getToken() );
        foreach ( $packs as $p ) {
            $id = htmlspecialchars( $p['id'] );
            $desc = htmlspecialchars( $p['description'] );
            $version = htmlspecialchars( $p['version'] );
            $html .= '<div><label>';
            $html .= '<input type="checkbox" name="packs[]" value="' . $id . '"> ';
            $html .= '<b>' . $id . '</b>';
            if ( $version !== '' ) {
                $html .= ' <span style="color:#666">(' . $version . ')</span>';
            }
            if ( $desc !== '' ) {
                $html .= ' – ' . $desc;
            }
            $html .= '</label></div>';
        }
        $html .= '<div style="margin-top:8px"><input type="submit" value="Select"></div>';
        $html .= '</form>';
        $output->addHTML( $html );
    }
}


