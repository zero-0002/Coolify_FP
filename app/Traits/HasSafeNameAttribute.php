<?php

namespace App\Traits;

trait HasSafeNameAttribute
{
    /**
     * Set the name attribute - strip any HTML tags for safety
     */
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = strip_tags($value);
    }
}
