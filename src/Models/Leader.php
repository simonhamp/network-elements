<?php

namespace SimonHamp\NetworkElements\Models;

use Illuminate\Database\Eloquent\Model;

class Leader extends Model
{
    // We'll need to respond to pings and get updates

    protected function getUpdates()
    {
        // Fetch the updates
    }
}
