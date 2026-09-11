<?php

namespace App\Models\Concerns;

/** Keep an Eloquent change and its mobile revision visible as one database commit. */
trait AtomicMobileWrites
{
    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }
}
