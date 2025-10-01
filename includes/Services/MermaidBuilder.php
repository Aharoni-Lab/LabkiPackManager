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
		$nodeDefs = [];
		$edgeDefs = [];
		// Collect nodes and edge lines with styles
		foreach ( $edges as $e ) {
			$fromKey = (string)$e['from'];
			$toKey = (string)$e['to'];
			$from = $assign( $fromKey );
			$to = $assign( $toKey );
			$rel = $e['rel'] ?? '';
			$edgeDefs[] = $from . ( $rel === 'depends' ? ' -.-> ' : ' --> ' ) . $to;
			$nodeDefs[$fromKey] = true;
			$nodeDefs[$toKey] = true;
		}
		// Define nodes with basic shapes and classes
		foreach ( array_keys( $nodeDefs ) as $key ) {
			$id = $idMap[$key];
			$label = preg_replace( '/^(pack:|page:)/', '', $key );
			if ( str_starts_with( $key, 'pack:' ) ) {
				$lines[] = $id . '([' . $label . ']):::pack';
			} else {
				$lines[] = $id . '[' . $label . ']:::page';
			}
		}
		$lines = array_merge( $lines, $edgeDefs );
		// Base classes (colors kept minimal; client will add dynamic classes)
		$lines[] = 'classDef pack fill:#eef7ff,stroke:#4682b4,color:#1f2937;';
		$lines[] = 'classDef page fill:#f8fafc,stroke:#94a3b8,color:#111827;';
		return [ 'code' => implode( "\n", $lines ), 'idMap' => $idMap ];
	}
}


