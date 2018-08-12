<?php
/**
 * @author    Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */

namespace Threema\MsgApi\Tools;

use Threema\Core\AssocArray;
use Threema\Core\Exception;
use Threema\Core\KeyPair;
use Threema\MsgApi\Commands\Results\UploadFileResult;
use Threema\MsgApi\Exceptions\BadMessageException;
use Threema\MsgApi\Exceptions\DecryptionFailedException;
use Threema\MsgApi\Exceptions\UnsupportedMessageTypeException;
use Threema\MsgApi\Messages\DeliveryReceipt;
use Threema\MsgApi\Messages\FileMessage;
use Threema\MsgApi\Messages\ImageMessage;
use Threema\MsgApi\Messages\TextMessage;
use Threema\MsgApi\Messages\ThreemaMessage;

/**
 * Interface CryptTool
 * Contains static methods to do various Threema cryptography related tasks.
 *
 * @package Threema\MsgApi\Tool
 */
abstract class CryptTool
{
    const TYPE_SODIUM = 'sodium';
    const TYPE_SALT   = 'salt';

    const FILE_NONCE           = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01";
    const FILE_THUMBNAIL_NONCE = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x02";

    const MESSAGE_ID_LEN      = 8;

    const BLOB_ID_LEN         = 16;

    const IMAGE_FILE_SIZE_LEN = 4;

    const IMAGE_NONCE_LEN     = 24;

    const EMAIL_HMAC_KEY   = "\x30\xa5\x50\x0f\xed\x97\x01\xfa\x6d\xef\xdb\x61\x08\x41\x90\x0f\xeb\xb8\xe4\x30\x88\x1f\x7a\xd8\x16\x82\x62\x64\xec\x09\xba\xd7";

    const PHONENO_HMAC_KEY = "\x85\xad\xf8\x22\x69\x53\xf3\xd9\x6c\xfd\x5d\x09\xbf\x29\x55\x5e\xb9\x55\xfc\xd8\xaa\x5e\xc4\xf9\xfc\xd8\x69\xe2\x58\x37\x07\x23";

    /**
     * @var CryptTool
     */
    private static $instance = null;

    protected function __construct()
    {
    }

