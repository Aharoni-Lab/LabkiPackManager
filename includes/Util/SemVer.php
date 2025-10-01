<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

final class SemVer {
    /** @return array{int,int,int} */
    public static function parse( ?string $v ): array {
        if ( $v === null || $v === '' ) { return [0,0,0]; }
        $v = trim( (string)$v );
        // strip leading v and anything after +/- (pre-release/build)
        $v = preg_replace( '/^[vV]/', '', $v );
        $v = preg_split( '/[+-]/', $v )[0] ?? $v;
        $parts = explode( '.', $v );
        $maj = isset($parts[0]) ? (int)preg_replace('/\D/','', $parts[0]) : 0;
        $min = isset($parts[1]) ? (int)preg_replace('/\D/','', $parts[1]) : 0;
        $pat = isset($parts[2]) ? (int)preg_replace('/\D/','', $parts[2]) : 0;
        return [ $maj, $min, $pat ];
    }

    public static function compare( ?string $a, ?string $b ): int {
        [ $A,$B,$C ] = self::parse( $a );
        [ $X,$Y,$Z ] = self::parse( $b );
        if ( $A !== $X ) return $A <=> $X;
        if ( $B !== $Y ) return $B <=> $Y;
        if ( $C !== $Z ) return $C <=> $Z;
        return 0;
    }

    public static function sameMajor( ?string $a, ?string $b ): bool {
        return self::parse($a)[0] === self::parse($b)[0];
    }
}


