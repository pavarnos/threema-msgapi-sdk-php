<?php
/**
 * @author    Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */

declare(strict_types=1);

namespace Threema\MsgApi\Request;

use Threema\MsgApi\Response\CreditsResponse;
use Threema\MsgApi\Response\Response;

class CreditsRequest implements RequestInterface
{
    /**
     * @return array
     */
    public function getParams(): array
    {
        return [];
    }

    public function getPath(): string
    {
        return 'credits';
    }

    /**
     * @param int    $httpCode
     * @param string $response
     * @return CreditsResponse
     */
    public function parseResult(int $httpCode, string $response): Response
    {
        return new CreditsResponse($httpCode, $response);
    }
}
