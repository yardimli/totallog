<?php

namespace App\Models\Concerns;

/** Keep an Eloquent change and its mobile revision visible as one database commit. */
trait AtomicMobileWrites
{
    public function save(array $options = [])
    {
        // The caller owns rollback/retry when a larger operation is in progress.
        // Extra savepoints can mask a MySQL transaction abort with a missing-savepoint error.
        if ($this->getConnection()->transactionLevel() > 0) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        if ($this->getConnection()->transactionLevel() > 0) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(fn () => parent::delete());
    }
}
