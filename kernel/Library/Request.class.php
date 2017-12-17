<?php
namespace Library;

class Request{
	static private $middlewares=[]; //中间件数组
	private $params=[]; //绑定的路由参数
	private $disposer=null; //请求处理器
	
	public function __construct(){
		if(get_magic_quotes_gpc()){
			$_POST=slashes($_POST, 0);
			$_GET=slashes($_GET, 0);
			$_REQUEST=slashes($_REQUEST, 0);
			$_COOKIE=slashes($_COOKIE, 0);
		}
		
		//session_id设置，防止客户端不支持cookie设定
		if($sessionid=I('PHPSESSID')){
			session_id($sessionid);
		}
		
		//路由绑定
		$this->bind();
	}
	
	/*
	 * 获取路由匹配参数
	 * @param string $name 参数名称 如果为null，则返回参数数组
	 * return string|array
	 */
	public function getParam($name=null){
		return is_null($name) ? $this->params : $this->params[$name];
	}
	
	/*
	 * 设置绑定的控制器
	 * @param object $disposer 绑定的控制器
	 */
	public function setDisposer($disposer){
		$this->disposer=$disposer;
	}
	
	/*
	 * 获取绑定的控制器
	 * @return object $disposer 绑定的控制器
	 */
	public function getDisposer(){
		return $this->disposer;
	}

	/*
	 * 检查路由参数是否匹配
	 */
	private function bind(){
		$urls=explode('?',(PHP_SAPI!='cli' ? $_SERVER['REQUEST_URI'] : (isset($GLOBALS['argv'][1])&&!empty($GLOBALS['argv'][1]) ? $GLOBALS['argv'][1]:'/')),2);
		$url=array_shift($urls);
		if(stripos($url, SYSTEM_ENTRY)===0){
			//将"/index.php/user/list"格式地址处理为"/user/list"
			$url=substr($url, strlen(SYSTEM_ENTRY));
		}
		//规范url地址必须以"/"开头，以非"/"结尾
		$url='/'.trim($url,'/');
		
		//使用路径参数匹配
		$handle=$this->matchHandle($url);
		if(is_null($handle) || is_string($handle)){
			//获取路由处理器，如：index/show@home
			if(is_string($handle)){
				$res=explode('@', $handle);
				$cas=explode('/', array_shift($res));
				define('ROUTE_A',array_pop($cas));
				!empty($cas) && define('ROUTE_C',ucfirst(array_pop($cas)));
				!empty($res) && define('ROUTE_M',ucfirst(strtolower(array_pop($res))));
			}
			//设置默认路由常量，同时使用传统路由方式匹配模式
			if($url==ROOT_URL || defined('USE_DEFUALT_HANDLE') && USE_DEFUALT_HANDLE){
				!defined('ROUTE_C') && define('ROUTE_C', defined('BIND_CONTROLLER') ? BIND_CONTROLLER : ucfirst(I('c', C('default_c'))));
				!defined('ROUTE_A') && define('ROUTE_A', defined('BIND_ACTION') ? BIND_ACTION : I('a', C('default_a')));
			}
		}else if(is_callable($handle)){
			$this->setDisposer($handle);
		}
		
		//绑定模块
		!defined('ROUTE_M') && define('ROUTE_M', defined('BIND_MODULE') ? BIND_MODULE : ucfirst(strtolower(I('m',env('bind_module','Home')))));
	}

	/*
	 * 查找路由处理器
	 * @param string $url URL地址
	 * @return string|null
	 */
	private function matchHandle($url){
		$configs=C('route.*',[]);
		$default=isset($configs['default']) ? $configs['default'] : [];
		unset($configs['default']);
		//通过主机名获取路由配置
		$routes = $this->getRoutesByHost(SITE_HOST, $configs,$default);
		unset($configs,$default);
		
		//对URL访问路径进行路由匹配
		foreach($routes as $key=> $value){
			if(is_array($value)){
				foreach($value as $k=> $v){
					if(!is_null($result=$this->getHandle($url,$k,$v))){
						self::setMiddleware(explode('&', $key));
						return $result;
					}
				}
			}elseif(!is_null($result=$this->getHandle($url,$key,$value))){
				return $result;
			}
		}
		return null;
	}
	
