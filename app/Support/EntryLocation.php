<?php

namespace App\Support;

class EntryLocation
{
    public static function rules(): array
    {
        return [
            'location' => 'sometimes|array:latitude,longitude,location_accuracy',
            'location.latitude' => 'required_with:location|numeric|between:-90,90',
            'location.longitude' => 'required_with:location|numeric|between:-180,180',
            'location.location_accuracy' => 'nullable|numeric|min:0|max:100000',
        ];
    }
}
