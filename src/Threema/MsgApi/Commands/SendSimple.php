<?php
/**
 * @author Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */


namespace Threema\MsgApi\Commands;

use Threema\MsgApi\Commands\CommandInterface;
use Threema\MsgApi\Commands\Results\SendSimpleResult;
use Threema\MsgApi\Receiver;

class SendSimple implements CommandInterface {
	/**
	 * @var string
	 */
	private $text;

	/**
	 * @var \Threema\MsgApi\Receiver
	 */
	private $receiver;

	/**
	 * @param \Threema\MsgApi\Receiver $receiver
	 * @param string $text
	 */
	public function __construct(Receiver $receiver, $text) {
		$this->text = $text;
		$this->receiver = $receiver;
	}

	/**
	 * @return string
	 */
	public function getText() {
		return $this->text;
	}

	/**
	 * @return array
	 */
	public function getParams() {
		$p = $this->receiver->getParams();
		$p['text'] = $this->getText();
		return $p;
	}

	/**
	 * @return string
	 */
	public function getPath() {
		return 'send_simple';
	}

	/**
	 * @param int $httpCode
	 * @param object $res
	 * @return SendSimpleResult
	 */
	public function parseResult($httpCode, $res){
		return new SendSimpleResult($httpCode, $res);
	}
}
