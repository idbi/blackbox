<?php

namespace Blackbox\Sinks;

interface Sink
{
    /**
     * Persist one audit record. Called after the response has been sent.
     */
    public function write(array $record): void;
}
