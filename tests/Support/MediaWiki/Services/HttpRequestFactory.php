<?php

namespace MediaWiki\Services;

use MediaWiki\Status\StatusValue;

class HttpRequestFactory {
	private ?array $nextResponse = null;

	public function setNextResponse( int $statusCode, string $body, bool $ok = true ) : void {
		$this->nextResponse = [ 'code' => $statusCode, 'body' => $body, 'ok' => $ok ];
	}

	public function create( string $url, array $options = [] ) : HttpRequestStub {
		$resp = $this->nextResponse ?? [ 'code' => 200, 'body' => '', 'ok' => true ];
		return new HttpRequestStub( $resp['code'], $resp['body'], $resp['ok'] );
	}
}

class HttpRequestStub {
	private int $statusCode;
	private string $body;
	private bool $ok;

	public function __construct( int $statusCode, string $body, bool $ok ) {
		$this->statusCode = $statusCode;
		$this->body = $body;
		$this->ok = $ok;
	}

	public function execute() : StatusValue {
		return $this->ok ? StatusValue::newGood( null ) : StatusValue::newFatal( 'http-error' );
	}

	public function getStatus() : int {
		return $this->statusCode;
	}

	public function getContent() : string {
		return $this->body;
	}
}


