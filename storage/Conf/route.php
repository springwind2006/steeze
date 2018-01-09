<?php
//此配置文件只能在全局配置
return [
	'default' => [
		'/'=> function(){
			return 'Hello world!';
		},
		'/hello'=> 'Index/hello@home',
		'/member/index/hello'=> 'Member/Index/hello@home',
		'auth&convert' => [
			'/{c}/{a}'=>'{c}/{a}',
			'/{c}/{a}/{user|d}'=>'{c}/{a}',
		]
	]
];