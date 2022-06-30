## 使用 php + es

> 场景一：
> 
> 第一次使用时，部署好es后，配置config
```php
$config = [
    'host' => ['192.168.0.52:9200'],//es地址
    'index' => 'wares_v3',          //索引名称
];

//创建索引
if(!$es->isCreate()){
    //创建索引配置，具体可以查看es文档
    $es->isCreate([
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
                '_doc' => [
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
                    ],
                ]
            ],
        ]
    ]);
}

//...可以开始开发了
```

> 场景二：
> 
> 由于场景一种mappings下面的字段需要增加updated_at，这时可以使用版本进行更新
> 
> 这是，我们使用新的索引wares_v4，并设置一个别名wares，然后把old_index设置为原本的索引名称wares_v3，意思是把V3的数据复制到V4去，V3的数据不用保留的话就把is_delete_old设置为true

```php

$config = [
    'host' => ['192.168.0.52:9200'],//es地址
    'index' => 'wares_v4',          //索引名称
    'alias' => 'wares',             //别名
    'old_host' => '',               //需要迁移的地址，默认为空，该字段暂不支持
    'old_index' => 'wares_v3',      //要迁移的索引，如果和index不同，则会把该索引下面的数据复制到index下
    'is_delete_old' => true,        //是否删除旧版索引，如果为true，则复制到index后，会删除old_index的内容
];
```

> 然后通过 autoVersionManagement 方法自动管理版本库

```php
$es->autoVersionManagement($config,[
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
            '_doc' => [
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
                ],
            ]
        ],
    ]
])

```

> 如果后续再有修改，只需要修改配置config，把index加一个版本 wares_v5，old_index=wares_v4，就可以了

```php
$config = [
    'host' => ['192.168.0.52:9200'],//es地址
    'index' => 'wares_v5',          //索引名称
    'alias' => 'wares',             //别名
    'old_host' => '',               //需要迁移的地址，默认为空，该字段暂不支持
    'old_index' => 'wares_v4',      //要迁移的索引，如果和index不同，则会把该索引下面的数据复制到index下
    'is_delete_old' => true,        //是否删除旧版索引，如果为true，则复制到index后，会删除old_index的内容
];
$es->autoVersionManagement($config,[
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
            '_doc' => [
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
                    'updated_at' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                    ],
                ],
            ]
        ],
    ]
])
```
> 这一次我去掉了created_at字段