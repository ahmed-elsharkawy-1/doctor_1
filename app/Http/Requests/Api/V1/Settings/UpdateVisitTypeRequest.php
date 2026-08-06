<?php

namespace App\Http\Requests\Api\V1\Settings;

class UpdateVisitTypeRequest extends StoreVisitTypeRequest
{
    // Same shape as create: name, duration, and price for those allowed to
    // set it. A partial update would make "clear the price" ambiguous.
}
