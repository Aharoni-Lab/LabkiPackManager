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
		$edgesInfo = $graph->build( $domain['packs'] );
		$tree = $hierarchy->buildTree( $domain['packs'] );
		$diagram = $mermaid->generate( $edgesInfo['edges'] );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'schema' => $domain['schema_version'],
			'tree' => $tree,
			'edges' => $edgesInfo['edges'],
			'mermaid' => $diagram,
		] );
	}

	public function isInternal() {
		return false;
	}
}


