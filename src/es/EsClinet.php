<?php /** @noinspection PhpUnused */

/** @noinspection SpellCheckingInspection */

namespace es;

/**
 * User: xds
 * Date: 20220519
 * explain:
 */
class EsClinet
{
    static private $instanse;
    protected $es;

    //如果wares的mappings需要更改，可以直接把 wares_v1 改为 wares_v2 ，再更改"别名+Create"方法对应的mappings，然后检查无误后删除wares_v1就好了
    private $config = [
        'es_wares' => [
            'host' => ['192.168.0.52:9200'],
            'index' => 'wares_v2',
            'alias' => 'wares',
            'old_host' => '',
            'old_index' => 'wares_v1',
            'is_delete_old' => true
        ],
        'es_test' => [
            'host' => ['192.168.0.52:9200'],
            'index' => 'test_v1',
            'alias' => 'test',
            'old_host' => '',
            'old_index' => 'test_v5',
            'is_delete_old' => true
        ],
        'es_wares_temp' => [
            'host' => ['192.168.0.52:9200'],
            'index' => 'wares_temp_v1',
            'alias' => 'wares_temp',
            'old_host' => '',
            'old_index' => '',
            'is_delete_old' => false
        ],
    ];

    private function __construct( $name )
    {
        $this->config = is_array( $name ) ? $name : $this->config[$name];

        $this->es = new ElasticsearchService( $this->config['host'], $this->config['index'] );

        //使用别名
        if ( $this->config['alias'] ) $this->es = $this->es->aliasHandle( $this->config['alias'] );

        //检查索引
        if ( method_exists( $this, $this->config['alias'] . 'Create' ) ) {
            if ( call_user_func( [$this, $this->config['alias'] . 'Create'] ) ) {
                if ( !$this->es->indices()->existsAlias(  ['name' => $this->config['alias'], 'index' => $this->config['index']]  ) ) {
                    //版本升级
                    $this->version_update( $this->config['old_index'], $this->config['index'], $this->config['alias'], $this->config['is_delete_old'] );
                }
            }
        }
    }

    private function __clone()
    {
        // TODO: Implement __clone() method.
    }

    static public function instanse( string $name, array $config = [] )
    {
        if ( !isset( self::$instanse[$name] ) ) {
            self::$instanse[$name] = ( new self( $config?:$name ) )->es;
        }
        return self::$instanse[$name];
    }

    /**
     * User: xds
     * Date: 20220519
     * explain: 版本升级
     * @param $old_index
     * @param $index
     * @param $alias
     * @param $is_delete_old
     * @return bool
     */
    protected function version_update( $old_index, $index, $alias, $is_delete_old ): bool
    {

        //数据同步
        if ( $old_index != $index ) {
            $this->es->isIndex( false );
            $this->es->isIgnore( false );

            $res = $this->es->client()->reindex(
                [
                    'refresh' => true,              //(boolean)是否应该刷新受影响的索引?
                    'wait_for_completion' => true,  //(boolean)请求是否应该阻塞，直到reindex完成。 (默认= true)
                    'slices' => 'auto',             //任务是否被分片 默认1,
                    'body' => [
                        'conflicts' => 'proceed', //将冲突进行类似于continue的操作
                        'source' => [
                            'index' => $old_index,
                            'size' => 10000, //批量操作数量，默认1000
                        ],
                        'dest' => [
                            'index' => $index,
                            'op_type' => 'create'//只会对发生不同的document进行reindex
                        ],
                    ],
                ]
            );
            // if ( isset( $res['task'] ) ) {
            //     //异步时可通过task查询同步任务是否完成
            //     dump( $this->es->isIndex( false )->client()->tasks()->get( ['task_id' => $res['task']] ) );
            // }
            $this->es->isIgnore( true );
            $this->es->isIndex( true );
        }

        //关联别名
        if ( $this->es->aliasHandle( $alias, $index ) ) {
            //删除旧版本
            if ( $old_index != $index && $is_delete_old ) {
                //备份旧版索引 settings 和 mappings
                backup( $this->es->indices()->getSettings( ['index' => $old_index] ), "test-$old_index-setting.log" );
                backup( $this->es->indices()->getMapping( ['index' => $old_index] ), "test-$old_index-mapping.log" );

                $this->es->indices()->delete( ['index' => $old_index] );
            }
        }

        return true;
    }

    //中文+拼音测试
    protected function testCreate( $type = null ): bool
    {

        return $this->es->isCreate( [
            'body' => [
                'settings' => [
                    'analysis' => [
                        //配置分析器
                        'analyzer' => [
                            //IK分词器+拼音分词过滤器+内置ngram分词过滤器
                            'ik_max_word_analyzer' => [
                                'tokenizer' => 'ik_max_word',//ik_max_word-细粒度分词
                                'filter' => ['icfilter', 'my_pinyin']
                            ],
                            //IK分词器+英文转小写+将ASCII码不在ASCII表前127内的字母、数字和Unicode符号转换为ASCII等效字符
                            'ik_smart_analyzer' => [
                                'tokenizer' => 'ik_smart',//ik_smart-粗粒度分词
                                'filter' => ['lowercase', 'asciifolding']
                            ],
                        ],
                        //配置分词过滤器
                        'filter' => [
                            'my_pinyin' => [
                                'type' => 'pinyin',
                                'keep_separate_first_letter' => false,
                                'keep_full_pinyin' => true,
                                'keep_original' => true,
                                'limit_first_letter_length' => 16,
                                'lowercase' => true,
                                'remove_duplicated_term' => true
                            ],
                            'icfilter' => [
                                'type' => 'ngram',
                                'min_gram' => 1,
                                'max_gram' => 128
                            ]
                        ],
                    ]
                ],
                'mappings' => [
                    ( $type ?: $this->es->typeHandle() ) => [
                        'dynamic' => false,
                        'properties' => [
                            'content' => [
                                'type' => 'text',
                                'analyzer' => 'ik_max_word_analyzer',
                                'search_analyzer' => 'ik_smart_analyzer',
                            ],
                        ],
                    ]
                ]
            ],
        ] );
    }

