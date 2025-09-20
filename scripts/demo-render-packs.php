<?php

// Demo script: render HTML output similar to Special:LabkiPackManager without MediaWiki.
// Usage (Docker):
// docker run --rm -v "$PWD:/app" -w /app composer:2 php scripts/demo-render-packs.php > demo.html

require __DIR__ . '/../vendor/autoload.php';

use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Special\PackListRenderer;

$fixture = __DIR__ . '/../tests/fixtures/manifest.yml';
if ( !file_exists( $fixture ) ) {
    fwrite( STDERR, "Fixture not found: $fixture\n" );
    exit( 1 );
}

$yaml = file_get_contents( $fixture );
$parser = new ManifestParser();
$packs = $parser->parseRoot( $yaml );

$renderer = new PackListRenderer();
$csrf = 'demo-token';

$title = 'Available Content Packs';
$status = $renderer->renderStatusNotice( 'Showing demo data (no MediaWiki).' );
$refresh = $renderer->renderRefreshForm( $csrf, 'Grab Manifest' );
$list = $renderer->renderPacksList( $packs, $csrf );

echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>LabkiPackManager Demo</title>";
echo '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:24px} .mw-message-box{border:1px solid #c8ccd1;padding:8px 12px;background:#f8f9fa;border-radius:2px}</style>';
echo "</head><body>\n";
echo '<h1>LabkiPackManager (Demo)</h1>';
echo '<h2>' . htmlspecialchars( $title ) . '</h2>';
echo $status;
echo $refresh;
echo $list ?: '<p><em>No packs found.</em></p>';
echo "</body></html>\n";


