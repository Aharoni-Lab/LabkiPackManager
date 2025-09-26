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

		// Resolve content sources (required)
		$sources = $this->getConfig()->get( 'LabkiContentSources' );
		if ( !is_array( $sources ) || !$sources ) {
			$output->addHTML( '<div class="mw-message-box mw-message-box-error">' .
				htmlspecialchars( $this->msg( 'labkipackmanager-error-no-sources' )->text() ) . '</div>' );
			return;
		}

		$repoLabel = $request->getVal( 'repo' );
		if ( !$repoLabel || !isset( $sources[$repoLabel] ) ) {
			$repoLabel = array_key_first( $sources );
		}
		$manifestUrl = $sources[$repoLabel]['manifestUrl'] ?? '';

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

		// Source selector (GET form)
		$selector = '<form method="get" style="margin-bottom:12px">';
		$selector .= '<label style="margin-right:8px">' . htmlspecialchars( $this->msg( 'labkipackmanager-repo-select-label' )->text() ) . '</label>';
		$selector .= '<select name="repo">';
		foreach ( $sources as $label => $info ) {
			$selected = $label === $repoLabel ? ' selected' : '';
			$selector .= '<option value="' . htmlspecialchars( $label ) . '"' . $selected . '>' . htmlspecialchars( $label ) . '</option>';
		}
		$selector .= '</select> ';
		$selector .= '<button class="mw-htmlform-submit" type="submit" name="load" value="1">' . htmlspecialchars( $this->msg( 'labkipackmanager-button-load' )->text() ) . '</button>';
		$selector .= '</form>';
		$output->addHTML( $selector );

		$output->addWikiTextAsInterface( '== ' . $this->msg( 'labkipackmanager-list-title' )->text() . ' ==' );
        if ( $statusNote !== '' ) {
            $output->addHTML( '<div class="mw-message-box mw-message-box-notice">' . $statusNote . '</div>' );
        }

		$html = '<form method="post" style="margin-bottom:12px">';
        $html .= \MediaWiki\Html\Html::hidden( 'token', $this->getContext()->getCsrfTokenSet()->getToken() );
		$html .= \MediaWiki\Html\Html::hidden( 'repo', $repoLabel );
        $html .= '<button class="mw-htmlform-submit" type="submit" name="refresh" value="1">' .
            htmlspecialchars( $this->msg( 'labkipackmanager-button-refresh' )->text() ) . '</button>';
        $html .= '</form>';
        $output->addHTML( $html );

        if ( !is_array( $packs ) || !$packs ) {
            return;
        }

		$html = '<form method="post">';
        $html .= \MediaWiki\Html\Html::hidden( 'token', $this->getContext()->getCsrfTokenSet()->getToken() );
		$html .= \MediaWiki\Html\Html::hidden( 'repo', $repoLabel );
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


