<?php

namespace Statamic\FormsPro\Fieldtypes;

use Statamic\Fields\Fieldtype;

class HubSpotSubscription extends Fieldtype
{
    protected static $handle = 'hubspot_subscription';

    protected $selectable = false;

    protected $defaultable = false;
}