    /**
     * @return CryptTool
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new CryptToolSodium();
        }

        return self::$instance;
    }

    /**
     * @param string $type
     * @return null|CryptTool null on unknown type
     */
    public static function createInstance($type)
    {
        switch ($type) {
            case self::TYPE_SODIUM:
                $instance = new CryptToolSodium();
                break;
            default:
                return null;
        }
        return $instance->isSupported() ? $instance : null;
    }

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
     * @param UploadFileResult $uploadFileResult   the result of the upload
     * @param EncryptResult    $encryptResult      the result of the image encryption
     * @param string           $senderPrivateKey   the private key of the sending ID (as binary)
     * @param string           $recipientPublicKey the public key of the receiving ID (as binary)
     * @param string           $nonce              the nonce to be used for the encryption (usually 24 random bytes)
     * @return string
     * @throws \Threema\Core\Exception
     */
    final public function encryptImageMessage(
        UploadFileResult $uploadFileResult,
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

    final public function encryptFileMessage(UploadFileResult $uploadFileResult,
        EncryptResult $encryptResult,
        UploadFileResult $thumbnailUploadFileResult,
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
     * @return ThreemaMessage the decrypted message
     * @throws BadMessageException
     * @throws DecryptionFailedException
     * @throws UnsupportedMessageTypeException
     * @throws \Threema\Core\Exception
     */
    final public function decryptMessage($box, $recipientPrivateKey, $senderPublicKey, $nonce)
    {

        $data = $this->openBox($box, $recipientPrivateKey, $senderPublicKey, $nonce);

        if (null === $data || strlen($data) == 0) {
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
            $d   = substr($data, $pos, $length);
            $pos += $length;
            return $d;
        };

        switch ($type) {
            case TextMessage::TYPE_CODE:
                /* Text message */
                if ($realDataLength < 2) {
                    throw new BadMessageException();
                }

                return new TextMessage(substr($data, 1));
            case DeliveryReceipt::TYPE_CODE:
                /* Delivery receipt */
                if ($realDataLength < (self::MESSAGE_ID_LEN - 2) || (($realDataLength - 2) % self::MESSAGE_ID_LEN) != 0) {
                    throw new BadMessageException();
                }

                $receiptType = ord($data[1]);
                $messageIds  = str_split(substr($data, 2), self::MESSAGE_ID_LEN);

                return new DeliveryReceipt($receiptType, $messageIds);
            case ImageMessage::TYPE_CODE:
                /* Image Message */
                if ($realDataLength != 1 + self::BLOB_ID_LEN + self::IMAGE_FILE_SIZE_LEN + self::IMAGE_NONCE_LEN) {
                    throw new BadMessageException();
                }

                $blobId = $piece->__invoke(self::BLOB_ID_LEN);
                $length = $piece->__invoke(self::IMAGE_FILE_SIZE_LEN);
                $nonce  = $piece->__invoke(self::IMAGE_NONCE_LEN);
                return new ImageMessage($this->bin2hex($blobId), $this->bin2hex($length), $nonce);
            case FileMessage::TYPE_CODE:
                /* Image Message */
                $decodeResult = json_decode(substr($data, 1), true);
                if (null === $decodeResult || false === $decodeResult) {
                    throw new BadMessageException();
                }

                $values = AssocArray::byJsonString(substr($data, 1), ['b', 't', 'k', 'm', 'n', 's']);
                if (null === $values) {
                    throw new BadMessageException();
                }

                return new FileMessage(
                    $values->getValue('b'),
                    $values->getValue('t'),
                    $values->getValue('k'),
                    $values->getValue('m'),
                    $values->getValue('n'),
                    $values->getValue('s'));
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
     * Validate crypt tool
     *
     * @return bool
     * @throws Exception
     */
    abstract public function validate();

    /**
     * @param $data
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
        $result = $this->openSecretBox($data, self::FILE_NONCE, $key);
        return false === $result ? null : $result;
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
        $result = $this->openSecretBox($data, self::FILE_THUMBNAIL_NONCE, $key);
        return false === $result ? null : $result;
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

        return new EncryptResult($box, null, $nonce, strlen($box));
    }

    /**
     * @param string $data       as binary
     * @param string $publicKey  as binary
     * @param string $privateKey as binary
     * @param string $nonce      as binary
     * @return string
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
        return 'CryptTool ' . $this->getName();
    }

    /**
     * Converts a binary string to an hexdecimal string.
     *
     * This is the same as PHP's bin2hex() implementation, but it is resistant to
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
     * Converts an hexdecimal string to a binary string.
     *
     * This is the same as PHP's hex2bin() implementation, but it is resistant to
     * timing attacks.
     * Note that the default implementation does not support $ignore currrently and will
     * throw an error. Only when libsodium >= 0.22 is used, this is supported.
     *
     * @param  string      $hexString The hex string to convert
     * @param  string|null $ignore    (optional) Characters to ignore
     * @throws \Threema\Core\Exception
     * @return string
     */
    public function hex2bin($hexString, $ignore = null)
    {
        if ($ignore !== null) {
            throw new Exception('$ignore parameter is not supported');
        }
        return hex2bin($hexString);
    }

    /**
     * Compares two strings in a secure way.
     *
     * This is the same as PHP's strcmp() implementation, but it is resistant to
     * timing attacks.
     *
     * @link https://paragonie.com/book/pecl-libsodium/read/03-utilities-helpers.md#compare
     * @param  string $str1 The first string
     * @param  string $str2 The second string
     * @return bool
     */
    public function stringCompare($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($str1, $str2);
        } else {
            // check variable type manually
            if (!is_string($str1) || !is_string($str2)) {
                return false;
            }

            // fast comparison: check string length
            if (strlen($str1) != strlen($str2)) {
                return false;
            }

            # PHP implementation of hash_equals
            # partly taken from https://github.com/symfony/polyfill-php56/blob/master/Php56.php#L45-L51
            #
            # Note that this is really slow!!
            #
            $ret    = 0;
            $length = strlen($str1);
            for ($i = 0; $i < $length; ++$i) {
                $ret |= ord($str1[$i]) ^ ord($str2[$i]);
            }
            return 0 === $ret;
        }
    }

    /**
     * Name of the CryptTool
     * @return string
     */
    abstract public function getName();

    /**
     * Description of the CryptTool
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
     * @param $data
     * @param $nonce
     * @param $key
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
     * @return string
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
