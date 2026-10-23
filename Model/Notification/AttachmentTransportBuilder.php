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
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;

/**
 * Magento's transport builder with file attachments (the framework's builder has none): the rendered template is
 * the first MIME part, each attachment another one. Magento 2.4.8+ sends a Symfony message whose MimeMessage keeps
 * only the first text part, so there the attachments go onto the Symfony message itself; 2.4.7 (Laminas) gets a
 * new MIME message with all parts.
 */
class AttachmentTransportBuilder extends TransportBuilder
{
    /**
     * @var array<int, array{content: string, fileName: string, type: string}>
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
        $this->attachments[] = ['content' => $content, 'fileName' => $fileName, 'type' => $type];

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
            if (method_exists($message, 'getSymfonyMessage') && class_exists(MixedPart::class)) {
                $this->attachToSymfony($message->getSymfonyMessage());
            } elseif ($message instanceof EmailMessageInterface) {
                $body = $this->mimeMessages->create([
                    'parts' => array_merge($message->getMessageBody()->getParts(), $this->laminasParts()),
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

    /**
     * Magento 2.4.8+: the rendered template and the attachments as one multipart/mixed body.
     *
     * @param object $symfonyMessage \Symfony\Component\Mime\Message
     * @return void
     */
    private function attachToSymfony(object $symfonyMessage): void
    {
        $parts = [];
        $body = $symfonyMessage->getBody();
        if ($body instanceof AbstractPart) {
            $parts[] = $body;
        }
        foreach ($this->attachments as $attachment) {
            $parts[] = new DataPart($attachment['content'], $attachment['fileName'], $attachment['type']);
        }
        $symfonyMessage->setBody(new MixedPart(...$parts));
    }

    /**
     * Magento 2.4.7: the attachments as Magento MIME parts.
     *
     * @return MimePartInterface[]
     */
    private function laminasParts(): array
    {
        return array_map(fn (array $attachment): MimePartInterface => $this->mimeParts->create([
            'content' => $attachment['content'],
            'type' => $attachment['type'],
            'fileName' => $attachment['fileName'],
            'disposition' => MimeInterface::DISPOSITION_ATTACHMENT,
            'encoding' => MimeInterface::ENCODING_BASE64,
        ]), $this->attachments);
    }
}
