<?php
namespace Library;

class Response{
	private $response=null; //外部Response对象
	private $isHeaderSend=false; //是否已经发送头部信息
	private $isEnd=false; //是否已经结束发送
    
	/**
	 * 设置外部响应对象
	 * @param Response $response 外部响应对象
	 */
	public function setResponse($response){
		//对Swoole的支持
		if(!empty($response) && is_a($response,'Swoole\\Http\\Response')){
			$this->response=$response;
		}
        $this->isHeaderSend=false;
        $this->setIsEnd(false);
	}
    
    /**
     * 设置是否请求结束
     *
     * @param boolean $status 是否请求结束，默认：true
     */
    public function setIsEnd($status=true){
        $this->isEnd=$status;
    }
	
	/**
	 * 判断是否成功发送请求头部信息
	 * @return bool
	 */
	public function hasSendHeader(){
		return $this->isHeaderSend;
	}
	
	/**
	 * 设置HTTP响应的Header信息
	 * @param string $key http头的键名
	 * @param string $value http头的键值
	 * @param bool   $hasSend 是否设置为已发送，默认为true
	 * @return bool | void
	 * 
	 * 说明：header设置必须在end方法之前，键名必须完全符合Http的约定，
	 * 		每个单词首字母大写，不得包含中文，下划线或者其他特殊字符
	 * 		header设置必须在end方法之前
	 */
	public function header($key, $value, $hasSend=true){
        if( !$this->isEnd ){
            $this->isHeaderSend=$hasSend;
            return !is_null($this->response) ? 
                        $this->response->header($key,$value,true) : 
                        header($key.':'.$value);
        }
	}
	
	/**
	 * 设置HTTP响应的Header信息
	 * @see http://php.net/manual/en/function.setcookie.php
	 * 
	 * 说明： cookie设置必须在end方法之前
	 */
	public function cookie($key, $value = '', $expire = 0 , $path = '/', $domain  = '', $secure = 0 , $httponly = 0){
		if( !$this->isEnd ){
            return !is_null($this->response) ?
                        $this->response->cookie($key,$value,$expire,$path,$domain,$secure,$httponly) :
                        setcookie($key,$value,$expire,$path,$domain,$secure,$httponly);
        }
	}
	
	/**
	 * 发送Http状态码
	 * @param int $code 状态码
	 * 
	 * 说明：$http_status_code必须为合法的HttpCode，如200， 502， 301, 404等，否则会报错
	 * 		必须在$response->end之前执行status
	 */
	public function status($code){
        if( !$this->isEnd ){
            return !is_null($this->response) ?
                $this->response->status($code) :
                http_response_code($code);
        }
	}
	
	/**
	 * 压缩级别设置
	 * @param int $level 压缩等级，范围是1-9，等级越高压缩后的尺寸越小，但CPU消耗更多。默认为1
	 * 
	 * 说明：启用Http GZIP压缩。压缩可以减小HTML内容的尺寸，有效节省网络带宽，提高响应时间。
	 * 		必须在write/end发送内容之前执行gzip，否则会抛出错误
	 */
	public function gzip($level = 0){
		!is_null($this->response) && $this->response->gzip($level);
	}
	
	/**
	 * 启用Http Chunk分段向浏览器发送相应内容
	 * @param string $data 要发送的数据内容，最大长度不得超过2M
	 * @param bool $isConsole 是否直接写入到控制台
	 * 
	 */
	public function write($data, $isConsole=false){
        if( !$this->isEnd && !is_null($data)){
            if(!$isConsole && !is_null($this->response)){
                $this->response->write(to_string($data));
            }else{
                echo to_string($data);
            }
        }
	}
	
	/**
	 * 发送文件到浏览器
	 * @param string $filename 要发送的文件名称
	 * @param int $offset 上传文件的偏移量，可以指定从文件的中间部分开始传输数据。此特性可用于支持断点续传
	 * @param int $length 发送数据的尺寸，默认为整个文件的尺寸
	 * 
	 * 说明：调用sendfile前不得使用write方法发送Http-Chunk
	 */
	public function sendfile($filename, $offset = 0, $length = 0){
        if( !$this->isEnd ){
            $ext=fileext($filename);
            $mimetype=C('mimetype.'.$ext,'application/octet-stream');
            $this->header('Content-Type', $mimetype);
            if(!is_null($this->response)){
                $this->response->sendfile($filename, $offset,$length);
            }else{
                readfile($filename);
            }
        }
	}
	
	/**
	 * 发送Http响应体，并结束请求处理
	 * @param string $data 字符串数据
	 * @param bool $isAsyn 是否使用异步输出，默认为false
	 * 
	 * 说明：只能调用一次，如果需要分多次向客户端发送数据，请使用write方法
	 */
	public function end($data=null,$isAsyn=0){
        if( !$this->isEnd ){
            !is_null($data) && $this->write($data);
            if(!is_null($this->response)){
                $this->response->end();
            }else{
                if($isAsyn){
                    function_exists('fastcgi_finish_request') &&
                        fastcgi_finish_request();
                }else if(env('PHP_SAPI')!='cli'){
                    exit(0);
                }
            }
            $this->setIsEnd(true);
        }
	}
    
    /**
     * 输出数据到客户端
     *
     * @param mixed $data 需要输出的数据
     * @param string $dataType 数据类型，如果为空从系统配置获取
     * @param string|bool $charset 数据编码，如果为false不输出编码，为空从系统配置获取
     */
    public function flush($data, $dataType='', $charset=''){
        if(!$this->hasSendHeader()){
			if(empty($dataType)){
				$type=is_array($data) || is_object($data) ? 'json' : 'html';
				$dataType=C('mimetype.'.$type, 'text/html');
			}
            if($charset!==false){
                $dataType=$dataType . '; charset=' . ($charset ?: C('charset', 'utf-8'));
            }
			$this->header('Content-Type', $dataType); // 网页字符编码
			$this->header('Cache-control', C('HTTP_CACHE_CONTROL', 'private')); // 页面缓存控制
			$this->header('X-Powered-By', 'steeze');
		}
        //结束输出
        $this->end($data);
    }
    
    /**
     * URL重定向
     *
     * @param string $url 重定向的URL地址
     * @param integer $time 重定向的等待时间（秒）
     * @param string $msg 重定向前的提示信息
     * @return void
     */
    public function redirect($url, $time=0, $msg=''){
        // 多行URL地址支持
        $url=str_replace(["\n","\r"], '', $url);
        if($time && empty($msg)){
            $msg=L('System will automatically jump to {0} after {1} seconds', [$url, $time]);
        }
        if(!$this->hasSendHeader()){
            if(0 === $time){
                $this->header('Location', $url);
                $this->status(302);
                $this->end();
            }else{
                $contentType=C('mimetype.html','text/html');
                $charset=C('charset', 'utf-8');
                $this->header('Content-Type',$contentType . '; charset=' . $charset); // 网页字符编码
                $this->header('Cache-control',C('HTTP_CACHE_CONTROL', 'private')); // 页面缓存控制
                $this->header('X-Powered-By','steeze');
                $this->header('refresh', $time . ';url=' . $url);
                $this->end($msg);
            }
        }else{
            $str='<meta http-equiv="Refresh" content="'.$time.';URL='.$url.'"/>';
            if($time != 0){
                $str.=$msg;
            }
            $this->end($str);
        }
    }
	
}
