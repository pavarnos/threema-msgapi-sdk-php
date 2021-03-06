<?php
/**
 * @file
 * @author Lightly Salted Software Ltd
 * Date: 6/08/18
 */

declare(strict_types=1);

namespace Threema\MsgApi\Response;

use PHPUnit\Framework\TestCase;
use Threema\MsgApi\Request\LookupBulkRequest;
use Threema\MsgApi\ConnectionFactory;

class LookupBulkResultTest extends TestCase
{
    public function testGetMatches()
    {
        $factory = new ConnectionFactory();
        $encryptor = $factory->getEncryptor();
        $request   = new LookupBulkRequest(
            [$emailKey = 99 => $emailAddress = 'foo@example.com'],
            [$phoneKey = 17 => $phoneNumber = '641234567']);
        $request->calculateHashes($encryptor);
        $request->getJson();

        $responseData = [['identity'  => $id1 = 'ABCD1234',
                          'phoneHash' => $encryptor->hashPhoneNo($phoneNumber),
                          'publicKey' => $key1 = 'f00baa'],
                         ['identity'  => $id2 = 'EFGH5678',
                          'emailHash' => $encryptor->hashEmail($emailAddress),
                          'publicKey' => $key2 = 'abcd4567']];

        $subject = new LookupBulkResponse(200, json_encode($responseData), $request);
        $matches = $subject->getMatches();

        self::assertEquals(2, count($matches));

        // first ID
        $this->assertEquals($id1, $matches[$id1]->getIdentity());
        $this->assertEquals($key1, $matches[$id1]->getPublicKey());
        $this->assertEquals($phoneNumber, $matches[$id1]->getFirstPhone());
        $this->assertEquals('', $matches[$id1]->getFirstEmail());
        $this->assertEquals([$phoneKey => $phoneNumber], $matches[$id1]->getPhones());
        $this->assertEquals([], $matches[$id1]->getEmails());

        // second ID
        $this->assertEquals($id2, $matches[$id2]->getIdentity());
        $this->assertEquals($key2, $matches[$id2]->getPublicKey());
        $this->assertEquals('', $matches[$id2]->getFirstPhone());
        $this->assertEquals($emailAddress, $matches[$id2]->getFirstEmail());
        $this->assertEquals([], $matches[$id2]->getPhones());
        $this->assertEquals([$emailKey => $emailAddress], $matches[$id2]->getEmails());
    }
}
