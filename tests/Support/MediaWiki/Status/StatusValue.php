<?php

namespace MediaWiki\Status;

class StatusValue {
	private bool $ok;
	private $value;
	private ?StatusMessage $message;

	private function __construct( bool $ok, $value = null, ?StatusMessage $message = null ) {
		$this->ok = $ok;
		$this->value = $value;
		$this->message = $message;
	}

	public static function newGood( $value = null ) : self {
		return new self( true, $value, null );
	}

	public static function newFatal( string $messageKey ) : self {
		return new self( false, null, new StatusMessage( $messageKey ) );
	}

	public function isOK() : bool {
		return $this->ok;
	}

	public function getValue() {
		return $this->value;
	}

	public function getMessage() : ?StatusMessage {
		return $this->message;
	}
}

class StatusMessage {
	private string $key;

	public function __construct( string $key ) {
		$this->key = $key;
	}

	public function getKey() : string {
		return $this->key;
	}
}


