<?php /** @noinspection SpellCheckingInspection */

/** @noinspection PhpUnused */

namespace es;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Namespaces\IndicesNamespace;
use Exception;

/**
 * User: xds
 * Date: 20220512
 * explain:
 * @use Elasticsearch 6.8.13
 * @method Client client()
 * @method IndicesNamespace indices()
 * @method mixed|$this dump()
 */
class ElasticsearchService
{
    protected $original_index = '';
    protected $index = '';
    protected $type = '_doc';
    protected $alias = '';
    protected $requet_type = 0;
    protected $params = [];
    protected $is_create = false;
    protected $is_create_alias = false;
    protected $is_index = true;
    protected $is_type = false;
    protected $is_ignore = true;
    protected $is_dump = false;


    protected $drive;

    public function __construct( array $host, $index = '' )
    {
        $this->drive = ClientBuilder::create()->setHosts( $host )->build();

        $index && $this->index = $this->original_index = $index;

        $this->is_create = $this->drive->indices()->exists( ['index' => $this->index] );
    }

    /**
     * User: xds
     * Date: 20220519
     * explain:
     * @return string
     */
    public function originalIndexHandle(): string
    {
        return $this->original_index;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 设置或查看index
     * @param $index
     * @return $this|string
     */
    public function indexHandle( $index = null )
    {
        if ( is_null( $index ) ) return $this->index;
        !$this->original_index && $this->original_index = $this->index;
        $this->index = $index;
        return $this;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 设置或查看type
     * @param $type
     * @return $this|string
     */
    public function typeHandle( $type = null )
    {
        if ( is_null( $type ) ) return $this->type;
        $this->type = $type;
        return $this;
    }

    /**
     * User: xds
     * Date: 20220518
     * explain: 只通过别名访问es
     * @param      $alias
     * @param null $index     把索引关联至该别名
     * @param null $old_index 把旧索引的别名更新为新索引的别名
     * @return $this|array
     */
    public function aliasHandle( $alias, $index = null, $old_index = null )
    {

        if ( !is_null( $index ) ) {

            $res['acknowledged'] = false;

            if ( $this->drive->indices()->exists( ['index' => $index] ) ) {

                if ( is_null( $old_index ) ) {

                    $res = $this->drive->indices()->putAlias( ['index' => $index, 'name' => $alias] );

                }
                else {

                    $oldAlias = $this->getAlias( ['name' => $alias] );

                    if ( isset( $oldAlias[$old_index] ) && isset( $oldAlias[$old_index]['aliases'][$alias] ) ) {

                        $res = $this->drive->indices()->updateAliases( [
                            'body' => [
                                'actions' => [
                                    [
                                        'remove' => [
                                            'alias' => $alias,
                                            'index' => $old_index
                                        ]
                                    ],
                                    [
                                        'add' => [
                                            'alias' => $alias,
                                            'index' => $index
                                        ]
                                    ]
                                ]
                            ]
                        ] );

                    }

                }
            }

            return $res;

        }

        $this->is_create_alias = $this->drive->indices()->existsAlias( ['name' => $alias] );
        !$this->original_index && $this->original_index = $this->index;
        $this->index = $this->alias = $alias;
        return $this;
    }

    /**
     * User: xds
     * Date: 20220518
     * explain: 创建索引/是否创建
     * @param array $params
     * @return bool
     */
    public function isCreate( array $params = [] ): bool
    {
        if ( !isset( $params['index'] ) ) {
            $params['index'] = $this->original_index;

            $is_create = $this->is_create;
        }
        else {
            $is_create = $this->drive->indices()->exists( ['index' => $params['index']] );
        }

        if ( $params && !$is_create ) {
            $this->drive->indices()->create( $params );

            $this->is_create = true;
        }
        return $this->is_create;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 请求es api时，是否携带index字段 ,默认携带
     * @param bool $bool
     * @return $this
     */
    public function isIndex( bool $bool ): self
    {
        $this->is_index = $bool;
        return $this;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 请求es api时，是否携带type字段 ,默认不携带
     * @param bool $bool
     * @return $this
     */
    public function isType( bool $bool ): self
    {
        $this->is_type = $bool;
        return $this;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 是否忽略404
     * @param bool $bool
     * @return $this
     */
    public function isIgnore( bool $bool ): self
    {
        $this->is_ignore = $bool;
        return $this;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 获取请求参数
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * User: xds
     * Date: 20220516
     * explain: 批量创建/更新文档
     * @param array $data
     * @param int   $size
     * @return array
     */
    public function bulk( array $data, int $size = 500 ): array
    {
        $res = [];
        $params = [];

        if ( !$data ) return $res;

        foreach ( $data as $v ) {
            $index = [
                'index' => [
                    '_index' => $this->index,
                    '_type' => $this->type,
                ]
            ];
            isset( $v['id'] ) && $index['index']['_id'] = $v['id'];

            $params[] = $index;
            $params[] = $v;
            unset( $index );
        }

        $params = array_chunk( $params, $size );

        foreach ( $params as $v ) {
            $res[] = $this->drive->bulk( ['body' => $v] );
        }
        return $res;
    }

    /**
     * User: xds
     * Date: 20220518
     * explain: 查看别名
     * @param array $params
     * @param bool  $original
     * @return array
     */
    public function getAlias( array $params, bool $original = true ): array
    {
        if ( $this->drive->indices()->existsAlias( ['name' => $params['name']] ) ) {
            if ( !$original ) {
                $params['index'] = $this->original_index;
            }

            $res = $this->drive->indices()->getAlias( $params );
        }

        return $res ?? [];
    }

    /**
     * User: xds
     * Date: 20220518
     * explain: 删除别名
     * @param $index
     * @param $alias
     * @return array
     */
    public function delAlias( $index = null, $alias = null ): array
    {

        $index = $index ?: $this->original_index;
        $alias = $alias ?: $this->alias;

        if ( $this->drive->indices()->existsAlias( ['name' => $alias] ) ) {

            $res = $this->drive->indices()->deleteAlias( [
                'index' => $index,
                'name' => $alias
            ] );

        }

        return $res ?? [];
    }


    /**
     * User: xds
     * Date: 20220518
     * explain: 断点打印参数
     * @param $method
     * @param ...$arguments
     * @return $this|false
     */
    protected function setDump( $method, ...$arguments )
    {
        if ( $method == 'dump' ) {
            $this->is_dump = true;
            return $this;
        }
        if ( $this->is_dump ) {
            var_dump( [$this->is_index, $method, $this->params, $arguments] );
            exit();
        }

        return false;
    }

    /**
     * User: xds
     * Date: 20220518
     * explain: 公共参数
     * @return void
     */
    protected function getPublicParams( $params )
    {
        if ( $params == 'all' ) return;

        $public_params = [];

        //judge index
        if ( $this->is_index ) $public_params['index'] = $this->index;

        //judge type
        if ( $this->is_type ) $public_params['type'] = $this->type;

        //judge ignore
        if ( $this->is_ignore ) $public_params['client'] = ['ignore' => 404];

        $this->params = array_merge( $public_params, is_array( $params ) ? $params : [] );
    }

    /**
     * @return $this|mixed
     * @throws Exception
     */
    public function __call( $name, $arguments )
    {
        // TODO: Implement __call() method.

        $this->getPublicParams( array_shift( $arguments ) );

        if ( $name == 'indices' ) {
            $this->requet_type = 2;
            return $this;
        }
        if ( $name == 'client' ) {
            $this->requet_type = 1;
            return $this;
        }

        if ( !$this->is_create && !( $name == 'create' && $this->requet_type == 2 ) && $this->params ) {
            throw new Exception( "[$name]not create index: " . ( $this->original_index ?: $this->index ) );
        }

        $instance = $this->drive;

        if ( $this->requet_type == 2 ) $instance = $this->drive->indices();

        if ( strpos( $name, 'Script' ) !== false ) unset( $this->params['index'] );

        return $this->setDump( $name, ...$arguments ) ?: $instance->$name( $this->params, ...$arguments );
    }

}