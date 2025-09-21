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
        return '<div class="mw-message-box mw-message-box-notice">' . $statusNote . '</div>';
    }

    /**
     * Render the refresh-manifest form with a submit button.
     */
    public function renderRefreshForm( string $csrfToken, string $buttonLabel ) : string {
        $html = '<form method="post" style="margin-bottom:12px">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars( $csrfToken ) . '">';
        $html .= '<button class="mw-htmlform-submit" type="submit" name="refresh" value="1">' .
            htmlspecialchars( $buttonLabel ) . '</button>';
        $html .= '</form>';
        return $html;
    }

    /**
     * Render the selectable list of packs with checkboxes.
     * Expects $packs entries with keys: id, description, version.
     */
    public function renderPacksList( array $packs, string $csrfToken ) : string {
        if ( !is_array( $packs ) || !$packs ) {
            return '';
        }
        $html = '<form method="post">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars( $csrfToken ) . '">';
        foreach ( $packs as $p ) {
            $id = htmlspecialchars( (string)( $p['id'] ?? '' ) );
            $desc = htmlspecialchars( (string)( $p['description'] ?? '' ) );
            $version = htmlspecialchars( (string)( $p['version'] ?? '' ) );
            if ( $id === '' ) {
                continue;
            }
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
        return $html;
    }
}


