<?php

namespace MacropaySolutions\CrufdWizardDecorator\Helpers;

class LogHelper
{
    public static function logError(\Throwable $e, string $prefix): void
    {
        \app('log')->error($prefix . ' error: ' . $e->getMessage(), $e->getTrace());
    }
}
