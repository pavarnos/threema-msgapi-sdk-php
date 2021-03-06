<?php
/**
 * @author    Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */

declare(strict_types=1);

namespace Threema\MsgApi\Encryptor;

use Threema\MsgApi\Exceptions\BadMessageException;
use Threema\MsgApi\Exceptions\DecryptionFailedException;
use Threema\MsgApi\Exceptions\UnsupportedMessageTypeException;
use Threema\MsgApi\Helpers\EncryptResult;
use Threema\MsgApi\Helpers\FileAnalysisResult;
use Threema\MsgApi\Helpers\KeyPair;
use Threema\MsgApi\Message\AbstractMessage;
use Threema\MsgApi\Message\DeliveryReceipt;
use Threema\MsgApi\Message\FileMessage;
use Threema\MsgApi\Message\ImageMessage;
use Threema\MsgApi\Message\LocationMessage;
use Threema\MsgApi\Message\TextMessage;
use Threema\MsgApi\Response\UploadFileResponse;

/**
 * Contains static methods to do various Threema cryptography related tasks.
 *
 * @package Threema\MsgApi\Tool
 */
abstract class AbstractEncryptor
{
    const TYPE_SODIUM = 'sodium';

    const FILE_NONCE           = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01";
    const FILE_THUMBNAIL_NONCE = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x02";

    const MESSAGE_ID_LEN = 8;

    const BLOB_ID_LEN = 16;

    const IMAGE_FILE_SIZE_LEN = 4;

    const IMAGE_NONCE_LEN = 24;

    const EMAIL_HMAC_KEY = "\x30\xa5\x50\x0f\xed\x97\x01\xfa\x6d\xef\xdb\x61\x08\x41\x90\x0f\xeb\xb8\xe4\x30\x88\x1f\x7a\xd8\x16\x82\x62\x64\xec\x09\xba\xd7";

    const PHONENO_HMAC_KEY = "\x85\xad\xf8\x22\x69\x53\xf3\xd9\x6c\xfd\x5d\x09\xbf\x29\x55\x5e\xb9\x55\xfc\xd8\xaa\x5e\xc4\xf9\xfc\xd8\x69\xe2\x58\x37\x07\x23";

    /**
     * Encrypt a text message.
     *
     * @param string $text               the text to be encrypted (max. 3500 bytes)
     * @param string $senderPrivateKey   the private key of the sending ID
     * @param string $recipientPublicKey the public key of the receiving ID
     * @param string $nonce              the nonce to be used for the encryption (usually 24 random bytes)
     * @return string encrypted box
     */
    final public function encryptMessageText($text, $senderPrivateKey, $recipientPublicKey, $nonce)
    {
        // @todo use TextMessage::TYPE_CODE
        /* prepend type byte (0x01) to message data */
        $textBytes = "\x01" . $text;

        /* determine random amount of PKCS7 padding */
        $padbytes = $this->generatePadBytes();

        /* append padding */
        $textBytes .= str_repeat(chr($padbytes), $padbytes);

        return $this->makeBox($textBytes, $nonce, $senderPrivateKey, $recipientPublicKey);
    }

    /**
     * @param UploadFileResponse $uploadFileResult   the result of the upload
     * @param EncryptResult      $encryptResult      the result of the image encryption
     * @param string             $senderPrivateKey   the private key of the sending ID (as binary)
     * @param string             $recipientPublicKey the public key of the receiving ID (as binary)
     * @param string             $nonce              the nonce to be used for the encryption (usually 24 random bytes)
     * @return string
     * @throws \Threema\MsgApi\Exceptions\Exception
     */
    final public function encryptImageMessage(
        UploadFileResponse $uploadFileResult,
        EncryptResult $encryptResult,
        $senderPrivateKey,
        $recipientPublicKey,
        $nonce)
    {
        $message = "\x02" . $this->hex2bin($uploadFileResult->getBlobId());
        $message .= pack('V', $encryptResult->getSize());
        $message .= $encryptResult->getNonce();

        /* determine random amount of PKCS7 padding */
        $padbytes = $this->generatePadBytes();

        /* append padding */
        $message .= str_repeat(chr($padbytes), $padbytes);

        return $this->makeBox($message, $nonce, $senderPrivateKey, $recipientPublicKey);
    }

    final public function encryptFileMessage(UploadFileResponse $uploadFileResult,
        EncryptResult $encryptResult,
        ?UploadFileResponse $thumbnailUploadFileResult,
        FileAnalysisResult $fileAnalysisResult,
        $senderPrivateKey,
        $recipientPublicKey,
        $nonce)
    {

        $messageContent = [
            'b' => $uploadFileResult->getBlobId(),
            'k' => $this->bin2hex($encryptResult->getKey()),
            'm' => $fileAnalysisResult->getMimeType(),
            'n' => $fileAnalysisResult->getFileName(),
            's' => $fileAnalysisResult->getSize(),
            'i' => 0,
        ];

        if ($thumbnailUploadFileResult !== null && strlen($thumbnailUploadFileResult->getBlobId()) > 0) {
            $messageContent['t'] = $thumbnailUploadFileResult->getBlobId();
        }

        $message = "\x17" . json_encode($messageContent);

        /* determine random amount of PKCS7 padding */
        $padbytes = $this->generatePadBytes();

        /* append padding */
        $message .= str_repeat(chr($padbytes), $padbytes);

        return $this->makeBox($message, $nonce, $senderPrivateKey, $recipientPublicKey);
    }

