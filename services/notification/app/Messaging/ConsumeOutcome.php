<?php

namespace App\Messaging;

enum ConsumeOutcome
{
    case Ack;
    case Requeue;
    case Dead;
}
