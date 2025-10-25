<?php

declare(strict_types=1);

namespace LabkiPackManager\Special;

use MediaWiki\MediaWikiServices;
use SpecialPage;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Displays data from LabkiPackManager SQL tables.
 * For admin/debug use only.
 */
final class SpecialLabkiDBViewer extends SpecialPage {
    public function __construct() {
        parent::__construct('LabkiDBViewer');
    }

    public function getGroupName(): string {
        return 'labki';
    }

    public function getRestriction(): string {
        return 'labkipackmanager-manage';
    }

    public function execute($subPage): void {
        $this->checkPermissions();
        $config = $this->getConfig();

        if ( !$config->get('LabkiEnableDBViewer') ) {
            throw new \PermissionsError('labkipackmanager-error-dbviewer-disabled');
        }

        $output = $this->getOutput();
        $output->setPageTitle($this->msg('labkipackmanager-dbviewer-title')->text());
        $output->addModuleStyles('ext.LabkiPackManager.styles');

        $req = $this->getRequest();
        $table = $req->getText('table', 'labki_content_repo');
        $limit = (int)$req->getInt('limit', 50);

        $tables = ['labki_content_repo', 'labki_content_ref', 'labki_pack', 'labki_page', 'labki_operations'];
        if ( !in_array($table, $tables, true) ) {
            $output->addHTML('<p>Invalid table.</p>');
            return;
        }

        $titleAttr = htmlspecialchars($this->getPageTitle()->getPrefixedText());
        $output->addHTML('<form method="get">' .
            '<input type="hidden" name="title" value="' . $titleAttr . '" />' .
            'Table: <select name="table">' .
            implode('', array_map(
                fn($t) => "<option value=\"$t\"" . ($t === $table ? ' selected' : '') . ">$t</option>",
                $tables
            )) .
            '</select> ' .
            'Limit: <input type="number" name="limit" value="' . $limit . '" min="1" max="500" />' .
            '<input type="submit" value="View" />' .
            '</form>'
        );

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();

        // Try replica first
        $dbr = $lb->getConnection(DB_REPLICA);
        $res = $dbr->newSelectQueryBuilder()
            ->select('*')
            ->from($table)
            ->limit($limit)
            ->fetchResultSet();

        // If replica is empty, retry on primary
        if ( $res->numRows() === 0 ) {
            $output->addHTML('<p><em>No rows on replica — retrying on primary DB...</em></p>');
            $dbw = $lb->getConnection(DB_PRIMARY);
            $res = $dbw->newSelectQueryBuilder()
                ->select('*')
                ->from($table)
                ->limit($limit)
                ->fetchResultSet();
        }

        $this->renderTable($output, $res);
    }

    private function renderTable($output, IResultWrapper $res): void {
        if ( $res->numRows() === 0 ) {
            $output->addHTML('<p>No records found.</p>');
            return;
        }

        $headers = array_keys((array)$res->current());
        $html = '<table class="wikitable sortable"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($res as $row) {
            $html .= '<tr>';
            foreach ($headers as $h) {
                $val = $row->$h;
                $html .= '<td>' . htmlspecialchars((string)$val) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $output->addHTML($html);
    }
}
