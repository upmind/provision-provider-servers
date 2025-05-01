<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\TwentyI\Helper;

use TwentyI\API\ControlPanel as APIControlPanel;
use Upmind\ProvisionProviders\Servers\TwentyI\Helper\Traits\LogsRequests;

/**
 * TwentyI\API\ControlPanel decorator which can logs request and response data.
 */
class ControlPanel extends APIControlPanel
{
    use LogsRequests;
}
