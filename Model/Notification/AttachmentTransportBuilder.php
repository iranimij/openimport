<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Notification;

use Magento\Framework\Mail\AddressConverter;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\EmailMessageInterfaceFactory;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\MessageInterfaceFactory;
use Magento\Framework\Mail\MimeInterface;
use Magento\Framework\Mail\MimeMessageInterfaceFactory;
use Magento\Framework\Mail\MimePartInterface;
use Magento\Framework\Mail\MimePartInterfaceFactory;
use Magento\Framework\Mail\Template\FactoryInterface;
use Magento\Framework\Mail\Template\SenderResolverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Framework\ObjectManagerInterface;

/**
 * Magento's transport builder with file attachments (the framework's builder has none): the rendered template is
 * the first MIME part, each attachment another one.
 */
class AttachmentTransportBuilder extends TransportBuilder
{
    /**
     * @var MimePartInterface[]
     */
    private array $attachments = [];

    /**
     * @param FactoryInterface $templateFactory
     * @param MessageInterface $message
     * @param SenderResolverInterface $senderResolver
     * @param ObjectManagerInterface $objectManager
     * @param TransportInterfaceFactory $mailTransportFactory
     * @param EmailMessageInterfaceFactory $emailMessages
     * @param MimeMessageInterfaceFactory $mimeMessages
     * @param MimePartInterfaceFactory $mimeParts
     * @param MessageInterfaceFactory|null $messageFactory
     * @param AddressConverter|null $addressConverter
     */
    public function __construct(
        FactoryInterface $templateFactory,
        MessageInterface $message,
        SenderResolverInterface $senderResolver,
        ObjectManagerInterface $objectManager,
        TransportInterfaceFactory $mailTransportFactory,
        private readonly EmailMessageInterfaceFactory $emailMessages,
        private readonly MimeMessageInterfaceFactory $mimeMessages,
        private readonly MimePartInterfaceFactory $mimeParts,
        ?MessageInterfaceFactory $messageFactory = null,
        ?AddressConverter $addressConverter = null
    ) {
        parent::__construct(
            $templateFactory,
            $message,
            $senderResolver,
            $objectManager,
            $mailTransportFactory,
            $messageFactory,
            $emailMessages,
            $mimeMessages,
            $mimeParts,
            $addressConverter
        );
    }

    /**
     * @param string $content file contents
     * @param string $fileName
     * @param string $type MIME type
     * @return $this
     */
    public function addAttachment(string $content, string $fileName, string $type = MimeInterface::TYPE_OCTET_STREAM): self
    {
        $this->attachments[] = $this->mimeParts->create([
            'content' => $content,
            'type' => $type,
            'fileName' => $fileName,
            'disposition' => MimeInterface::DISPOSITION_ATTACHMENT,
            'encoding' => MimeInterface::ENCODING_BASE64,
        ]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTransport()
    {
        if ($this->attachments === []) {
            return parent::getTransport();
        }
        try {
            $this->prepareMessage();
            $message = $this->message;
            if ($message instanceof EmailMessageInterface) {
                $body = $this->mimeMessages->create([
                    'parts' => array_merge($message->getMessageBody()->getParts(), $this->attachments),
                ]);
                $message = $this->emailMessages->create([
                    'body' => $body,
                    'subject' => (string) $message->getSubject(),
                    'to' => $message->getTo(),
                    'from' => $message->getFrom(),
                    'cc' => $message->getCc(),
                    'bcc' => $message->getBcc(),
                    'replyTo' => $message->getReplyTo(),
                    'sender' => $message->getSender(),
                    'encoding' => $message->getEncoding(),
                ]);
            }
            /** @var TransportInterface $transport */
            $transport = $this->mailTransportFactory->create(['message' => $message]);
        } finally {
            $this->attachments = [];
            $this->reset();
        }

        return $transport;
    }
}
