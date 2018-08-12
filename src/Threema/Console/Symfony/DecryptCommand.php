<?php
/**
 * @author    Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */

declare(strict_types=1);

namespace Threema\Console\Symfony;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Threema\MsgApi\Tools\CryptTool;

class DecryptCommand extends AbstractLocalCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('decrypt')
             ->setDescription('Decrypt standard input using the given recipient private key and sender public key and nonce. ' .
                 'The encrypted message box (hex) can be on standard input. Prints the decrypted message to standard output.')
             ->requirePrivateKey()
             ->requirePublicKey()
             ->addArgument('nonce', InputArgument::REQUIRED, 'Message nonce in hex')
             ->optionalMessageOrStdIn();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadDefaults($input, $output);
        $cryptTool = CryptTool::getInstance();
        $message   = $cryptTool->decryptMessage($cryptTool->hex2bin($this->getMessage($input)),
            $this->getPrivateKey($input, $output), $this->getPublicKey($input), $cryptTool->hex2bin($input->getArgument('nonce')));
        $output->writeln($message->__toString());
        return 0;
    }
}