<?php

namespace LabkiPackManager\Special;

class PackListRenderer {
    /**
     * Render a notice box if a status message is present.
     */
    public function renderStatusNotice( string $statusNote ) : string {
        if ( $statusNote === '' ) {
            return '';
        }
        return '<div class="cdx-message cdx-message--block cdx-message--notice"><span class="cdx-message__content">' . $statusNote . '</span></div>';
    }

    

    /**
     * Render the selectable list of packs with checkboxes.
     * Expects $packs entries with keys: id, description, version.
     */
    public function renderPacksList( array $packs, string $csrfToken, ?string $repoLabel = null ) : string {
        if ( !is_array( $packs ) || !$packs ) {
            return '<div class="cdx-message cdx-message--block"><span class="cdx-message__content">No packs available for the selected source.</span></div>';
        }
        $html = '<form method="post" class="cdx-form">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars( $csrfToken ) . '">';
        if ( $repoLabel !== null ) {
            $html .= '<input type="hidden" name="repo" value="' . htmlspecialchars( $repoLabel ) . '">';
        }
        foreach ( $packs as $p ) {
            $rawId = (string)( $p['id'] ?? '' );
            $id = htmlspecialchars( $rawId );
            $desc = htmlspecialchars( (string)( $p['description'] ?? '' ) );
            $version = htmlspecialchars( (string)( $p['version'] ?? '' ) );
            if ( $id === '' ) {
                continue;
            }
            $checkboxId = 'pack-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $rawId );
            $html .= '<div class="cdx-field" style="margin:4px 0">';
            $html .= '<span class="cdx-checkbox">';
            $html .= '<input class="cdx-checkbox__input" id="' . htmlspecialchars( $checkboxId ) . '" type="checkbox" name="packs[]" value="' . $id . '">';
            $html .= '<span class="cdx-checkbox__icon"></span>';
            $html .= '<label class="cdx-checkbox__label" for="' . htmlspecialchars( $checkboxId ) . '">';
            $html .= '<b>' . $id . '</b>';
            if ( $version !== '' ) {
                $html .= ' <span style="color:#666">(' . $version . ')</span>';
            }
            if ( $desc !== '' ) {
                $html .= ' – ' . $desc;
            }
            $html .= '</label>';
            $html .= '</span>';
            $html .= '</div>';
        }
        $html .= '<div style="margin-top:8px">';
        $html .= '<button class="cdx-button cdx-button--action-progressive" type="submit">Select</button>';
        $html .= '</div>';
        $html .= '</form>';
        return $html;
    }

    /**
     * Render the repository selector (GET form).
     *
     * @param array<string,string> $sources Mapping label => URL
     */
    public function renderRepoSelector( array $sources, string $selectedLabel, string $loadLabel, string $refreshLabel = 'Refresh' , ?string $csrfToken = null ) : string {
        // Single POST form containing the selector and both actions ensures the selected repo is always submitted
        $html = '<form method="post" class="cdx-form" style="margin-bottom:12px">';
        if ( $csrfToken !== null ) {
            $html .= '<input type="hidden" name="token" value="' . htmlspecialchars( $csrfToken ) . '">';
        }
        $selectId = 'labki-repo-select';
        $html .= '<div class="cdx-field" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        $html .= '<label class="cdx-label" for="' . $selectId . '">' . htmlspecialchars( wfMessage( 'labkipackmanager-repo-select-label' )->text() ) . '</label>';
        $html .= '<span class="cdx-select">';
        $html .= '<select id="' . $selectId . '" class="cdx-select__input" name="repo">';
        foreach ( $sources as $label => $_info ) {
            $sel = ( $label === $selectedLabel ) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars( $label ) . '"' . $sel . '>' . htmlspecialchars( $label ) . '</option>';
        }
        $html .= '</select>';
        $html .= '</span>';
        $html .= '<button class="cdx-button" type="submit" name="load" value="1">' . htmlspecialchars( $loadLabel ) . '</button>';
        $html .= '<button class="cdx-button cdx-button--action-progressive" type="submit" name="refresh" value="1">' . htmlspecialchars( $refreshLabel ) . '</button>';
        $html .= '</div>';
        $html .= '</form>';
        return $html;
    }
}