    //正品商家
    protected function waresCreate( $type = null ): bool
    {
        return $this->es->isCreate( [
            'body' => [
                'settings' => [
                    'analysis' => [
                        //配置分析器
                        'analyzer' => [
                            //IK分词器+拼音分词过滤器+内置ngram分词过滤器
                            'ik_max_word_analyzer' => [
                                'tokenizer' => 'ik_max_word',//ik_max_word-细粒度分词
                                'filter' => ['icfilter', 'my_pinyin', 'unique']
                            ],
                            //IK分词器+英文转小写+将ASCII码不在ASCII表前127内的字母、数字和Unicode符号转换为ASCII等效字符
                            'ik_smart_analyzer' => [
                                'tokenizer' => 'ik_smart',//ik_smart-粗粒度分词
                                'filter' => ['lowercase', 'asciifolding']
                            ],
                        ],
                        //配置分词过滤器
                        'filter' => [
                            'my_pinyin' => [
                                'type' => 'pinyin',
                                'keep_separate_first_letter' => false,
                                'keep_full_pinyin' => true,
                                'keep_original' => true,
                                'limit_first_letter_length' => 16,
                                'lowercase' => true,
                                'remove_duplicated_term' => true
                            ],
                            'icfilter' => [
                                'type' => 'ngram',
                                'min_gram' => 1,
                                'max_gram' => 128
                            ]
                        ],
                    ]
                ],
                'mappings' => [
                    ( $type ?: $this->es->typeHandle() ) => [
                        'dynamic' => false,
                        'properties' => [
                            'content' => [
                                'type' => 'text',
                                'analyzer' => 'ik_max_word_analyzer',
                                'search_analyzer' => 'ik_smart_analyzer',
                            ],
                            'brand' => [
                                'type' => 'keyword',
                                'copy_to' => ['content']
                            ],
                            'model' => [
                                'type' => 'keyword',
                                'copy_to' => ['content']
                            ],
                            'spec' => [
                                'type' => 'keyword',
                                'copy_to' => ['content']
                            ],
                            'created_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                            ],
                            'updated_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                            ],
                            'reviews_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                            ],
                            'catal' => [
                                'properties' => [
                                    'id' => [
                                        'type' => 'long'
                                    ]
                                ]
                            ]
                        ],
                    ]
                ],
            ]
        ] );
    }

    //正品商家-原始版本
    protected function wares_tempCreate( $type = null ): bool
    {
        return $this->es->isCreate( [
            'body' => [
                'settings' => [
                    'analysis' => [
                        'analyzer' => [
                            //分词
                            'whites_analyzer' => [
                                'tokenizer' => 'whitespace',
                                'filter' => ['lowercase', 'asciifolding']
                            ],
                            'ictoken_analyzer' => [
                                'tokenizer' => 'ictoken',
                                'filter' => ['lowercase', 'asciifolding']
                            ],
                        ],
                        'tokenizer' => [
                            //分词器
                            'ictoken' => [
                                'type' => 'ngram',
                                'min_gram' => 2,
                                'max_gram' => 128
                            ]
                        ]
                    ]
                ],
                'mappings' => [
                    ( $type ?: $this->es->typeHandle() ) => [
                        'dynamic' => false,
                        'properties' => [
                            'content' => [
                                'type' => 'text',
                                'analyzer' => 'ictoken_analyzer',
                                'search_analyzer' => 'whites_analyzer',
                            ],
                            'brand' => [
                                'type' => 'keyword',
                                'copy_to' => ['content']
                            ],
                            'model' => [
                                'type' => 'keyword',
                                'copy_to' => ['content']
                            ],
                            'spec' => [
                                'type' => 'keyword',
                                'copy_to' => ['content']
                            ],
                            'created_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                            ],
                            'update_data' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                            ],
                            'reviews_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                            ],
                        ],
                    ]
                ],
            ]
        ] );
    }
}

function backup( $content, $filename, $dir = null ): bool
{
    $dir = ( $dir ?: __DIR__ ) . '/log';

    if ( !is_dir( $dir ) ) {

        mkdir( $dir, '0777' );
    }

    $filename = $dir . '/' . trim( $filename, '/' );

    $file = fopen( $filename, 'w' );

    if ( is_string( $content ) ) {

        $content = json_decode( $content ) ?: $content;
    }

    if ( is_object( $content ) || is_array( $content ) ) {

        $content = json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
    }

    fwrite( $file, date( 'Y-m-d H:i:s' ) . PHP_EOL . $content . PHP_EOL . PHP_EOL );

    fclose( $file );

    return true;
}