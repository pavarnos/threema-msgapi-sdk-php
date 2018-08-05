<?php
/**
 * @author Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */


namespace Threema\MsgApi\Commands;

use Threema\MsgApi\Commands\CommandInterface;
use Threema\MsgApi\Commands\Results\FetchPublicKeyResult;

class FetchPublicKey implements CommandInterface {
	/**
	 * @var string
	 */
	private $threemaId;

	/**
	 * @param string $threemaId
	 */
	public function __construct($threemaId) {
		$this->threemaId = $threemaId;
	}

	/**
	 * @return array
	 */
	public function getParams() {
		return array();
	}

	/**
	 * @return string
	 */
	public function getPath() {
		return 'pubkeys/'.$this->threemaId;
	}

	/**
	 * @param int $httpCode
	 * @param object $res
	 * @return FetchPublicKeyResult
	 */
	public function parseResult($httpCode, $res){
		return new FetchPublicKeyResult($httpCode, $res);
	}
}
