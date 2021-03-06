<?php /** @noinspection PhpUnused */

namespace MrTimofey\LaravelAdminApi\Events;

abstract class SingleModelEvent extends ModelEvent
{
    /**
     * @var mixed
     */
    public $key;

    public function __construct(string $entity, $userKey, $key)
    {
        parent::__construct($entity, $userKey);
        $this->key = $key;
    }

    public function getModelInstance()
    {
        return $this->getModel()->newQuery()->find($this->key);
    }
}