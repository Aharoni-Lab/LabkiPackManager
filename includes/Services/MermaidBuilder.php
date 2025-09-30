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
}


