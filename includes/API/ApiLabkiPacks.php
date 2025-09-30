<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\ManifestLoader;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\MermaidBuilder;

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

		$domain = $loader->loadFromUrl( $manifestUrl );
		$graphInfo = $graph->build( $domain['packs'] );
		$vm = $hierarchy->buildViewModel( $domain['packs'] );
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

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'source' => [ 'name' => 'LabkiContentSources', 'branch' => $this->getConfig()->get( 'LabkiDefaultBranch' ), 'commit' => null ],
			'schemaVersion' => $domain['schema_version'],
			'summary' => [ 'packCount' => $vm['packCount'], 'pageCount' => $vm['pageCount'], 'roots' => $vm['roots'] ],
			'nodes' => $vm['nodes'],
			'tree' => $vm['tree'],
			'edges' => $edges,
			'mermaid' => [ 'code' => $diagram['code'], 'idMap' => $diagram['idMap'] ],
			'dataVersion' => 1,
		] );
	}

	public function isInternal() {
		return false;
	}
}


