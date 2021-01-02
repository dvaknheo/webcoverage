<?php
use SebastianBergmann\CodeCoverage\CodeCoverage;

class WebCoverage
{
    public $options=[
		'path'=>null,
		'path_src'=>'src',
		'path_dump'=>'test_coveragedumps',
		'path_report'=>'test_reports',
        
        'reg_shutdown'=>true,
        'auto_report'=>true,
        'tests' =>[
            'a'=>[
                '/',
            ],
            'b'=>[
                '/test/done',
            ],
        ],
    ];
	public $is_inited =true;
    
    public $coverage;
    
    protected $name;
    protected $url;
    protected $post;
    protected $hash;
    
    ////[[[[
    public static function G($object=null)
    {
        if (defined('__SINGLETONEX_REPALACER')) {
            $callback = __SINGLETONEX_REPALACER;
            return ($callback)(static::class, $object);
        }
        static $_instance;
        $_instance=$object?:($_instance??new static);
        return $_instance;
    }
    public static function RunQuickly(array $options = [], callable $after_init = null)
    {
        $instance = static::G()->init($options);
        if ($after_init) {
            ($after_init)();
        }
        return $instance->run();
    }
    ////]]]]
    public function init(array $options, ?object $context = null)
    {
        $this->options = array_intersect_key(array_replace_recursive($this->options, $options) ?? [], $this->options);
		
		$this->options['path'] = $this->options['path']?? realpath(__DIR__ .'/..').'/';
		$this->options['path_src'] = $this->getComponenetPathByKey('path_src');
		$this->options['path_dump'] = $this->getComponenetPathByKey('path_dump');
        $this->options['path_report'] = $this->getComponenetPathByKey('path_report');
		
		if(!is_dir($this->options['path_dump'])){
			mkdir($this->options['path_dump']);
		}
		if(!is_dir($this->options['path_report'])){
			mkdir($this->options['path_report']);
		}
		$this->is_inited = true;
        return $this;
    }
    protected function getComponenetPathByKey($path_key)
    {
        if (substr($this->options[$path_key], 0, 1) === '/') {
            return rtrim($this->options[$path_key], '/').'/';
        } else {
            return $this->options['path'].rtrim($this->options[$path_key], '/').'/';
        }
    }
    public function isInited():bool
    {
        return $this->is_inited;
    }
    protected function checkPermission()
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $id=$_SEREVER['HTTP_WEBCOVERAGE_ID']??null;
        if(empty($id)){
            $this->hash = $this->createHashFile();
        }else{
            //TODO 判断合法性
            $this->hash = trim($id);
        }
        
