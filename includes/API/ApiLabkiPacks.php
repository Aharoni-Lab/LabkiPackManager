<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\ManifestLoader;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\MermaidBuilder;
use LabkiPackManager\Services\SelectionResolver;
use LabkiPackManager\Services\ContentSourceHelper;
use LabkiPackManager\Services\ManifestStore;

final class ApiLabkiPacks extends ApiBase {
	public function __construct( ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
	}

	public function execute() {
		$manifestUrl = (string)$this->getConfig()->get( 'LabkiContentSources' )['Lab Packs (Default)' ] ?? '';
		$loader = new ManifestLoader();
		$graph = new GraphBuilder();
		$hierarchy = new HierarchyBuilder();
		$mermaid = new MermaidBuilder();

		// Repo selection
		$sources = ContentSourceHelper::getSources();
		$requestedRepo = $this->getRequest()->getVal( 'repo' );
		$repoLabel = ContentSourceHelper::resolveSelectedRepoLabel( $sources, $requestedRepo );
		$manifestUrl = ContentSourceHelper::getManifestUrlForLabel( $sources, $repoLabel );

		$doRefresh = $this->getRequest()->getCheck( 'refresh' );
		$store = new ManifestStore( $manifestUrl );
		$usedCache = false;
		$fetchedAt = null;
		$domain = [ 'schema_version' => null, 'packs' => [] ];
		if ( $doRefresh ) {
			$domain = $loader->loadFromUrl( $manifestUrl, true );
			$store->savePacks( $domain['packs'], [ 'schema_version' => $domain['schema_version'], 'manifest_url' => $manifestUrl ] );
		} else {
			$cached = $store->getPacksOrNull();
			$meta = $store->getMetaOrNull();
			if ( is_array( $cached ) ) {
				$domain = [ 'schema_version' => $meta['schema_version'] ?? null, 'packs' => $cached ];
				$usedCache = true;
				$fetchedAt = $meta['fetched_at'] ?? null;
			} else {
				$domain = $loader->loadFromUrl( $manifestUrl, false );
				$store->savePacks( $domain['packs'], [ 'schema_version' => $domain['schema_version'], 'manifest_url' => $manifestUrl ] );
			}
		}
		$graphInfo = $graph->build( $domain['packs'] );
		$vm = $hierarchy->buildViewModel( $domain['packs'] );
		$resolver = new SelectionResolver();
		$diagram = $mermaid->generateWithIdMap( array_map( static function( $e ) {
			return [ 'from' => 'pack:' . $e['from'], 'to' => 'pack:' . $e['to'], 'rel' => 'depends' ];
		}, $graphInfo['dependsEdges'] ) );

		// Build combined edges with rel
		$edges = [];
		foreach ( $graphInfo['containsEdges'] as $e ) {
			$edges[] = [ 'from' => 'pack:' . $e['from'], 'to' => ( strpos( $e['to'], ':' ) === false ? 'page:' . $e['to'] : $e['to'] ), 'rel' => 'contains' ];
		}
		foreach ( $graphInfo['dependsEdges'] as $e ) {
			$edges[] = [ 'from' => 'pack:' . $e['from'], 'to' => 'pack:' . $e['to'], 'rel' => 'depends' ];
		}

		$selected = $this->getRequest()->getArray( 'selected' ) ?: [];
		$preview = [];
		if ( $selected ) {
			$preview = $resolver->resolveWithLocks( $domain['packs'], $selected );
		}

		$payload = [
			'source' => [ 'name' => $repoLabel, 'branch' => $this->getConfig()->get( 'LabkiDefaultBranch' ), 'commit' => null ],
			'sources' => array_keys( $sources ),
			'refresh' => $doRefresh,
			'status' => [ 'usingCache' => $usedCache, 'fetchedAt' => $fetchedAt ],
			'schemaVersion' => $domain['schema_version'],
			'summary' => [ 'packCount' => $vm['packCount'], 'pageCount' => $vm['pageCount'], 'roots' => $vm['roots'] ],
			'nodes' => $vm['nodes'],
			'tree' => $vm['tree'],
			'edges' => $edges,
			'mermaid' => [ 'code' => $diagram['code'], 'idMap' => $diagram['idMap'] ],
			'preview' => $preview,
			'dataVersion' => 1,
		];
		if ( isset( $domain['errorKey'] ) ) {
			$payload['errorKey'] = $domain['errorKey'];
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $payload );
	}

	public function isInternal() {
		return false;
	}
}


