<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * Generates a base Mermaid graph diagram for packs.
 */
final class MermaidBuilder {
	/**
	 * @param array<array{from:string,to:string}> $edges
	 */
	public function generate( array $edges ): string {
		$lines = [ 'graph LR' ];
		foreach ( $edges as $e ) {
			$lines[] = $e['from'] . ' --> ' . $e['to'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Generate Mermaid with stable idMap for nodes.
	 * @param array<array{from:string,to:string,rel?:string}> $edges
	 * @return array{code:string,idMap:array<string,string>}
	 */
	public function generateWithIdMap( array $edges ): array {
		$idMap = [];
		$assign = function( string $key ) use ( &$idMap ) {
			if ( isset( $idMap[$key] ) ) { return $idMap[$key]; }
			$id = 'n' . ( count( $idMap ) + 1 );
			$idMap[$key] = $id;
			return $id;
		};
		$lines = [ 'graph LR' ];
		foreach ( $edges as $e ) {
			$from = $assign( $e['from'] );
			$to = $assign( $e['to'] );
			$lines[] = $from . ' --> ' . $to;
		}
		return [ 'code' => implode( "\n", $lines ), 'idMap' => $idMap ];
	}
}


