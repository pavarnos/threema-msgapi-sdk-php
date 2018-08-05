<?php
/**
 * @author Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */


namespace Threema\Console\Command;

use Threema\Console\Common;
use Threema\MsgApi\Connection;
use Threema\MsgApi\ConnectionSettings;
use Threema\MsgApi\PublicKeyStore;

class LookupIdByEmail extends Base {
	const argEmail = 'email';

	/**
	 * @var PublicKeyStore
	 */
	private $publicKeyStore;

	/**
	 * @param PublicKeyStore $publicKeyStore
	 */
	public function __construct(PublicKeyStore $publicKeyStore) {
		parent::__construct('ID-Lookup By Email Address',
			array(self::argEmail, self::argFrom, self::argSecret),
			'Lookup the ID linked to the given email address (will be hashed locally).');
		$this->publicKeyStore = $publicKeyStore;
	}

	protected function doRun() {
		$email = $this->getArgument(self::argEmail);
		$from = $this->getArgumentThreemaId(self::argFrom);
		$secret = $this->getArgument(self::argSecret);

		Common::required($email, $from, $secret);

		//define connection settings
		$settings = new ConnectionSettings($from, $secret);

		//create a connection
		$connector = new Connection($settings, $this->publicKeyStore);

		$result = $connector->keyLookupByEmail($email);;
		Common::required($result);
		if($result->isSuccess()) {
			Common::l($result->getId());
		}
		else {
			Common::e($result->getErrorMessage());
		}
	}
}
