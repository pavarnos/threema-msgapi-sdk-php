<?php
/**
 * @author Threema GmbH
 * @copyright Copyright (c) 2015 Threema GmbH
 */

namespace Threema\MsgApi\PublicKeyStores;

use Threema\Core\Exception;
use Threema\MsgApi\PublicKeyStore;

/**
 * Store the PublicKeys in a PHP file
 *
 * @package Threema\MsgApi\PublicKeyStores
 */
class PhpFile extends PublicKeyStore
{
    /**
     * PHP code used to prevent unauthorized access
     *
     * @var string
     */
    private $fileBlocker = '<?php if (!isset($isMsgApiKeystore) || !$isMsgApiKeystore) die(\'Unauthorized access\');';

    /**
     * @var string
     */
    private $file;

    /**
     * cache of array
     *
     * @var array
     */
    private $keystore = array();

    /**
     * @param string $file path to a read and writable (empty) PHP file
     * @throws Exception if the file does not exist, is not writable or no valid PHP file
     */
    public function __construct($file)
    {
        if (false === is_writable($file)) {
            throw new Exception('file '.$file.' does not exist or is not writable');
        }
        if (pathinfo($file, PATHINFO_EXTENSION) != 'php') {
            throw new Exception('file '.$file.' is not a valid PHP file');
        }
        $this->file = $file;
    }

    /**
     * return null if the public key not found in the store
     *
     * @param string $threemaId
     * @return null|string
     */
    public function findPublicKey($threemaId)
    {
        $threemaId = strtoupper($threemaId);
        $publicKey = null;

        //Pre-check loaded array
        if (array_key_exists($threemaId, $this->keystore)) {
            $publicKey = $this->keystore[$threemaId];
            return $publicKey;
        }

        //Parse file
        $keystore = array();
        $isMsgApiKeystore = true;
        require_once $this->file;

        //Update cache
        $this->keystore = $keystore;

        //Check file content
        if (array_key_exists($threemaId, $keystore)) {
            $publicKey = $keystore[$threemaId];
        }

        return $publicKey;
    }

    /**
     * save a public key
     *
     * @param string $threemaId
     * @param string $publicKey
     * @throws exception
     * @return bool
     */
    public function savePublicKey($threemaId, $publicKey)
    {
        //create file if needed
        $this->_initFile();

        //check for key
        if (array_key_exists($threemaId, $this->keystore)) {
            return false;
        }

        //add key
        $this->keystore[$threemaId] = $publicKey;
        $content = '$keystore[\'' . $threemaId . '\'] = \'' . $publicKey . '\';'.PHP_EOL;

        //write content
        $fileadd = file_put_contents($this->file, $content, FILE_APPEND);
        if (!$fileadd) {
            throw new Exception('could not write to file '.$this->file);
        }

        return true;
    }

    /**
     * initiate a php file
     *
     * @param null|resource file handle for file opened in r+ mode
     * @return bool
     * @throws Exception
     */
    private function _initFile($fileHandle = null)
    {
        //check if file does already contain content
        if (filesize($this->file) != 0) {
            return true;
        }

        //manually open file if no file handle is given
        $fileopened = null;
        if (null === $fileHandle) {
            $fileHandle = fopen($this->file, 'r+');
            $fileopened = true;

            //check for success
            if (null === $fileHandle) {
                throw new Exception('could not open file '.$this->file);
            }
        }

        //create content
        $content  = $this->fileBlocker."\n";
        $content .= "\n";
        $content .= '//Threema MsgApi phpfile keystore'."\n";
        $content .= '//DO NOT EDIT THIS FILE!'."\n";
        $content .= "\n";

        //write file
        $fwrite = fwrite($fileHandle, $content);
        if (!$fwrite) {
            throw new Exception('could not write to file '.$this->file);
        }

        //close file if necessary
        if ($fileopened) {
            $fclose = fclose($fileHandle);
            if (!$fclose) {
                throw new Exception('error while processing file '.$this->file);
            }
        }
        return true;
    }
}
