<?php

namespace app\models\contract;

/**
 * A record that belongs to a user. Lets {@see \app\models\service\AccessControlService}
 * resolve ownership polymorphically — granting the implicit base abilities on
 * one's own records — instead of switching on the concrete model type.
 */
interface OwnableInterface
{
    /**
     * The id of the user who owns this record.
     */
    public function getOwnerId(): int;
}
