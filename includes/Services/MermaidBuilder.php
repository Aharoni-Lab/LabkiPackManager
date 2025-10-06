<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

// This likely can get moved into frontend
/**
 * Generates Mermaid diagrams for pack dependency and containment graphs.
 */
final class MermaidBuilder {

    /**
     * Generate a minimal Mermaid graph (no styling, no node mapping).
     *
     * @param array<array{from:string,to:string}> $edges
     * @return string Mermaid source string
     */
    public function generate(array $edges): string {
        $lines = ['graph LR'];
        foreach ($edges as $e) {
            $lines[] = "{$e['from']} --> {$e['to']}";
        }
        return implode("\n", $lines);
    }

    /**
     * Generate a Mermaid graph with a stable node ID map for consistent rendering.
     *
     * @param array<array{from:string,to:string,rel?:string}> $edges
     * @return array{code:string,idMap:array<string,string>}
     */
    public function generateWithIdMap(array $edges): array {
        $idMap = [];
        $assign = function (string $key) use (&$idMap): string {
            return $idMap[$key] ??= 'n' . (count($idMap) + 1);
        };

        $lines = ['graph LR'];
        $nodeDefs = [];
        $edgeDefs = [];

        foreach ($edges as $e) {
            $fromKey = (string)$e['from'];
            $toKey = (string)$e['to'];
            $from = $assign($fromKey);
            $to = $assign($toKey);
            $rel = $e['rel'] ?? '';

            $edgeDefs[] = $from . ($rel === 'depends' ? ' -.-> ' : ' --> ') . $to;
            $nodeDefs[$fromKey] = true;
            $nodeDefs[$toKey] = true;
        }

        foreach (array_keys($nodeDefs) as $key) {
            $id = $idMap[$key];
            $label = preg_replace('/^(pack:|page:)/', '', $key);
            $isPack = str_starts_with($key, 'pack:');
            $shape = $isPack ? "([{$label}]):::pack" : "[{$label}]:::page";
            $lines[] = "{$id}{$shape}";
        }

        $lines = array_merge($lines, $edgeDefs);
        $lines[] = 'classDef pack fill:#eef7ff,stroke:#4682b4,color:#1f2937;';
        $lines[] = 'classDef page fill:#f8fafc,stroke:#94a3b8,color:#111827;';

        return [
            'code' => implode("\n", $lines),
            'idMap' => $idMap
        ];
    }
}
