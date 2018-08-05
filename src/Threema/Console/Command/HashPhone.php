<?php
/**
 * @author Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */


namespace Threema\Console\Command;

use Threema\Console\Common;
use Threema\MsgApi\Tools\CryptTool;

class HashPhone extends Base {
	const argPhoneNo = 'phoneNo';

	public function __construct() {
		parent::__construct('Hash Phone Number',
			array(self::argPhoneNo),
			'Hash a phone number for identity lookup. Prints the hash in hex.');
	}

	protected function doRun() {
		$phoneNo = $this->getArgument(self::argPhoneNo);
		Common::required($phoneNo);
		$hashedPhoneNo = CryptTool::getInstance()->hashPhoneNo($phoneNo);
		Common::l($hashedPhoneNo);
	}
}
