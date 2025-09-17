<?php

declare(strict_types=1);

namespace Janartist\Elasticsearch;

use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Exception;
use Hyperf\Collection\Collection;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;
use Throwable;
use TypeError;

use function Hyperf\Collection\collect;
use function Hyperf\Support\call;
use function Hyperf\Support\make;

class Builder
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * The base query.
     *
     * @var []
     */
    protected $query;

    /**
     * @var array
     */
    protected $highlight = [];

    protected $sql;

    /**
     * @var []
     */
    protected $sort = [];

    /**
     * The model being queried.
     *
     * @var Model
     */
    protected $model;

    /**
     * @var int
     */
    protected $take = 50;

    /**
     * @var array
     */
    protected $operate = ['=', '>', '<', '>=', '<=', '!=', '<>', 'in', 'not in', 'like', 'regex', 'prefix', 'filter', 'range', 'nested', 'multi match', 'or'];

    protected array $source = [];

    /**
     * aggs condition.
     */
    protected array $aggs = [];

    /**
     * 分页.
     */
    public function page(int $size = 50, int $page = 1): Paginator
    {
        $from = (($page - 1) * $size);
        $this->sql = [
            'from' => $from,
            'size' => $size,
            'track_total_hits' => true,
            'index' => $this->model->getIndex(),
            'body' => [
                'query' => $this->query,
                'highlight' => $this->highlight,
                'sort' => $this->sort,
                '_source' => $this->source,
            ],
        ];
        if (empty($this->query)) {
            $this->sql = [
                'from' => $from,
                'size' => $size,
                'track_total_hits' => true,
                'index' => $this->model->getIndex(),
                'body' => [
                    'query' => [
                        'match_all' => new stdClass(),
                    ],
                ],
            ];
        }
        $result = $this->run('search', $this->sql);
        $original = $result['hits']['hits'] ?? [];
        $total = $result['hits']['total']['value'] ?? 0;
        /** @phpstan-ignore-next-line */
        $collection = Collection::make($original)->map(function ($value) {
            $attributes = $value['_source'] ?? [];
            $attributes['hit_score'] = $value['_score'];
            $model = $this->model->newInstance();
            $model->setAttributes($attributes);
            $model->setOriginal($value);
            return $model;
        });

        return make(Paginator::class, ['items' => $collection, 'perPage' => $size, 'currentPage' => $page, 'totalItems' => $total]);
    }

    public function select(array $fields): Builder
    {
        $this->source = $fields;

        return $this;
    }

    public function get($size = 50): Collection
    {
        $this->sql = [
            'from' => 0,
            'size' => $size,
            'track_total_hits' => true,
            'index' => $this->model->getIndex(),
            'body' => [
                'query' => $this->query,
                'highlight' => $this->highlight,
                'sort' => $this->sort,
                '_source' => $this->source,
            ],
        ];

        if (empty($this->query)) {
            $this->sql = [
                'from' => 0,
                'size' => $size,
                'track_total_hits' => true,
                'index' => $this->model->getIndex(),
                'body' => [
                    'query' => [
                        'match_all' => new stdClass(),
                    ],
                ],
            ];
        }
        $result = $this->run('search', $this->sql);
        $original = $result['hits']['hits'] ?? [];
        /* @phpstan-ignore-next-line */
        return Collection::make($original)->map(function ($value) {
            $attributes = $value['_source'] ?? [];
            $model = $this->model->newInstance();
            $model->setAttributes($attributes);
            $model->setOriginal($value);
            return $model;
        });
    }

    public function first(): ?Model
    {
        return $this->take(1)->get()->first();
    }

    public function take(int $take): Builder
    {
        $this->take = $take;
        return $this;
    }

    /**
     * 查找单条
     * @param mixed $id
     */
    public function find($id): ?Model
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'id' => $id,
        ];
        try {
            $result = $this->run('get', $this->sql);
        } catch (Missing404Exception) {
            return null;
        }

        $this->model->setAttributes($result['_source'] ?? []);
        $this->model->setOriginal($result);
        return $this->model;
    }

    /**
     * 批量插入.
     */
    public function insert(array $values): Collection
    {
        $body = [];
        foreach ($values as $value) {
            $body['body'][] = [
                'index' => [
                    '_index' => $this->model->getIndex(),
                    ...(isset($value['_id']) ? ['_id' => $value['_id']] : []),
                ],
            ];
            unset($value['_id']);
            $body['body'][] = $value;
        }

        $this->sql = $body;
        $result = $this->run('bulk', $this->sql);
        return collect($result['items'])->map(function ($value, $key) use ($values) {
            $model = $this->model->newInstance();
            $model->setAttributes(Arr::merge($values[$key] ?? [], ['_id' => $value['index']['_id'] ?? '']));
            $model->setOriginal($value);
            return $model;
        });
    }

    public function upsert(int|string $id, array $data): bool
    {
        $result = $this->run('update', [
            'index' => $this->model->getIndex(),
            'id' => $id,
            'body' => [
                'doc' => $data,
                'doc_as_upsert' => true,
            ],
        ]);
        if (! empty($result['result']) && ($result['result'] == 'updated' || $result['result'] == 'noop')) {
            return true;
        }

        return false;
    }

    public function whereFunctionScore(array $query): Builder
    {
        $this->query['function_score'] = $query;

        return $this;
    }

    public function whereBetween(string $field, array $value): Builder
    {
        $this->query['bool']['must'][] = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];

        return $this;
    }

    public function geo(float $lat, float $lng, string $field = 'coordinate', int $distance = 2000): Builder
    {
        $this->query['bool']['filter'][] = [
            'geo_distance' => [
                'distance' => $distance . 'm',
                $field => [
                    'lat' => $lat,
                    'lon' => $lng,
                ],
            ],
        ];

        return $this;
    }

    public function orderByGeo(float $lat, float $lng, string $field = 'coordinate', string $order = 'asc'): Builder
    {
        if (! is_array($this->sort)) {
            $this->sort = [];
        }

        $this->sort[] = [
            '_geo_distance' => [
                $field => [
                    'lat' => $lat,
                    'lon' => $lng,
                ],
                'order' => $order,
                'unit' => 'm',
            ],
        ];

        return $this;
    }

    public function orderByScript(array $script): Builder
    {
        if (! is_array($this->sort)) {
            $this->sort = [];
        }

        $this->sort[] = $script;

        return $this;
    }

    public function orderByField(string $field, array $fieldValues): Builder
    {
        if (! is_array($this->sort)) {
            $this->sort = [];
        }

        $this->sort[] = [
            '_script' => [
                'script' => [
                    'lang' => 'painless',
                    'params' => ['ids' => $fieldValues],
                    'source' => <<<EOD
                        int idsCount = params.ids.size();
                        int id = (int)doc['{$field}'].value;
                        int foundIdx = params.ids.indexOf(id);
                        return foundIdx > -1 ? foundIdx: idsCount + 1;
                    EOD,
                ],
                'type' => 'number',
                'order' => 'asc',
            ],
        ];

        return $this;
    }

    /**
     * group by field.
     */
    public function groupBy(string $field, string $name, ?int $size = null): Builder
    {
        $this->aggs[$name] = [
            'terms' => [
                'field' => $field,
                ...(is_null($size) ? [] : ['size' => $size]),
            ],
        ];

        return $this;
    }

    /**
     * get aggregations result.
     * @throws TypeError
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function getAggregations(?array $aggs = null): Collection
    {
        $this->sql = [
            'from' => 0,
            'size' => 0,
            'track_total_hits' => true,
            'index' => $this->model->getIndex(),
            'body' => [
                'query' => $this->query,
                'highlight' => $this->highlight,
                'sort' => $this->sort,
                '_source' => $this->source,
                'aggs' => $this->aggs,
            ],
        ];

        if (empty($this->query)) {
            $this->sql = [
                'from' => 0,
                'size' => 0,
                'track_total_hits' => true,
                'index' => $this->model->getIndex(),
                'body' => [
                    'query' => [
                        'match_all' => new stdClass(),
                    ],
                ],
            ];
        }
        $result = $this->run('search', $this->sql);
        $aggsResult = $result['aggregations'] ?? [];

        /* @phpstan-ignore-next-line */
        return Collection::make($aggsResult)->filter(function ($value, $key) use ($aggs) {
            if (is_null($aggs)) {
                return true;
            }

            return in_array($key, $aggs);
        });
    }

    /**
     * insert.
     *
     * @return static
     */
    public function create(array $value): Model
    {
        $body = Arr::except($value, ['id', 'routing', 'timestamp']);
        $except = Arr::only($value, ['id', 'routing', 'timestamp']);
        $this->sql = Arr::merge($except, [
            'index' => $this->model->getIndex(),
            'body' => $body,
        ]);
        $result = $this->run('index', $this->sql);
        if (! empty($result['result']) && $result['result'] == 'created') {
            $this->model->setOriginal($result);
            $this->model->setAttributes(Arr::merge($body, ['_id' => $result['_id'] ?? '']));
        }
        return $this->model;
    }

    /**
     * update.
     *
     * @param string $id
     *
     * @return bool
     */
    public function update(array $value, $id)
    {
        $result = $this->run(
            'update',
            [
                'index' => $this->model->getIndex(),
                'id' => $id,
                'body' => [
                    'doc' => $value,
                ],
            ],
        );
        if (! empty($result['result']) && ($result['result'] == 'updated' || $result['result'] == 'noop')) {
            $this->model->setOriginal($result);
            $this->model->setAttributes(['_id' => $result['_id'] ?? '']);
        }
        return $this->model;
    }

    /**
     * delete document.
     */
    public function delete(string $id): bool
    {
        try {
            $result = $this->run(
                'delete',
                [
                    'index' => $this->model->getIndex(),
                    'id' => $id,
                ],
            );
        } catch (Missing404Exception) {
            return true;
        }
        if (! empty($result['result']) && $result['result'] == 'deleted') {
            return true;
        }
        return false;
    }

    public function updateMapping(array $mappings)
    {
        $mappings = collect($mappings)->map(function ($value, $key) {
            $valued = [];
            if (is_string($value)) {
                $valued['type'] = $value;
            }
            if (is_array($value)) {
                $valued = $value;
            }
            return $valued;
        })->toArray();
        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                'properties' => $mappings,
            ],
        ];
        return $this->run('indices.putMapping', $this->sql);
    }

    public function updateSetting(array $settings)
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                'settings' => $settings,
            ],
        ];
        return $this->run('putSettings', $this->sql);
    }

    public function createIndex(array $mappings = [], array $settings = [])
    {
        $mappings = Arr::merge(
            Collection::make($this->model->getCasts())->map(function ($value, $key) {
                $valued = '';
                if (is_string($value)) {
                    $valued['type'] = $value;
                }
                if (is_array($value)) {
                    $valued = $value;
                }
                return $valued;
            })->toArray(),
            Collection::make($mappings)->map(function ($value, $key) {
                $valued = '';
                if (is_string($value)) {
                    $valued['type'] = $value;
                }
                if (is_array($value)) {
                    $valued = $value;
                }
                return $valued;
            })->toArray()
        );
        $this->sql = [
            'index' => $this->model->getIndex(),
            'id' => '1',
            'body' => compact('mappings') + compact('settings'),
        ];
        // Create the index
        return $this->run('create', $this->sql);
    }

    public function matchPhrase(string $field, $value)
    {
        $this->query['match_phrase'][$field] = $value;
        return $this;
    }

    public function match(string $field, $value)
    {
        $this->query['match'][$field] = $value;
        return $this;
    }

    public function where(string $field, string $operate, mixed $value = null): Builder
    {
        if (is_null($value)) {
            $value = $operate;
            $operate = '=';
        }
        if (in_array($operate, $this->operate)) {
            $this->parseQuery($field, $operate, $value);
        }
        return $this;
    }

    public function getPayLoad(): array
    {
        return [
            'query' => $this->query,
            'highlight' => $this->highlight,
            'sort' => $this->sort,
        ];
    }

    public function whereRaw(array $value): Builder
    {
        $this->query['bool']['must'][] = $value;

        return $this;
    }

    public function whereOr(string $field, array $value): Builder
    {
        $this->query['bool']['must'][]['bool'] = $this->parseOr($field, $value);
        return $this;
    }

    public function whereIn(string $field, $value): Builder
    {
        return $this->where($field, 'in', $value);
    }

    public function whereNotIn(string $field, $value): Builder
    {
        return $this->where($field, 'not in', $value);
    }

    public function whereLike(string $field, $value): Builder
    {
        return $this->where($field, 'like', $value);
    }

    public function highlight($fields)
    {
        $fields = (array) $fields;

        $fields = collect($fields)
            ->mapWithKeys(function ($value, $key) {
                return is_numeric($key)
                    ? [$value => new stdClass()]
                    : [$key => (object) $value];
            })->toArray();
        $this->highlight = compact('fields');

        return $this;
    }

    /**
     * orderBy.
     */
    public function orderBy(string $field, bool $desc = false, array $extra = []): Builder
    {
        $sort = $desc ? 'desc' : 'asc';
        if (! is_array($this->sort)) {
            $this->sort = [];
        }
        $value = ['order' => $sort];
        $extra && $value = array_merge($value, $extra);
        $this->sort[] = [$field => $value];

        return $this;
    }

    /**
     * Set a model instance for the model being queried.
     *
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;
        $this->client = $model->getClient();
        $this->highlight = new stdClass();
        $this->sort = new stdClass();
        return $this;
    }

    /** @phpstan-ignore-next-line */
    public function when($value, $callback, $default = null): self
    {
        if ($value) {
            return $callback($this, $value) ?: $this;
        }
        if ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    public function addAggs(string $name, array $aggregation): Builder
    {
        $this->aggs[$name] = $aggregation;
        return $this;
    }

    /**
     * 根据文档ID更新单个字段.
     *
     * @param string|int $id 文档ID
     * @param array $fields 要更新的字段数组，格式：['field1' => 'value1', 'field2' => 'value2']
     * @param array $options 额外选项
     * @return array|false 返回更新结果或 false
     * @throws Throwable
     */
    public function updateFieldById(string|int $id, array $fields, array $options = []): array|false
    {
        return $this->updateFields($fields, [
            'term' => [
                '_id' => $id,
            ],
        ], $options);
    }

    /**
     * 批量更新字段（支持多个字段同时更新）.
     *
     * @param array $fields 要更新的字段数组，格式：['field1' => 'value1', 'field2' => 'value2']
     * @param array $query 查询条件
     * @param array $options 额外选项，支持：
     *                       - conflicts: 'proceed'|'abort' (默认: 'proceed')
     *                       - refresh: true|false (默认: false)
     *                       - wait_for_completion: true|false (默认: true)
     *                       - slices: int (并行切片数量)
     * @return array|false 返回更新结果或 false
     * @throws Throwable
     */
    public function updateFields(array $fields, array $query = [], array $options = []): array|false
    {
        // 构建 Painless 脚本
        $scriptParts = [];
        $params = [];

        foreach ($fields as $field => $value) {
            $scriptParts[] = "ctx._source.{$field} = params.{$field}";
            $params[$field] = $value;
        }

        $script = implode('; ', $scriptParts);

        // 默认选项：处理版本冲突
        $defaultOptions = [
            'conflicts' => 'proceed',  // 遇到版本冲突时继续处理
            'refresh' => false,        // 不立即刷新，提高性能
        ];

        $updateParams = [
            'index' => $this->model->getIndex(),
            'body' => [
                'script' => [
                    'source' => $script,
                    'lang' => 'painless',
                    'params' => $params,
                ],
            ],
        ];

        // 添加查询条件
        if (! empty($query)) {
            $updateParams['body']['query'] = $query;
        }

        // 合并默认选项和用户选项
        $updateParams = array_merge($updateParams, $defaultOptions, $options);

        try {
            return $this->run('updateByQuery', $updateParams);
        } catch (Exception $e) {
            throw new Exception('Update fields failed: ' . json_encode($fields) . ' Error: ' . $e->getMessage());
        }
    }

    protected function parseQuery(string $field, string $operate, mixed $value): Builder
    {
        $parsedOperation = $this->parseOperation($field, $operate, $value);
        match ($operate) {
            'like',
            '=',
            '>',
            '<',
            '>=',
            '<=',
            'in',
            'regex',
            'prefix',
            'multi match',
            'range' => $this->query['bool']['must'][] = $parsedOperation,
            'nested' => $this->query['bool']['filter'][] = $parsedOperation,
            '<>', '!=',
            'not in' => $this->query['bool']['must_not'][] = $parsedOperation,
            'or' => $this->query['bool']['must'][]['bool'] = $this->parseOr($field, $value),
            default => $this->query['bool'][$operate][] = [$field => $value],
        };

        return $this;
    }

    protected function parseOperation(string $field, string $operate, mixed $value): array
    {
        return match ($operate) {
            'like' => ['match' => [$field => $value]],
            '=', '<>', '!=' => ['term' => [$field => $value]],
            '>' => ['range' => [$field => ['gt' => $value]]],
            '<' => ['range' => [$field => ['lt' => $value]]],
            '>=' => ['range' => [$field => ['gte' => $value]]],
            '<=' => ['range' => [$field => ['lte' => $value]]],
            'in', 'not in' => ['terms' => [$field => $value]],
            'regex' => ['regexp' => [$field => $value]],
            'prefix' => ['prefix' => [$field => $value]],
            'nested' => ['nested' => ['path' => $field, 'query' => $value]],
            'multi match' => ['multi_match' => $value],
            'range' => ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]],
            default => [$operate => [$field => $value]],
        };
    }

    protected function run($method, ...$parameters)
    {
        $client = $this->client;
        $sql = $this->sql;
        if (strpos($method, '.')) {
            $methods = explode('.', $method);
            $method = $methods[1];
            $client = $client->{$methods[0]}();
        }
        ApplicationContext::getContainer()
            ->get(LoggerFactory::class)
            ->get('elasticsearch', 'default')
            ->debug('Elasticsearch run: ' . json_encode(compact('method', 'parameters', 'sql')));
        return call([$client, $method], $parameters);
    }

    protected function parseOr(string $field, array $value): array
    {
        $should = [];
        foreach ($value as $v) {
            $should[] = $this->parseOperation($field, $v[0], $v[1]);
        }
        return ['should' => $should, 'minimum_should_match' => 1];
    }
}