    /**
     * @param string $box
     * @param string $recipientPrivateKey
     * @param string $senderPublicKey
     * @param string $nonce
     * @return AbstractMessage the decrypted message
     * @throws BadMessageException
     * @throws DecryptionFailedException
     * @throws UnsupportedMessageTypeException
     * @throws \Threema\MsgApi\Exceptions\Exception
     */
    final public function decryptMessage($box, $recipientPrivateKey, $senderPublicKey, $nonce)
    {
        $data = $this->openBox($box, $recipientPrivateKey, $senderPublicKey, $nonce);

        if (empty($data)) {
            throw new DecryptionFailedException();
        }

        /* remove padding */
        $padbytes       = ord($data[strlen($data) - 1]);
        $realDataLength = strlen($data) - $padbytes;
        if ($realDataLength < 1) {
            throw new BadMessageException();
        }
        $data = substr($data, 0, $realDataLength);

        /* first byte of data is type */
        $type = ord($data[0]);

        $pos   = 1;
        $piece = function ($length) use (&$pos, $data) {
            $d   = substr($data, (int) $pos, $length);
            $pos += $length;
            return $d;
        };

        switch ($type) {
            case TextMessage::TYPE_CODE:
                /* Text message */
                if ($realDataLength < 2) {
                    throw new BadMessageException('text message is too short');
                }
                return new TextMessage(substr($data, 1));

            case DeliveryReceipt::TYPE_CODE:
                /* Delivery receipt */
                if ($realDataLength < (self::MESSAGE_ID_LEN - 2) || (($realDataLength - 2) % self::MESSAGE_ID_LEN) != 0) {
                    throw new BadMessageException();
                }

                $receiptType  = ord($data[1]);
                $messageIds   = str_split(substr($data, 2), self::MESSAGE_ID_LEN);
                $messageIdHex = array_map([$this, 'bin2hex'], $messageIds);
                return new DeliveryReceipt($receiptType, $messageIdHex);

            case ImageMessage::TYPE_CODE:
                /* Image Message */
                if ($realDataLength != 1 + self::BLOB_ID_LEN + self::IMAGE_FILE_SIZE_LEN + self::IMAGE_NONCE_LEN) {
                    throw new BadMessageException();
                }

                $blobId = $piece->__invoke(self::BLOB_ID_LEN);
                $length = $piece->__invoke(self::IMAGE_FILE_SIZE_LEN);
                $nonce  = $piece->__invoke(self::IMAGE_NONCE_LEN); // is this binary or hex?
                return new ImageMessage($this->bin2hex($blobId), (int) $this->bin2hex($length), $this->bin2hex($nonce));

            case FileMessage::TYPE_CODE:
                /* File Message */
                $values = json_decode(substr($data, 1), true);
                if (empty($values)) {
                    throw new BadMessageException('json badly formatted');
                }

                return new FileMessage(
                    $values['b'],
                    $values['t'],
                    $values['k'],
                    $values['m'],
                    $values['n'],
                    $values['s']);

            case LocationMessage::TYPE_CODE:
                $lines = explode("\n", substr($data,1));
                $points = explode(',',$lines[0] ?? '');
                if (count($points) < 2) {
                    throw new BadMessageException('invalid latitude and longitude');
                }
                array_shift($lines); // to get the address parts
                return new LocationMessage($points[0], $points[1], intval($points[2] ?? 0), $lines);

            default:
                throw new UnsupportedMessageTypeException();
        }
    }

    /**
     * Generate a new key pair.
     *
     * @return KeyPair the new key pair
     */
    abstract public function generateKeyPair();

    /**
     * Hashes an email address for identity lookup.
     *
     * @param string $email the email address
     * @return string the email hash (hex)
     */
    final public function hashEmail($email)
    {
        $emailClean = strtolower(trim($email));
        return hash_hmac('sha256', $emailClean, self::EMAIL_HMAC_KEY);
    }

    /**
     * Hashes an phone number address for identity lookup.
     *
     * @param string $phoneNo the phone number (in E.164 format, no leading +)
     * @return string the phone number hash (hex)
     */
    final public function hashPhoneNo($phoneNo)
    {
        $phoneNoClean = preg_replace("/[^0-9]/", "", $phoneNo);
        return hash_hmac('sha256', $phoneNoClean, self::PHONENO_HMAC_KEY);
    }

    /**
     * Check the HMAC of an incoming Threema message request. Always do this before decrypting the message.
     *
     * @param string $threemaId
     * @param string $gatewayId
     * @param string $messageId
     * @param string $date
     * @param string $nonce  nonce as hex encoded string
     * @param string $box    box as hex encoded string
     * @param string $secret hex
     * @return string the message mac
     */
    public final function calculateMac(string $threemaId, string $gatewayId, string $messageId, string $date,
        string $nonce, string $box, string $secret): string
    {
        return hash_hmac('sha256', $threemaId . $gatewayId . $messageId . $date . $nonce . $box, $secret);
    }

