<?php

declare(strict_types=1);

namespace LabkiPackManager\Import;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Logger\LoggerFactory;
use UserIdentity;

/**
 * Writes/updates pages only. I/O layer for imports (Phase 5 will implement).
 */
final class PackImporter {
	public function __construct() {}

	/**
	 * Placeholder import function. Will be implemented in later phases.
	 * @param array $pagesToImport
	 * @return array{created:int, updated:int}
	 */
	public function import( array $pagesToImport ): array {
		// Backward-compat placeholder behavior
		return [ 'created' => 0, 'updated' => 0 ];
	}

	/**
	 * Import a pack's pages and persist registry + page props.
	 *
	 * @param string $packId
	 * @param string|null $packVersion
	 * @param array<int,array{title:string,namespace?:int,text:string,page_key?:string}>
	 * @param array{source_repo?:?string,source_ref?:?string,source_commit?:?string} $source
	 * @param UserIdentity|null $performer
	 * @return array{created:int, updated:int}
	 */
	public function importPack( string $packId, ?string $packVersion, array $pages, array $source = [], ?UserIdentity $performer = null ): array {
		$services = MediaWikiServices::getInstance();
		$titleFactory = $services->getTitleFactory();
		$wikiPageFactory = $services->getWikiPageFactory();
		$contentHandler = $services->getContentHandlerFactory()->getContentHandler( CONTENT_MODEL_WIKITEXT );
		$revLookup = $services->getRevisionLookup();
		$user = $performer ?? $services->getUserFactory()->newSystemUser( 'LabkiPackManager', [ 'steal' => true ] );
		$lb = $services->getDBLoadBalancer();
		$dbw = $lb->getConnection( DB_PRIMARY );
		$logger = LoggerFactory::getInstance( 'LabkiPackManager' );

		$created = 0; $updated = 0;
		$lastRevIdPerPage = [];

		foreach ( $pages as $p ) {
			$titleText = (string)$p['title'];
			$ns = isset( $p['namespace'] ) ? (int)$p['namespace'] : NS_MAIN;
			$text = (string)$p['text'];
			$pageKey = isset( $p['page_key'] ) ? (string)$p['page_key'] : $titleText;

			$title = $titleFactory->makeTitleSafe( $ns, $titleText );
			if ( !$title ) { continue; }
			$wikiPage = $wikiPageFactory->newFromTitle( $title );

			$normalized = self::normalizeText( $text );
			$hash = hash( 'sha256', $normalized );

			// Compare existing revision hash to avoid no-op saves
			$existingRev = $revLookup->getRevisionByTitle( $title );
			$existingHash = null;
			if ( $existingRev ) {
				$content = $existingRev->getContent( SlotRecord::MAIN );
				$existingText = $content ? ContentHandler::getContentText( $content ) : '';
				$existingHash = hash( 'sha256', self::normalizeText( (string)$existingText ) );
			}

			if ( $existingHash !== null && $existingHash === $hash ) {
				// No change; still update page props and registry to reflect ownership
				$lastRevIdPerPage[$title->getPrefixedText()] = $existingRev ? (int)$existingRev->getId() : 0;
				self::writePageProps( $dbw, (int)$title->getArticleID(), [
					'labki.pack_id' => $packId,
					'labki.pack_version' => (string)$packVersion,
					'labki.source_repo' => (string)($source['source_repo'] ?? ''),
					'labki.source_ref' => (string)($source['source_ref'] ?? ''),
					'labki.source_commit' => (string)($source['source_commit'] ?? ''),
					'labki.page_key' => $pageKey,
					'labki.content_hash' => $hash,
				] );
				$logger->info( 'Registry props refreshed for unchanged page {title}', [ 'title' => $title->getPrefixedText() ] );
				continue;
			}

			// Save new revision
			$contentObj = $contentHandler->makeContent( $normalized, $title );
			$updater = $wikiPage->newPageUpdater( $user );
			$updater->setContent( SlotRecord::MAIN, $contentObj );
			$updater->setRcPatrolStatus( false );
			$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Imported by LabkiPackManager' ) );
			$newRev = $updater->getNewRevision();
			$revId = $newRev ? (int)$newRev->getId() : 0;
			$pageId = (int)$title->getArticleID();
			$lastRevIdPerPage[$title->getPrefixedText()] = $revId;

			self::writePageProps( $dbw, $pageId, [
				'labki.pack_id' => $packId,
				'labki.pack_version' => (string)$packVersion,
				'labki.source_repo' => (string)($source['source_repo'] ?? ''),
				'labki.source_ref' => (string)($source['source_ref'] ?? ''),
				'labki.source_commit' => (string)($source['source_commit'] ?? ''),
				'labki.page_key' => $pageKey,
				'labki.content_hash' => $hash,
			] );

			if ( $existingRev ) { $updated++; $logger->info( 'Updated page {title} rev {rev}', [ 'title' => $title->getPrefixedText(), 'rev' => $revId ] ); }
			else { $created++; $logger->info( 'Created page {title} rev {rev}', [ 'title' => $title->getPrefixedText(), 'rev' => $revId ] ); }
		}

		// Upsert registry for pack and pages
		$dbw->upsert(
			'labki_pack_registry',
			[ 'pack_id' => $packId, 'version' => $packVersion, 'source_repo' => $source['source_repo'] ?? null, 'source_ref' => $source['source_ref'] ?? null, 'source_commit' => $source['source_commit'] ?? null, 'installed_at' => time(), 'installed_by' => $user->getId() ],
			[ 'pack_id' ],
			[ 'version' => $packVersion, 'source_repo' => $source['source_repo'] ?? null, 'source_ref' => $source['source_ref'] ?? null, 'source_commit' => $source['source_commit'] ?? null, 'installed_at' => time(), 'installed_by' => $user->getId() ]
		);
		foreach ( $pages as $p ) {
			$titleText = (string)$p['title'];
			$ns = isset( $p['namespace'] ) ? (int)$p['namespace'] : NS_MAIN;
			$title = $services->getTitleFactory()->makeTitleSafe( $ns, $titleText );
			if ( !$title ) { continue; }
			$pageId = (int)$title->getArticleID();
			$prefixed = $title->getPrefixedText();
			$revId = (int)($lastRevIdPerPage[$prefixed] ?? 0);
			$hash = hash( 'sha256', self::normalizeText( (string)$p['text'] ) );
			$dbw->upsert(
				'labki_pack_pages',
				[
					'pack_id' => $packId,
					'page_title' => $prefixed,
					'page_namespace' => $ns,
					'page_id' => $pageId,
					'last_rev_id' => $revId,
					'content_hash' => $hash,
				],
				[ [ 'pack_id', 'page_title' ] ],
				[ 'page_namespace' => $ns, 'page_id' => $pageId, 'last_rev_id' => $revId, 'content_hash' => $hash ]
			);
		}

		return [ 'created' => $created, 'updated' => $updated ];
	}

	private static function normalizeText( string $text ): string {
		$norm = preg_replace( "/\r\n?|\u2028|\u2029/", "\n", $text );
		$norm = preg_replace( '/[ \t]+\n/', "\n", (string)$norm );
		return (string)$norm;
	}

	/**
	 * @param array<string,string> $props
	 */
	private static function writePageProps( $dbw, int $pageId, array $props ): void {
		foreach ( $props as $name => $value ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'page_props' )
				->where( [ 'pp_page' => $pageId, 'pp_propname' => $name ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->newInsertQueryBuilder()
				->insertInto( 'page_props' )
				->row( [ 'pp_page' => $pageId, 'pp_propname' => $name, 'pp_value' => (string)$value ] )
				->caller( __METHOD__ )
				->execute();
		}
	}
}