        return true;
    }
    //// 入口1
    public function run()
    {
    
        if(!$this->isInited()){
            $this->init([]);
        }

        if(!$this->checkPermission()){
            return false;
        }
        if($this->options['reg_shutdown']){
            register_shutdown_function([static::class,'OnShutDown']);
        }
        $path = $this->options['path_src'];
        $this->coverage = new CodeCoverage();  // 这里要不要和 用 newCodever 助手函数？
        $this->coverage->filter()->addDirectoryToWhitelist($path);
        /*
        $coverage->setTests([
          'T' =>[
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        */
        $this->coverage->start($this->getRequestName());
        return true;
    }
    public static function OnShutDown()
    {
        return static::G()->_OnShutDown();
    }
    public function _OnShutDown()
    {
        $this->coverage->stop();
        $data = $this->coverage->getData(true);
        $data = $this->postpareData($data);
        $dir = $this->options['path_dump'];
        
        $filename= DATE('Ymd-His').md5($this->getRequestName());
        // 文件名要时间优先。因为旧文件要覆盖新文件。
        
        // 我们把 request hash 一下
        
        // 保存文件还是放一起，然后挨个读取过滤？ 虽然总 hash 不同，但是用到的分文件 hash 还是相同的。
        // 我们不仅仅是要保存资源数据，还要保存hash 信息
        //$data =
        file_put_contents($dir.$filename.'.json',json_encode($data,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK)); // 把总 hash 信息，文件 md5 信息都找出来。
    }
    //*/
    ///////////////////////////////////////////////////////////
    
    public function report()
    {
        $coverage = $this->newCodeCoverage();
        $files = $this->scanFiles($this->options['path_dump'], '.json');
        
        ////[[[[
        foreach ($files as $file) {
            $data = file_get_contents($file);
            $data = json_decode($data,true);
            if(!$this->match($data)){
                //rename($file,$file.'.old');
                continue;
            }
            $object = $this->newCodeCoverage();
            
            $object->setData($data['data']);
            
            //TODO 最好还是自己merge ，否则一个测试上百条请求怎么办？
            $coverage->merge($object);
        }
        ////]]]]
        $this->process($coverage,$this->options['path_report']);
    }
    protected function match($data)
    {
        //从这些文件的文件名，索引 hash 文件的 md5 。
        // 和当前 hash 的 md5 比较。
        //$data['hash']
        
        return true;
    }
    protected function process($coverage,$path)
    {
        $writer = new \SebastianBergmann\CodeCoverage\Report\Html\Facade;
        $writer->process($coverage, $this->options['path_report']);
        
        $report = $coverage->getReport();
        $lines_tested = $report->getNumExecutedLines();
        $lines_total = $report->getNumExecutableLines();
        $lines_percent = sprintf('%0.2f%%',$lines_tested/$lines_total *100);
        return [
            'lines_tested'=>$lines_tested,
            'lines_total'=>$lines_total,
            'lines_percent'=>$lines_percent,
        ];
    }
    public function cover()
    {
        $hash = $this->createHashFile();
        
        foreach($this->options['tests'] as $name => $test){
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['WebCoverage-Id: '.$hash]);
            foreach($test as $name =>$path){
                //TODO 如果碰到特殊指令时候还要调整
                @list($url,$post) = explode(' ',$path);
                
                $url="http://127.0.0.1:8080".$path; //TODO 这里要可调。
                if($post){
                    curl_setopt($ch, CURLOPT_POST,true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS,$post);
                }
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                
                $ret = curl_exec($ch);
            }
            curl_close($ch);
        }
        if($this->options['auto_report']){
            $this->report();
        }
        
        var_dump(DATE(DATE_ATOM));
        /*
        if (is_array($url)) {
            list($base_url, $real_host) = $url;
            $url = $base_url;
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            $c = $host.':'.$port.':'.$real_host;
            curl_setopt($ch, CURLOPT_CONNECT_TO, [$c]);
        }
        */
    }
    protected function scanFiles($source,$ext)
    {
        $directory = new \RecursiveDirectoryIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $ret = new \RegexIterator ($iterator, '/'.preg_quote($ext).'$/',\RegexIterator::MATCH);
        $ret = \array_values(\iterator_to_array($ret));
        ksort($ret);
        
        return $ret;
    }
    protected function createHashFile()
    {
        $files = $this->scanFiles($this->options['path_src'],'.php');
        $data=[];
        foreach($files as $file){
            $md5 = md5(file_get_contents($file));
            $data[$md5]=$file;
        }
        $str=json_encode($data,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        $id = md5($str);
        file_put_contents($this->options['path_dump'].$id.'.hash',$str);
        return $id;
    }
    protected function getRequestName()
    {
        if ($_POST ?? false) {
            return $_SERVER['REQUEST_URI'].' '. http_build_query($_POST);
        }
        return $_SERVER['REQUEST_URI'];
    }
    // helper
    protected function newCodeCoverage()
    {
        $coverage = new CodeCoverage();
        $coverage->filter()->addDirectoryToWhitelist($this->options['path_src']);
        $coverage->setTests([
          'T' =>[
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        return $coverage;
    }
    
    protected function postpareData($data)
    {
        $ret=[
            'hash' => $this->hash,
            'request' => $this->getRequestName(),
            'date'=>DATE(DATE_ATOM),
        ];
        $ret['data']=$data;
        
        return $ret;
    }
}