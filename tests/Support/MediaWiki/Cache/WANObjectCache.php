<?php

namespace MediaWiki\Cache;

class WANObjectCache {
	private array $store = [];

	public function get( string $key ) {
		return $this->store[$key] ?? null;
	}

	public function set( string $key, $value, int $ttl ) : void {
		$this->store[$key] = $value;
	}

	public function delete( string $key ) : void {
		unset( $this->store[$key] );
	}
}