    /**
     * Generate a random nonce.
     *
     * @return string random nonce
     */
    final public function randomNonce()
    {
        return $this->createRandom(24);
    }

    /**
     * Generate a symmetric key
     * @return mixed
     */
    final public function symmetricKey()
    {
        return $this->createRandom(32);
    }

    /**
     * Derive the public key
     *
     * @param string $privateKey as binary
     * @return string as binary
     */
    abstract public function derivePublicKey($privateKey);

    /**
     * Check if implementation supported
     * @return bool
     */
    abstract public function isSupported();

    /**
     * @param string $data
     * @return EncryptResult
     */
    public final function encryptFile($data)
    {
        $key = $this->symmetricKey();
        $box = $this->makeSecretBox($data, self::FILE_NONCE, $key);
        return new EncryptResult($box, $key, self::FILE_NONCE, strlen($box));
    }

    /**
     * @param string $data as binary
     * @param string $key  as binary
     * @return null|string
     */
    public final function decryptFile($data, $key)
    {
        return $this->openSecretBox($data, self::FILE_NONCE, $key);
    }

    /**
     * @param string $data
     * @param string $key
     * @return EncryptResult
     */
    public final function encryptFileThumbnail($data, $key)
    {
        $box = $this->makeSecretBox($data, self::FILE_THUMBNAIL_NONCE, $key);
        return new EncryptResult($box, $key, self::FILE_THUMBNAIL_NONCE, strlen($box));
    }

    public final function decryptFileThumbnail($data, $key)
    {
        return $this->openSecretBox($data, self::FILE_THUMBNAIL_NONCE, $key);
    }

    /**
     * @param string $imageData
     * @param string $privateKey as binary
     * @param string $publicKey  as binary
     * @return EncryptResult
     */
    public final function encryptImage($imageData, $privateKey, $publicKey)
    {
        $nonce = $this->randomNonce();

        $box = $this->makeBox(
            $imageData,
            $nonce,
            $privateKey,
            $publicKey
        );

        return new EncryptResult($box, '', $nonce, strlen($box));
    }

    /**
     * @param string $data       as binary
     * @param string $publicKey  as binary
     * @param string $privateKey as binary
     * @param string $nonce      as binary
     * @return string|null
     */
    public final function decryptImage($data, $publicKey, $privateKey, $nonce)
    {
        return $this->openBox($data,
            $privateKey,
            $publicKey,
            $nonce);
    }

    public function __toString()
    {
        return 'Encryptor ' . $this->getName();
    }

    /**
     * Converts a binary string to an hexadecimal string.
     *
     * This is the same as PHP s bin2hex() implementation, but it is resistant to
     * timing attacks.
     *
     * @param  string $binaryString The binary string to convert
     * @return string
     */
    public function bin2hex($binaryString)
    {
        return bin2hex($binaryString);
    }

    /**
     * Converts an hexadecimal string to a binary string.
     *
     * This is the same as PHP s hex2bin() implementation, but it is resistant to
     * timing attacks.
     * Note that the default implementation does not support $ignore currently and will
     * throw an error. Only when libsodium >= 0.22 is used, this is supported.
     *
     * @param  string      $hexString The hex string to convert
     * @param  string|null $ignore    (optional) Characters to ignore
     * @return string
     */
    public function hex2bin($hexString, $ignore = null)
    {
        assert($ignore == null, '$ignore parameter is not supported');
        return hex2bin($hexString) ?: '';
    }

    /**
     * @return string
     */
    abstract public function getName();

    /**
     * @return string
     */
    abstract public function getDescription();

    protected function __clone()
    {
    }

    /**
     * make a box
     *
     * @param string $data
     * @param string $nonce
     * @param string $senderPrivateKey
     * @param string $recipientPublicKey
     * @return string encrypted box
     */
    abstract protected function makeBox($data, $nonce, $senderPrivateKey, $recipientPublicKey);

    /**
     * make a secret box
     *
     * @param string $data
     * @param string $nonce
     * @param string $key
     * @return mixed
     */
    abstract protected function makeSecretBox($data, $nonce, $key);

    /**
     * decrypt a box
     *
     * @param string $box                 as binary
     * @param string $recipientPrivateKey as binary
     * @param string $senderPublicKey     as binary
     * @param string $nonce               as binary
     * @return string|null
     */
    abstract protected function openBox($box, $recipientPrivateKey, $senderPublicKey, $nonce);

    /**
     * decrypt a secret box
     *
     * @param string $box   as binary
     * @param string $nonce as binary
     * @param string $key   as binary
     * @return string as binary
     */
    abstract protected function openSecretBox($box, $nonce, $key);

    abstract protected function createRandom($size);

    /**
     * determine random amount of PKCS7 padding
     * @return int
     */
    private function generatePadBytes()
    {
        $padbytes = 0;
        while ($padbytes < 1 || $padbytes > 255) {
            $padbytes = ord($this->createRandom(1));
        }
        return $padbytes;
    }
}
