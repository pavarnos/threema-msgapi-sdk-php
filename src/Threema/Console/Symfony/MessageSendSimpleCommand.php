<?php
/**
 * @author    Threema GmbH
 * @copyright Copyright (c) 2015-2016 Threema GmbH
 */

declare(strict_types=1);

namespace Threema\Console\Symfony;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MessageSendSimpleCommand extends AbstractNetworkedCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('message:send:simple')
             ->setDescription('Send Simple Message')
             ->setHelp('Send a message with server side encryption to the given ID (only if the API identity supports it). Prints the message ID on success')
             ->setAliases(['simple'])
             ->requireRecipientID()
             ->optionalMessageOrStdIn();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = $this->getConnection($input, $output)
                       ->sendToThreemaID($this->getRecipientID($input), $this->getMessage($input));
        $this->assertSuccess($result);
        $output->writeln($result->getMessageId());
        return 0;
    }
}