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
        
        // 这里先做测试了
        'tests' =>[
            'a'=>[
                '/',
            ],
            'b'=>[
                '/test/done',
            ],
        ],
    ];
	public $is_inited = false;
    
    public $coverage;  //
    
    protected $name;
    protected $url;
    protected $post;
    protected $hash;
    
    ////[[[[
    protected static $_instances = [];
    public static function G($object = null)
    {
        if (defined('__SINGLETONEX_REPALACER')) {
            $callback = __SINGLETONEX_REPALACER;
            return ($callback)(static::class, $object);
        }
        //fwrite(STDOUT,"SINGLETON ". static::class ."\n");
        if ($object) {
            self::$_instances[static::class] = $object;
            return $object;
        }
        $me = self::$_instances[static::class] ?? null;
        if (null === $me) {
            $me = new static();
            self::$_instances[static::class] = $me;
        }
        
        return $me;
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
    public function isInited():bool
    {
        return $this->is_inited;
    }
    public function init(array $options, ?object $context = null)
    {
        $this->options = array_intersect_key(array_replace_recursive($this->options, $options) ?? [], $this->options);
		
		$this->options['path'] = $this->options['path']?? realpath(__DIR__ .'/..').'/';
		$this->options['path_src'] = $this->getComponenetPathByKey('path_src');
		$this->options['path_dump'] = $this->getComponenetPathByKey('path_dump');
        $this->options['path_report'] = $this->getComponenetPathByKey('path_report');
		
        
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

    /////////////////////////////
    // 这里最折腾的一点
    protected function checkPermission()
    {
        $this->hash=md5(microtime());
        return true;
    }
    protected function getRequestName()
    {
        if ($_POST ?? false) {
            return $_SERVER['REQUEST_URI'].' '. http_build_query($_POST);
        }
        return $_SERVER['REQUEST_URI'];
    }
    //// 入口1
    public function run()
    {
        if(!$this->checkPermission()){
            return false;
        }
        if($this->options['reg_shutdown']){
            register_shutdown_function([static::class,'OnShutDown']);
        }
        $this->coverage = $this->newCodeCoverage();
        $this->coverage->start($this->getRequestName());
        return true;
    }
    
    public static function OnShutDown()
    {
        return static::G()->_OnShutDown();
    }
    public function _OnShutDown()
    {
		if(!is_dir($this->options['path_dump'])){
			mkdir($this->options['path_dump']);
		}
        
        $this->coverage->stop();
        
        $dir = $this->options['path_dump'];
        
        $data = $this->coverage->getData(true);
        $this->saveMetaData($data);
        $this->saveContent($data);
    }
    protected function saveMetaData($input)
    {
        $ret=[
            'base_path'=>$this->options['path_src'],
            'request'=> $this->getRequestName(),
            'name' =>$this->getRequestName(),
            'date'=>DATE(DATE_ATOM),
            'files'=>[],
        ];
        foreach($input as $fullfile=>$v){
            $file=substr($fullfile,strlen($this->options['path_src']));
            $t[$file]=filemtime($fullfile);
        }
        $ret['files'] = $t;
        
        $file = $this->options['path_dump'].$_SERVER['REQUEST_TIME_FLOAT'].'.req';
        file_put_contents($file,json_encode($ret,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    protected function saveContent($data)
    {
        $file = $this->options['path_dump'].$_SERVER['REQUEST_TIME_FLOAT'].'.req.data';
        file_put_contents($file,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK));
    }
    //*/
    // 以上是服务端。
    
    ///////////////////////////////////////////////////////////
    public function clean()
    {
        $mtimes = $this->getSourceMtimes();
        $coverage = $this->newCodeCoverage();
        $files = $this->scanFiles($this->options['path_dump'], '.req');
        foreach ($files as $file) {
            $meta = json_decode(file_get_contents($file),true);
            if(!$this->match($mtimes,$meta)){
                // skip ile;
                continue;
            }
            
        }
    }
    // 脚本生成报告
    public function report()
    {
		if(!is_dir($this->options['path_report'])){
			@mkdir($this->options['path_report']);
		}
        
        $mtimes = $this->getSourceMtimes();
        $coverage = $this->newCodeCoverage();
        
        $files = $this->scanFiles($this->options['path_dump'], '.req');
        ksort($files); // 按音序排。
        foreach ($files as $file) {
            $meta = json_decode(file_get_contents($file),true);
            if(!$this->match($mtimes,$meta)){
                // skip;
                continue;
            }
            $data =json_decode(file_get_contents($file.'.data'),true);
            $object = $this->newCodeCoverage();
            $object->setData($data);
            //TODO 最好还是自己merge ，否则一个测试上百条请求怎么办？
            $coverage->merge($object);
        }
        ////]]]]
        $ret = $this->process($coverage,$this->options['path_report']);
        var_dump($ret);
    }
    
    // 看是否符合条件
    protected function match($mtimes,$meta)
    {
        $data = array_diff_assoc($meta['files'],$mtimes);
        return empty($data) ? true : false;
    }
    protected function process($coverage,$path)
    {
        $writer = new \SebastianBergmann\CodeCoverage\Report\Html\Facade;
        
        // 为什么会有个 Notice ?
        @$writer->process($coverage, $this->options['path_report']); 
        
        $report = $coverage->getReport();
        $lines_tested = $report->getNumExecutedLines();
        $lines_total = $report->getNumExecutableLines();
        $lines_percent = sprintf('%0.2f%%',$lines_tested/($lines_total ?:1) *100);
        return [
            'lines_tested'=>$lines_tested,
            'lines_total'=>$lines_total,
            'lines_percent'=>$lines_percent,
        ];
    }
    public function cover()
    {
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
    }
    protected function getSourceMtimes()
    {
        $input = $this->scanFiles($this->options['path_src'],'.php');
        $data=[];
        foreach($input as $fullfile){
            $file=substr($fullfile,strlen($this->options['path_src']));
            $data[$file]=filemtime($fullfile);
        }
        return $data;
    }    
    // 扫描文件 ,改用 glob 更好点
    protected function scanFiles($source,$ext)
    {
        $directory = new \RecursiveDirectoryIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $ret = new \RegexIterator ($iterator, '/'.preg_quote($ext).'$/',\RegexIterator::MATCH);
        $ret = \array_values(\iterator_to_array($ret));
        ksort($ret);
        
        return $ret;
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
    
    // 补充数据
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