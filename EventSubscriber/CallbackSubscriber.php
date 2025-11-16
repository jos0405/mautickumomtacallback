<?php

declare(strict_types=1);

namespace MauticPlugin\KumoMtaCallbackBundle\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $payload = json_decode($event->getRequest()->getContent(), true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($payload)) {
            $event->setResponse(new Response('Invalid JSON', Response::HTTP_BAD_REQUEST));

            return;
        }

        try {
            $this->processKumoFeedback($payload);
            $event->setResponse(new Response('KumoMTA Callback processed'));
        } catch (\InvalidArgumentException $e) {
            // Payload is not a valid KumoMTA feedback loop
            $this->logger->warning('KumoMTA callback rejected: '.$e->getMessage(), [
                'payload' => $payload,
            ]);
            $event->setResponse(new Response('Bad Request', Response::HTTP_BAD_REQUEST));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process KumoMTA payload: '.$e->getMessage(), [
                'payload' => $payload,
            ]);
            $event->setResponse(new Response('Bad Request', Response::HTTP_BAD_REQUEST));
        }
    }

    /**
     * Handle KumoMTA feedback loops.
     *
     * Expected payload shape (simplified):
     * {
     *   "type": "TransientFailure" | "PermanentFailure" | ...,
     *   "recipient": "user@example.com",
     *   "response": {
     *     "code": 551,
     *     "enhanced_code": { "class": 5, "subject": 4, "detail": 4 },
     *     "content": "MX didn't resolve to any hosts",
     *   },
     *   ...
     * }
     *
     * If enhanced_code.class === 5, we mark the recipient as Do Not Contact in Mautic.
     *
     * @param array<string, mixed> $payload
     */
    private function processKumoFeedback(array $payload): void
    {
        // Validate minimal KumoMTA structure
        if (
            !isset($payload['type']) ||
            !isset($payload['recipient']) ||
            !isset($payload['response']['enhanced_code'])
        ) {
            throw new \InvalidArgumentException('Missing required KumoMTA fields.');
        }

        $enhanced = $payload['response']['enhanced_code'] ?? [];

        $class   = isset($enhanced['class']) ? (int) $enhanced['class'] : null;
        $subject = isset($enhanced['subject']) ? (int) $enhanced['subject'] : null;
        $detail  = isset($enhanced['detail']) ? (int) $enhanced['detail'] : null;

        // Only act on permanent 5.x.x failures
        if (5 !== $class) {
            $this->logger->info('KumoMTA feedback received but enhanced_code.class is not 5; ignoring.', [
                'class'     => $class,
                'recipient' => $payload['recipient'] ?? null,
                'type'      => $payload['type'] ?? null,
            ]);

            return;
        }

        $recipient = $payload['recipient'] ?? null;

        if (empty($recipient)) {
            throw new \InvalidArgumentException('Recipient is missing in KumoMTA payload.');
        }

        try {
            $address = Address::create($recipient);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid recipient address in KumoMTA payload: '.$e->getMessage());
        }

        // Build a human-readable reason
        $reasonParts = [];

        if (isset($payload['response']['code'])) {
            $reasonParts[] = (string) $payload['response']['code'];
        }

        if (isset($payload['response']['content'])) {
            $reasonParts[] = $payload['response']['content'];
        }

        $reasonParts[] = sprintf(
            'Enhanced code %d.%d.%d from KumoMTA',
            $class,
            $subject ?? 0,
            $detail ?? 0
        );

        $reason = implode(' - ', array_filter($reasonParts));

        // This call creates/updates the Do Not Contact entry in Mautic
        $this->transportCallback->addFailureByAddress(
            $address->getAddress(),
            $reason,
            DoNotContact::BOUNCED,
            null // We don’t have a Mautic email ID from KumoMTA payload
        );

        $this->logger->info('Marked contact as Do Not Contact based on KumoMTA feedback.', [
            'recipient' => $address->getAddress(),
            'reason'    => $reason,
        ]);
    }
}
