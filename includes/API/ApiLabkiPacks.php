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
use LabkiPackManager\Services\InstalledRegistry;
use LabkiPackManager\Util\SemVer;
use LabkiPackManager\Services\PreflightPlanner;
use LabkiPackManager\Services\PlanResolver;

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
		// Permission gate for refresh
		if ( $doRefresh && !$this->getAuthority()->isAllowed( 'labkipackmanager-manage' ) ) {
			$doRefresh = false;
		}
		$ttl = (int)$this->getConfig()->get( 'LabkiCacheTTL' );
		$store = new ManifestStore( $manifestUrl, null, $ttl > 0 ? $ttl : null );
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
        // Enrich nodes with installed state and updateAvailable
        $registry = new InstalledRegistry();
        $installedMap = $registry->getInstalledMap();
        foreach ( $vm['nodes'] as $key => &$node ) {
            if ( !is_array( $node ) || ($node['type'] ?? null) !== 'pack' ) { continue; }
            $id = isset($node['id']) && str_starts_with((string)$node['id'], 'pack:') ? substr((string)$node['id'], 5) : null;
            if ( !$id ) { continue; }
            // Build repo-scoped pack UID for lookup
            $repoUrl = ContentSourceHelper::getManifestUrlForLabel( $sources, $repoLabel );
            $packUid = sha1( $repoUrl . ':' . $id );
            $available = $node['version'] ?? null;
            $inst = $installedMap[$packUid]['version'] ?? null;
            $node['installed'] = $inst !== null;
            $node['installedVersion'] = $inst;
            $node['updateAvailable'] = ($inst !== null && $available !== null && SemVer::sameMajor( (string)$inst, (string)$available ) && SemVer::compare( (string)$available, (string)$inst ) > 0);
            $node['packUid'] = $packUid;
        }
        unset($node);
		$resolver = new SelectionResolver();
		$diagram = $mermaid->generateWithIdMap( (function() use ( $graphInfo ) {
			$edges = [];
			foreach ( $graphInfo['containsEdges'] as $e ) {
				// Always prefix page nodes with 'page:' so idMap keys are consistent
				$edges[] = [ 'from' => 'pack:' . $e['from'], 'to' => 'page:' . $e['to'], 'rel' => 'contains' ];
			}
			foreach ( $graphInfo['dependsEdges'] as $e ) {
				$edges[] = [ 'from' => 'pack:' . $e['from'], 'to' => 'pack:' . $e['to'], 'rel' => 'depends' ];
			}
			return $edges;
		})() );

        // Build combined edges with rel (client still consumes this for local closure calc)
        $edges = [];
        foreach ( $graphInfo['containsEdges'] as $e ) {
            $edges[] = [ 'from' => 'pack:' . $e['from'], 'to' => 'page:' . $e['to'], 'rel' => 'contains' ];
        }
        foreach ( $graphInfo['dependsEdges'] as $e ) {
            $edges[] = [ 'from' => 'pack:' . $e['from'], 'to' => 'pack:' . $e['to'], 'rel' => 'depends' ];
        }

        $selected = $this->getRequest()->getArray( 'selected' ) ?: [];
        $preview = [];
        $preflight = null;
        if ( $selected ) {
            $preview = $resolver->resolveWithLocks( $domain['packs'], $selected );
            // Pre-flight summary based on current wiki state
            $planner = new PreflightPlanner();
            $preflight = $planner->plan( [ 'packs' => $preview['packs'], 'pages' => $preview['pages'], 'pageOwners' => $preview['pageOwners'] ?? [], 'repoUrl' => $manifestUrl ] );
            // Optional mapping input for plan (rename/prefix). Accept as JSON in 'plan' param.
            $rawPlan = $this->getRequest()->getVal( 'plan' );
            $plan = null;
            $defaultPrefix = (string)$this->getConfig()->get( 'LabkiGlobalPrefix' );
            if ( is_string( $rawPlan ) && $rawPlan !== '' ) {
                $decoded = json_decode( $rawPlan, true );
                if ( is_array( $decoded ) ) {
                    if ( !isset( $decoded['globalPrefix'] ) && $defaultPrefix !== '' ) { $decoded['globalPrefix'] = $defaultPrefix; }
                    $plan = ( new PlanResolver() )->resolve( [ 'packs' => $preview['packs'], 'pages' => $preview['pages'] ], $decoded, [ 'lists' => $preflight['lists'] ?? [] ] );
                }
            } elseif ( $defaultPrefix !== '' ) {
                $plan = ( new PlanResolver() )->resolve( [ 'packs' => $preview['packs'], 'pages' => $preview['pages'] ], [ 'globalPrefix' => $defaultPrefix ], [ 'lists' => $preflight['lists'] ?? [] ] );
            }
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
            'preflight' => $preflight,
            'plan' => $plan,
			'dataVersion' => 1,
		];
		if ( isset( $domain['errorKey'] ) ) {
			$payload['errorKey'] = $domain['errorKey'];
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $payload );
	}

	public function getAllowedParams() {
		return [
			'repo' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
			'selected' => [ self::PARAM_TYPE => 'string', self::PARAM_ISMULTI => true, self::PARAM_DFLT => [] ],
			'refresh' => [ self::PARAM_TYPE => 'boolean', self::PARAM_DFLT => false ],
            'plan' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
		];
	}

	public function isInternal() {
		return false;
	}
}