	/*
	 * 获取路由
	 * @param string $host 域名
	 * @param array &$configs 所有路由配置
	 * @param array &$default 默认路由
	 * @return 匹配的路由配置
	 */
	private function getRoutesByHost($host,&$configs,&$default=[]){
		$routes=[];
		//从总配置文件和分布文件读取
		if(isset($configs[$host])){
			$routes=$configs[$host];
		}
		$file=STORAGE_PATH . 'Routes' . DS .$host.'.php';
		if(is_file($file) && is_array($confs=include($file))){
			$routes=array_merge($routes,$confs);
		}
		
		//尝试从泛解析域名读取，例如：*.steeze.cn
		if(empty($routes) && strpos($host, '.')){
			$domain='*'.strstr($host,'.');
			if(isset($configs[$domain])){
				$routes=$configs[$domain];
			}
			$file=STORAGE_PATH . 'Routes' . DS .$domain.'.php';
			if(is_file($file) && is_array($confs=include($file))){
				$routes=array_merge($routes,$confs);
			}
		}
		
		//从绑定模块的路由中获取，如：home@*.h928.com
		if(empty($routes)){
			//从全局配置中查找
			$domains=array_keys($configs);
			$bindModule='';
			foreach($domains as $domain){
				$cRoutes=explode('@',$domain);
				$cDomain=array_shift($cRoutes);
				if(
					$host == $cDomain ||
					(strpos($cDomain,'*.')===0 && $cDomain=='*'.strstr($host,'.'))
				){
					$routes=$configs[$domain];
					if(!empty($cRoutes)){
						$bindModule=array_shift($cRoutes);
					}
					break;
				}
			}
			
			//如果在全局中未找到，则从路由配置目录中查找
			if(empty($routes)){
				$path=STORAGE_PATH.'Routes'.DS;
				if(is_dir($path) && ($handle = opendir($path))){
					while (false !== ($file = readdir($handle))) {
						if($file != '.' && $file != '..' && is_file($path.$file)){
							$domain=basename($file,'.php');
							$cRoutes=explode('@',$domain);
							$cDomain=array_shift($cRoutes);
							if(
								$host == $cDomain ||
								(strpos($cDomain,'*.')===0 && $cDomain=='*'.strstr($host,'.'))
							){
								$routes=include($path.$file);
								if(!empty($cRoutes)){
									$bindModule=array_shift($cRoutes);
								}
								break;
							}
						}
					}
					closedir($handle);
				}
			}
			if(!empty($bindModule)){
				define('BIND_MODULE', ucfirst(strtolower($bindModule)));
			}
		}
		return !empty($routes) ? $routes : $default;
	}
	
	/*
	 * 获取路由处理器
	 * @param string $url URL参数
	 * @param string $route 路由
	 * @param string|function $handle 处理器 
	 * @return string|null
	 */
	private function getHandle($url,$route,$handle){
		$route='/'.trim($route,'/');
		$middlewares=[];
		if(is_string($handle)){
			$handles=explode('>', $handle,2);
			$handle=trim(array_pop($handles));
			if(!empty($handles)){
				$middlewares=array_merge($middlewares,explode('&', array_pop($handles)));
			}
		}
		
		if(substr_count($route, '/')==substr_count($url, '/')){
			$routes=explode(':', $route, 2);
			$route=trim(array_pop($routes));
			$method=count($routes) ? strtoupper(array_pop($routes)) : 'GET';
			if($method!=REQUEST_METHOD){
				return null;
			}
			if(!strcasecmp($route, $url)){
				$this->setMiddleware($middlewares);
				//如果url完全匹配（不区分大小写），直接返回
				return $handle;
			}else{
				//否则进行变量类型查找
				$kArrs=explode('/',$route);
				$urlArrs=explode('/',$url);
				
				$isVar=is_string($handle) && strpos($handle, '}')!==false;
				$mCount=count($kArrs);
				foreach($kArrs as $ki=> $kv){
					if(strcasecmp($kv, $urlArrs[$ki])){
						if(strpos($kv, '{')!==false){ //变量匹配检查
							$kvnts=explode('|',trim($kv,'{} '));
							$kvName=$kvnts[0];
							$kvType=isset($kvnts[1]) ? $kvnts[1] : 's';
							if($kvType=='d'){
								if(is_numeric($urlArrs[$ki])){
									$this->params[$kvName]=$urlArrs[$ki];
								}else{
									break;
								}
							}else{
								$this->params[$kvName]=$urlArrs[$ki];
							}
							if($isVar){
								$handle=str_replace('{'.$kvName.'}',$urlArrs[$ki],$handle);
							}
						}else{
							break;
						}
					}
					$mCount--;
				}
				if(!$mCount){
					$this->setMiddleware($middlewares);
					return $handle;
				}
			}
		}
		return null;
	}
	
	/*
	 * 设置中间件
	 * @param string $name 中间名称
	 * @param array|string $excepts 排除的方法名称
	 */
	public static function setMiddleware($name,$excepts=[]){
		if(is_array($name)){
			foreach($name as $n){
				self::setMiddleware($n,$excepts);
			}
		}else{
			$name=trim($name);
			$middlewares=C('middleware.*',[]);
			if(isset($middlewares[$name])){
				if(!isset(self::$middlewares[$name])){
					self::$middlewares[$name]=(array)$excepts;
				}else{
					self::$middlewares[$name]=array_unique(array_merge(self::$middlewares[$name],(array)$excepts));
				}
			}
		}
	}
	
	/*
	 * 获取中间件（或根据方法名称返回可用中间件）
	 * @param string $name 方法名称
	 * @return array 中间数组
	 * 说明：如果提供方法名称，则根据方法名称返回可用中间件
	 */
	public static function getMiddleware($name=null){
		$classes=[];
		$middlewares=C('middleware.*',[]);
		foreach(self::$middlewares as $key => $values){
			if(!is_null($name)){
				if(!in_array($name, $values)){
					$classes[]=$middlewares[$key];
				}
			}else{
				$classes[]=$middlewares[$key];
			}
		}
		return $classes;
	}
	
}
