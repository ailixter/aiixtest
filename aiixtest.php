<?php

/*
 * (C) 2015, AII (Alexey Ilyin).
 */
class AIIXTestContext
{
    public $what;

    public function replace_previous () {
        $this->what = 'replace';
    }

    public function delete_previous () {
        $this->what = 'delete';
    }
}

class AIIXTestException extends Exception {}

class AIIXTest
{
    protected $result = array(), $path, $cwd;

    protected function __construct () {
        $this->prev_errhandler = set_error_handler(array($this,'errhandler'));
        //$this->prev_exphandler = set_exception_handler(array($this,'exphandler'));
        register_shutdown_function(array($this,'check_for_fatal'));
        $this->parseCmdLine();
    }

    protected $prev_errhandler = 'undefined';//,$prev_exphandler = 'undefined';

//    function exphandler ($exception) {
//        $this->errhandler(null, (string)$exception);
//    }

    function errhandler ($errno, $errstr, $errfile=null, $errline=null, $errcontext=null) {
        $msg = $this->errmessage($errno, $errstr, $errfile, $errline, $errcontext);
        print("\n***** $msg\n");
        return true;
    }

    function check_for_fatal () {
        echo str_repeat('-', 80), "\n";
        if ($error = error_get_last()) {
            $this->errhandler($error['type'], $error['message'], $error['file'], $error['line']);
            exit("ABORTED\n");
        }
        exit("THE END\n");
    }

    function errmessage ($errno, $errstr, $errfile = null, $errline = null, $errcontext = null) {
        isset($errno)   and $errstr  = "($errno): $errstr";
        isset($errfile) and $errstr .= " in ".str_replace($this->cwd, '', $errfile);
        isset($errline) and $errstr .= " on $errline";
        return $errstr;
    }

    protected static $inst;

    protected static $SHARED = array();

    /**
     *
     * @param type $path
     * @param type $events
     * @return AIIXTest
     */
    public static function create () {
        error_reporting(0);
        ini_set('display_errors', false);
        ini_set('display_startup_errors', false);
        return self::$inst = new AIIXTest();
    }

    const CACHE = '.cache';

    public function start () {
        is_dir(self::CACHE) or mkdir(self::CACHE);
        echo 'PHP ',PHP_VERSION,"\n";
        $this->run('init');
        echo "\nInitial Vars: \n";
        $this->printVars(self::$SHARED);
        $this->run('test');
    }

    function parseCmdLine () {
        $this->cwd = getcwd();
        global $argv;
        $stage = false;
        while ($arg = next($argv)) {
            switch ($arg) {
                case 'init': case 'test':
                    $stage = $arg;
                    $arg = next($argv);
                    break;
            }
            if (!$stage) {
                $this->path
                    and $this->abort("on $arg - path $this->path is already set");
                $this->path = realpath($arg)
                    or $this->abort("path $arg does not exist");
                chdir($this->path)
                    or $this->abort("cannot chdir($this->path)");
                $this->cwd = getcwd();
            }
            else {
                $this->addFile($stage, $arg);
            }
        }
    }

    protected function addFile ($stage, $name) {
        file_exists($fname = "$stage/$name.php")
            or $fname = realpath($name)
            or $this->abort("file $name does not exists");
        $this->result[$stage][$fname] = false;//TODO $this->result[$stage][] = $this->prepare($fname)
    }

    protected function run ($stage) {
        empty($this->result[$stage])
        and $this->result[$stage] = array_fill_keys(glob("$stage/*.php", GLOB_MARK), false);
        foreach ($this->result[$stage] as $fname => &$result) {
            if ($result) continue; // already run
            $this->run_code($stage, $fname);
        }
    }

    protected function run_code ($stage, $fname) {
        $test = new AIIXTestContext;
        $test->fname = $fname;
        if (!$test->filepath = realpath($fname)) {
	   exit("\n\n***** FATAL ERROR: File $this->cwd/$fname not found\n\n");
	}
        $test->code = file($test->filepath);
        $this->result[$stage][$fname] = $this->{"print_$stage"}($test);
    }

    private static function exec (AIIXTestContext $AIIXTest) {
        extract(self::$SHARED, EXTR_PREFIX_INVALID|EXTR_REFS, 'var_');
        try {
            $AIIXTest->return = include $AIIXTest->filepath;
        }
        catch (Exception $e) {
            echo "\n$e\n";
            $AIIXTest->return = $e;
            unset($e);
        }
        $AIIXTest->vars = get_defined_vars();
        unset($AIIXTest->vars['AIIXTest']);
    }

    protected function print_init (AIIXTestContext $test) {
        printf("----%'--72s----\n", "[ $test->fname ]");
        self::exec($test);
        self::$SHARED = array_merge(self::$SHARED, $test->vars);
        return true;
    }

    protected function print_test (AIIXTestContext $test) {
        printf("\n#===%'=-72s===#\n", "[ $test->fname ]");
        foreach ($test->code as $lnum => $ltxt) {
            printf("%4d| %s", $lnum+1, $ltxt);
        }
        $line = str_repeat('-', 80);
        echo "\n",$line,"\n";
        self::exec($test);
        echo "\n",$line,"\n";
        $test->result = $this->test($test);
        echo "$test->fname returned: ";
        var_dump($test->return);
        echo $line,"\n";
        echo "Vars after $test->fname:\n";
        $this->printVars($test->vars);
        if ($test->result !== true) echo "\n$test->result\n";
        return $test;
    }

    protected function printVars (array $vars) {
        foreach ($vars as $key => $value) {
            echo "\n";
            isset(self::$SHARED[$key]) and print('shared ');
            echo "$$key = ";
            //var_dump($value);
            var_export($value); echo ";\n";
        }
    }

    protected function test (AIIXTestContext $test) {
        $filename = self::CACHE.'/'.strtr($test->fname, '/:\\', '---').'.txt';
        $current  = serialize($test->return);
        if (!file_exists($filename)) {
            file_put_contents($filename, $current);
            return true;
        }
        $previous = file_get_contents($filename);
        if ($current === $previous) return true;
        switch ($test->what) {
            case 'replace': file_put_contents($filename, $current); break;
            case 'delete':  unlink($filename); break;
        }
    }

    public static function require_init ($key) {
        self::$inst->run_code('init', "init/$key.php");
    }

    public static function require_test ($key) {
        self::$inst->run_code('test', "test/$key.php");
    }

    function abort ($message) {
        exit ("\nAborted: $message\n");
    }
}

//---=====[ TEST ]=====---//

AIIXTest::create()->start();




