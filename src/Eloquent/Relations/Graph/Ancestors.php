<?php

namespace Staudenmeir\LaravelAdjacencyList\Eloquent\Relations\Graph;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Expression;
use Staudenmeir\LaravelAdjacencyList\Eloquent\Relations\Graph\Traits\IsRecursiveRelation;

class Ancestors extends BelongsToMany
{
    use IsRecursiveRelation {
        buildDictionary as baseBuildDictionary;
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $column = $this->andSelf ? $this->getQualifiedParentKeyName() : $this->getQualifiedRelatedPivotKeyName();

            $constraint = function (Builder $query) use ($column) {
                $query->where(
                    $column,
                    '=',
                    $this->parent->{$this->parentKey}
                );
            };

            $this->addExpression($constraint);
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param array $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $whereIn = $this->whereInMethod($this->parent, $this->parentKey);

        $column = $this->andSelf ? $this->getQualifiedParentKeyName() : $this->getQualifiedRelatedPivotKeyName();

        $keys = $this->getKeys($models, $this->parentKey);

        $constraint = function (Builder $query) use ($models, $whereIn, $column, $keys) {
            $query->$whereIn($column, $keys);
        };

        $this->addExpression($constraint, null, null, $this->andSelf ? 'unionAll' : 'union');
    }

    /**
     * Build model dictionary.
     *
     * @param \Illuminate\Database\Eloquent\Collection $results
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        if ($this->andSelf) {
            return $this->baseBuildDictionary($results);
        }

        $dictionary = [];

        $depthName = $this->related->getDepthName();

        $firstLevelResults = $results->where($depthName, '=', -1)->groupBy($this->parentKey);

        foreach ($results as $result) {
            $keys = [];

            if ($result->$depthName < -1) {
                foreach ($firstLevelResults[$result->getFirstPathSegment()] as $model) {
                    $keys[] = $model->{$this->accessor}->{$this->relatedPivotKey};
                }
            } else {
                $keys[] = $result->{$this->accessor}->{$this->relatedPivotKey};
            }

            foreach ($keys as $key) {
                $dictionary[$key][] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Builder $parentQuery
     * @param array|mixed $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($query->getQuery()->from === $parentQuery->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $columns);
        }

        $first = $this->andSelf
            ? $query->getQuery()->from . '.' . $this->parentKey
            : $this->getQualifiedRelatedPivotKeyName();

        $constraint = function (Builder $query) use ($first) {
            $query->whereColumn(
                $first,
                '=',
                $this->getQualifiedParentKeyName()
            );
        };

        return $this->addExpression($constraint, $query->select($columns));
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array|mixed $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQueryForSelfRelation(
        Builder $query,
        $columns = ['*']
    ): Builder {
        if ($columns instanceof Expression) {
            $columns = $this->replaceTableHash($query, $columns);
        }

        $table = $this->getRelationCountHash();

        $from = $query->getModel()->getTable() . ' as ' . $table;

        $query->getModel()->setTable($table);

        $first = $this->andSelf
            ? "$table.$this->parentKey"
            : "$this->table.$this->relatedPivotKey";

        $constraint = function (Builder $query) use ($first) {
            $query->whereColumn(
                $first,
                '=',
                $this->getQualifiedParentKeyName()
            );
        };

        return $this->addExpression($constraint, $query->select($columns), $from);
    }

    /**
     * Add a recursive expression to the query.
     *
     * @param callable $constraint
     * @param \Illuminate\Database\Eloquent\Builder|null $query
     * @param string|null $from
     * @param string $union
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function addExpression(
        callable $constraint,
        Builder $query = null,
        string $from = null,
        string $union = 'unionAll'
    ): Builder {
        $query = $query ?: $this->query;

        $initialDepth = $this->andSelf ? 0 : -1;

        return $query->withRelationshipExpression(
            'asc',
            $constraint,
            $initialDepth,
            $from,
            null,
            $union
        );
    }
}
