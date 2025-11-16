<?php

return [
    'name'        => 'KumoMTA Callback',
    'description' => 'Processes KumoMTA feedback loops and marks contacts as Do Not Contact',
    'version'     => '1.0',
    'author'      => 'Joey Keller',
    'services'    => [
        'events' => [
            'mautic.kumomta.callback' => [
                'class'     => \MauticPlugin\KumoMtaCallbackBundle\EventSubscriber\CallbackSubscriber::class,
                'arguments' => [
                    'mautic.email.model.transport_callback',
                    'logger',
                ],
            ],
        ],
    ],
];
