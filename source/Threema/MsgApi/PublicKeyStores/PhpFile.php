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
     * length: 86 characters
     *
     * @var string
     */
    private $fileBlocker = '<?php if (!isset($isMsgApiKeystore) || !$isMsgApiKeystore) die(\'Unauthorized access\');';

    /**
     * regular expression used to determinate valid $fileBlocker
     *
     * only tested at the first line
     * https://regex101.com/r/tW7pC5/4
     * additional requirements: maximal 200 characters
     *
     * @var string
     */
    private $fileBlockerRegExp = '^\<\?php\h*if\h*\(!isset\(\$isMsgApiKeystore\)\h*\|\|\h*!\$isMsgApiKeystore\)\h*(die|exit)\((.*?)\);';

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

        // Pre-check loaded array
        if (array_key_exists($threemaId, $this->keystore)) {
            $publicKey = $this->keystore[$threemaId];
            return $publicKey;
        }

        // Parse file
        $keystore = array();
        $isMsgApiKeystore = true;
        require_once $this->file;

        // Update cache
        $this->keystore = $keystore;

        // Check file content
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
        // create file if needed
        $this->_initFile();

        // check for key
        if (array_key_exists($threemaId, $this->keystore)) {
            return false;
        }
        // NOTE: This prevents that an key is written twice into the file, but
        // it also prevents overwriting an existing ID with a new public key.

        // add key
        $this->keystore[$threemaId] = $publicKey;
        $content = '$keystore[\'' . $threemaId . '\'] = \'' . $publicKey . '\';';

        // write content
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
        // manually open file if no file handle is given
        $fileopened = null;
        if (null === $fileHandle) {
            $fileHandle = fopen($this->file, 'r+');
            $fileopened = true;

            // check for success
            if (null === $fileHandle) {
                throw new Exception('could not open file '.$this->file);
            }
        }

        // read first line and add content if neccessary
        $fileStart = fgets($fileHandle, 200);
        if (!preg_match($this->fileBlockerRegExp, $fileStart)) {
            // create content
            $content  = $this->fileBlocker."\n";
            $content .= "\n";
            $content .= '// Threema MsgApi phpfile keystore'."\n";
            $content .= '// DO NOT EDIT THIS FILE!'."\n";
            $content .= "\n";

            // Write file
            $fwrite = fwrite($fileHandle, $content);
            if (!$fwrite) {
                throw new Exception('could not write to file '.$this->file);
            }
        }

        if ($fileopened) {
            $fclose = fclose($fileHandle);
            if (!$fclose) {
                throw new Exception('error while processing file '.$this->file);
            }
        }
        return true;
    }
}
