<?php

namespace Mundipagg\Core\Kernel\I18N;

use Mundipagg\Core\Kernel\Abstractions\AbstractI18NTable;

class ENUS extends AbstractI18NTable
{
    protected function getTable()
    {
        return [
            'Invoice created: #%d.' => null,
            'Webhook received: %s.%s' => null,
            'Order paid.' => null,
            'Payment received: %.2f' => null,
        ];
    }
}