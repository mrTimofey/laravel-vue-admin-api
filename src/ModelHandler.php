<?php

namespace App\Admin;

use App\Admin\Contracts\ConfiguresAdminHandler;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ModelHandler
{
    /**
     * Model item (may be existing or just new instance)
     *
     * @var Model
     */
    protected $item;

    /**
     * API entity name (URL argument), useful with different model contexts
     *
     * @var string
     */
    protected $name;

    /**
     * Model pages title
     *
     * @var string
     */
    protected $title;

    /**
     * Item editing page subtitle
     *
     * @var string
     */
    protected $itemTitle;

    /**
     * Item creating page subtitle
     *
     * @var string
     */
    protected $createTitle;

    /**
     * Request instance
     *
     * @var Request
     */
    protected $req;

    /**
     * Array of allowed actions.
     *
     * @var array|null
     */
    protected $abilities;

    /**
     * Use common policies while authorizing
     *
     * @var bool
     */
    protected $policies = false;

    /**
     * Prefix for policy actions
     *
     * @var string|null
     */
    protected $policiesPrefix;

    /**
     * Field names to perform text search on
     *
     * @var string[]|null
     */
    protected $searchableFields;

    /**
     * Rewritten search callback
     *
     * @var callable|null
     */
    protected $searchCallback;

    /**
     * Query modifiers applying before any other query processing
     *
     * @var callable[]
     */
    protected $preQueryModifiers = [];

    /**
     * Query modifiers applying just before query execution
     *
     * @var callable[]
     */
    protected $postQueryModifiers = [];

    /**
     * Fields used as filters in index page
     *
     * @var array|null
     */
    protected $filterFields;

    /**
     * Fields exposed in index response
     *
     * @var array|null
     */
    protected $indexFields;

    /**
     * Fields exposed in item response
     *
     * @var array|null
     */
    protected $itemFields;

    /**
     * Validation rules per each action ('create', 'simpleCreate', 'update')
     *
     * @var array
     */
    protected $validationRules = [];

    /**
     * Validation messages
     *
     * @var array
     */
    protected $validationMessages = [];

    /**
     * Rewritten validation function
     *
     * @var callable|null
     */
    protected $validationCallback;

    public function __construct(Model $item, string $name, Request $req)
    {
        $this->item = $item;
        $this->name = $name;
        $this->req = $req;
        $this->setItem($item);
    }

    /**
     * Name is defined by URL chunk used to work with this model through an API.
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets a model item to work with.
     * @param Model $item
     */
    public function setItem(Model $item): void
    {
        $this->item = $item;
        if ($item instanceof ConfiguresAdminHandler) {
            $item->configureAdminHandler($this);
        }
    }

    /**
     * Set model title.
     * @param string $title
     * @return ModelHandler
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set model editing page subtitle.
     * @param string $title
     * @return ModelHandler
     */
    public function setItemTitle(string $title): self
    {
        $this->itemTitle = $title;
        return $this;
    }

    /**
     * Set model creating page subtitle.
     * @param string $title
     * @return ModelHandler
     */
    public function setCreateTitle(string $title): self
    {
        $this->createTitle = $title;
        return $this;
    }

    /**
     * Set array of allowed actions (index, create, simpleCreate, update, destroy, [...custom actions]).
     * @param array $abilities
     * @return ModelHandler
     */
    public function allowActions(array $abilities): self
    {
        $this->abilities = $abilities;
        return $this;
    }

    /**
     * Tells handler to use policies while authorizing actions (all actions are allowed by default).
     * @param bool $use
     * @param null|string $prefix policy method name prefix
     * @return ModelHandler
     */
    public function usePolicies(bool $use = true, ?string $prefix = null): self
    {
        $this->policies = $use;
        $this->policiesPrefix = $prefix;
        return $this;
    }

    /**
     * Set fields which will be used in a text search with SQL LIKE.
     * @param array $fields
     * @return ModelHandler
     */
    public function setSearchableFields(array $fields): self
    {
        $this->searchableFields = $fields;
        return $this;
    }

    /**
     * Set custom search callback to replace default behavior.
     * @param callable $callback function(Builder, Request, array $searchableFields)
     * @return ModelHandler
     */
    public function setSearchCallback(callable $callback): self
    {
        $this->searchCallback = $callback;
        return $this;
    }

    /**
     * Add query modifier called just after Model::newQuery() is called.
     * @param callable $modifier function(Builder, Request)
     * @return ModelHandler
     */
    public function addPreQueryModifier(callable $modifier): self
    {
        $this->preQueryModifiers[] = $modifier;
        return $this;
    }

    /**
     * Add query modifier called just before execution.
     * @param callable $modifier $modifier function(Builder, Request)
     * @return ModelHandler
     */
    public function addPostQueryModifier(callable $modifier): self
    {
        $this->postQueryModifiers[] = $modifier;
        return $this;
    }

    /**
     * Set available filters definition.
     * @param array $fields
     * @return ModelHandler
     */
    public function setFilterFields(array $fields): self
    {
        $this->filterFields = $this->prepareFields($fields);
        return $this;
    }

    /**
     * Set fields available in model index.
     * @param array $fields
     * @param array|null $defaults
     * @return ModelHandler
     */
    public function setIndexFields(array $fields, ?array $defaults = null): self
    {
        $this->indexFields = $this->prepareFields($fields, $defaults ?? ['sortable' => true]);
        return $this;
    }

    /**
     * Set fields available in model creating/editing.
     * @param array $fields
     * @return ModelHandler
     */
    public function setItemFields(array $fields): self
    {
        $this->itemFields = $this->prepareFields($fields);
        return $this;
    }

    /**
     * Set validation rules.
     * Use 'files.{field name}' keys to apply uploaded files validation.
     * @param array $rules
     * @return self
     */
    public function setValidationRules(array $rules): self
    {
        $this->validationRules = $rules;
        return $this;
    }

    /**
     * Set validation messages.
     * @param array $messages
     * @return ModelHandler
     */
    public function setValidationMessages(array $messages): self
    {
        $this->validationMessages = $messages;
        return $this;
    }

    /**
     * Set custom validation callback to replace default behavior.
     * @param callable $validator function(Request, array $rules, array $messages, array $customAttributes)
     * @return ModelHandler
     */
    public function setValidationCallback(callable $validator): self
    {
        $this->validationCallback = $validator;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getItemTitle(): ?string
    {
        return $this->itemTitle;
    }

    public function getCreateTitle(): ?string
    {
        return $this->createTitle;
    }

    public function getIndexFields(): ?array
    {
        if ($this->indexFields) {
            return $this->indexFields;
        }
        if (($visible = $this->item->getVisible()) && \count($visible) > 0) {
            return $this->prepareFields($visible, ['sortable' => true]);
        }
        return null;
    }

    public function getItemFields(): ?array
    {
        if ($this->itemFields) {
            return $this->itemFields;
        }
        if (($fillable = $this->item->getFillable()) && \count($fillable) > 0) {
            return $this->prepareFields($fillable);
        }
        return null;
    }

    public function getValidationRules(): ?array
    {
        return $this->validationRules;
    }

    public function getFilterFields(): ?array
    {
        return $this->filterFields;
    }

    public function isSearchable(): bool
    {
        return $this->searchableFields && \count($this->searchableFields) > 0 || $this->searchCallback;
    }

    /**
     * Authorize action.
     * Throw 403 exception if user is not permitted to perform this action.
     * @param string $action
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function authorize(string $action): void
    {
        if ($this->policies) {
            /** @var Authorizable $user */
            $user = $this->req->user();
            if (!$user->can(
                $this->policiesPrefix ? ($this->policiesPrefix . studly_case($action)) : $action,
                $this->item)) {
                throw new AccessDeniedHttpException($action . ' action on ' . $this->name . ' is not authorized');
            }
        }
        if ($this->abilities && !\in_array($action, $this->abilities, true)) {
            throw new AccessDeniedHttpException($action . ' action on ' . $this->name . ' is not allowed');
        }
    }

    /**
     * @param Builder $q
     * @param callable[] $modifiers
     */
    protected function applyQueryModifiers(Builder $q, array $modifiers): void
    {
        foreach ($modifiers as $modifier) {
            $modifier($q, $this->req);
        }
    }

    protected function prepareFields(array $fields, array $default = []): array
    {
        $realFields = [];
        $casts = $this->item->getCasts();
        $dates = $this->item->getDates();
        foreach ($fields as $field => $conf) {
            if (is_numeric($field)) {
                $field = $conf;
                $conf = $default;
            }
            if (!isset($conf['type'])) {
                if (\in_array($field, $dates, true)) {
                    $conf['type'] = 'datetime';
                } elseif (isset($casts[$field])) {
                    $conf['type'] = $casts[$field];
                } elseif (method_exists($this->item, $field)) {
                    $conf['type'] = 'relation';
                    $relation = $this->item->$field();
                    if ($relation instanceof HasMany || $relation instanceof BelongsToMany) {
                        $conf['multiple'] = true;
                    }
                    if (!isset($conf['entity'])) {
                        $conf['entity'] = str_replace('_', '-', $relation->getModel()->getTable());
                    }
                }
            }
            $realFields[$field] = $conf;
        }
        return $realFields;
    }

    protected function applyPreQueryModifiers(Builder $q): void
    {
        $this->applyQueryModifiers($q, $this->preQueryModifiers);
    }

    protected function applyPostQueryModifiers(Builder $q): void
    {
        $this->applyQueryModifiers($q, $this->postQueryModifiers);
    }

    protected function applyFilters(Builder $q): void
    {
        if ($filters = (array)$this->req->get('filters')) {
            foreach ($filters as $field => $value) {
                if (is_numeric($field)) {
                    $field = $value;
                    $value = null;
                }

                $not = false;
                $op = '=';

                if (starts_with($field, '!')) {
                    $not = true;
                    $op = '!=';
                    $field = substr($field, 1);
                } elseif (starts_with($field, '>~')) {
                    $op = '>=';
                    $field = substr($field, 2);
                } elseif (starts_with($field, '<~')) {
                    $op = '<=';
                    $field = substr($field, 2);
                } elseif (starts_with($field, '>')) {
                    $op = '>';
                    $field = substr($field, 1);
                } elseif (starts_with($field, '<')) {
                    $op = '<';
                    $field = substr($field, 1);
                }

                if (\is_array($value)) {
                    $q->whereIn($field, $value, 'and', $not);
                } elseif ($value === null) {
                    $q->where($field, $not ? false : true);
                } else {
                    $q->where($field, $op, $value);
                }
            }
        }
    }

    protected function applyScopes(Builder $q): void
    {
        if ($scopes = (array)$this->req->get('scopes')) {
            foreach ($scopes as $scope => $params) {
                if (is_numeric($scope)) {
                    $scope = $params;
                    $params = [];
                } elseif (!\is_array($params)) {
                    $params = explode(',', $params);
                }

                $scopeMethod = 'scope' . studly_case($scope);
                if (method_exists($model = $q->getModel(), $scopeMethod)) {
                    $model->$scopeMethod($q, ...$params);
                }
            }
        }
    }

    protected function applySearch(Builder $q): void
    {
        if (!$this->isSearchable()) {
            return;
        }
        if ($this->searchCallback) {
            ($this->searchCallback)($q, $this->req, $this->searchableFields);
        } elseif ($this->searchableFields && ($search = $this->req->get('search'))) {
            $search = mb_strtolower($search);
            $q->where(function (Builder $q) use ($search) {
                foreach ($this->searchableFields as $field) {
                    $q->orWhereRaw('lower(' . $field . ') like ?', ['%' . $search . '%']);
                }
            });
        }
    }

    protected function applySort(Builder $q): void
    {
        if ($sort = $this->req->get('sort')) {
            $sort = (array)$sort;
            foreach ($sort as $k => $v) {
                if (is_numeric($k)) {
                    $field = $v;
                    $dir = 'asc';
                } else {
                    $field = $k;
                    $dir = $v;
                    if ($dir === '0') {
                        $dir = 'desc';
                    } elseif ($dir === '1') {
                        $dir = 'asc';
                    }
                }

                $q->orderBy($field, $dir);
            }
        }
    }

    protected function loadRelations(Builder $q, array $fields): void
    {
        $q->with(collect($fields)
            ->keys()
            ->filter(function(string $field) {
                return method_exists($this->item, $field);
            })
            ->toArray()
        );
    }

    protected function transformRequestData(array $fields): array
    {
        $data = [];
        $transformer = app(RequestTransformer::class);
        foreach ($fields as $name => $config) {
            $data[$name] = $transformer->transform(
                $name,
                $config['type'] ?? 'text',
                $this->req
            );
        }
        return $data;
    }

    public function buildQuery(): Builder
    {
        $q = $this->item->newQuery();
        $this->applyPreQueryModifiers($q);
        $this->applyScopes($q);
        $this->applyFilters($q);
        $this->applySearch($q);
        $this->applySort($q);
        $this->loadRelations($q, $this->getIndexFields());
        $this->applyPostQueryModifiers($q);
        return $q;
    }

    protected function transform(Model $item, ?array $fields, bool $fullRelations = false)
    {
        $relations = [];
        if ($fields) {
            $visible = [];
            $appends = [];
            foreach ($fields as $field => $config) {
                if (method_exists($item, $field)) {
                    if (!$item->relationLoaded($field)) {
                        $item->load($field);
                    }
                    $related = $item->getRelation($field);
                    if ($fullRelations && empty($config['editable'])) {
                        $visible[] = $field;
                    } else {
                        if ($related instanceof Collection) {
                            $relations[$field] = $related->pluck('id')->toArray();
                        } else {
                            $relations[$field] = $related ? $related->getKey() : null;
                        }
                    }
                } else {
                    $visible[] = $field;
                    if ($item->hasGetMutator($field)) {
                        $appends[] = $field;
                    }
                }
            }
            $item->setVisible($visible);
            $item->setAppends($appends);
        }
        $item->addVisible($item->getKeyName());
        return array_merge($item->toArray(), $relations);
    }

    /**
     * @param Model $item
     * @return mixed
     */
    public function transformIndexItem(?Model $item = null)
    {
        if (!$item) {
            $item = clone $this->item;
        }
        return $this->transform($item, $this->indexFields, true);
    }

    /**
     * @return mixed
     */
    public function transformItem()
    {
        return $this->transform($this->item, $this->itemFields);
    }

    public function validate(bool $validateOnlyPresent = false): void
    {
        $fields = $this->getItemFields();
        $rules = $this->validationRules;
        $messages = $this->validationMessages;
        $titles = collect($this->getIndexFields() ?? [])->merge($fields ?? [])
            ->map(function ($field) {
                return $field['title'] ?? $field['label'] ?? $field['placeholder'] ?? null;
            })
            ->filter(function ($title) {
                return $title;
            })
            ->all();

        foreach ($titles as $k => $title) {
            $titles['files__' . $k] = $title;
        }

        if ($this->validationCallback) {
            ($this->validationCallback)($this->req, $rules, $messages, $titles);
        } elseif ($rules) {
            if ($validateOnlyPresent) {
                $presentRules = [];
                foreach ($this->req->keys() as $k) {
                    if (!empty($rules[$k])) {
                        $presentRules[$k] = $rules[$k];
                    }
                }
                $rules = $presentRules;
            }
            $this->req->validate($rules, $messages, $titles);
        }
    }

    protected function syncHasMany(HasMany $rel, $ids): void
    {
        if (!\is_array($ids) && empty($ids)) {
            return;
        }

        $fk = $rel->getForeignKeyName();
        $parentKey = $rel->getParentKey();
        $toSync = $rel->getRelated()->newQuery()->find((array)$ids);
        $toDelete = $rel->get()->keyBy($rel->getRelated()->getKeyName());

        /** @var Model $item */
        foreach ($toSync as $item) {
            if ($item->getAttribute($fk) !== $parentKey) {
                $item->setAttribute($fk, $parentKey);
                $item->save();
            }
            if ($toDelete->has($item->getKey())) {
                $toDelete->forget($item->getKey());
            }
        }

        /** @var Model $item */
        foreach ($toDelete->toArray() as $item) {
            $item->setAttribute($fk, null);
            $item->save();
        }
    }

    protected function fillAndSave(Model $item, array $fields): Model
    {
        $data = $this->transformRequestData($fields);
        $relations = [];
        foreach ($data as $name => $value) {
            $relationName = camel_case($name);
            if (method_exists($item, $relationName)) {
                $relation = $item->$relationName();
                if ($relation instanceof BelongsTo) {
                    $relation->associate($value);
                } else {
                    $relations[] = [$relation, $value];
                }
            } else {
                $item->setAttribute($name, $value);
            }
        }
        $item->saveOrFail();
        foreach ($relations as [$relation, $value]) {
            if ($relation instanceof BelongsToMany) {
                $relation->sync($value);
            } elseif ($relation instanceof HasMany) {
                $this->syncHasMany($relation, $value);
            }
        }
        return $item;
    }

    public function create(): Model
    {
        $this->validate();
        return $this->fillAndSave($this->item->newInstance(), $this->getItemFields());
    }

    public function update(): void
    {
        $this->validate();
        $this->fillAndSave($this->item, $this->getItemFields());
    }

    public function fastUpdate(): void
    {
        $this->validate(true);
        $fields = $this->getIndexFields();
        $field = $this->req->get('__field');
        $this->fillAndSave($this->item, [$field => $fields[$field]]);
    }

    public function destroy(): void
    {
        $this->item->delete();
    }

    // public function action{ActionName}(): mixed
}
