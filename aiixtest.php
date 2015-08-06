<?php

/*
 * (C) 2015, AII (Alexey Ilyin).
 */
class AIIXTestContext
{
    public function setFilepath ($fname) {
        if (!$this->filepath = realpath($fname)) {
	   echo "\n\n***** ERROR: File $this->cwd/$fname not found and so skipped\n\n";
           return false;
	}
        $this->fname = $fname;
        $this->code  = file($this->filepath);
        return true;
    }

    public function printCode () {
        if (is_array($this->code)) foreach ($this->code as $lnum => $ltxt) {
            printf("%4d| %s", $lnum+1, $ltxt);
        }
    }

    public function exec () {
        extract(AIIXTest::$SHARED, EXTR_PREFIX_INVALID|EXTR_REFS, 'var_');
        try {
            $this->return = include $this->filepath;
        }
        catch (Exception $e) {
            echo "\n$e\n";
            $this->return = $e;
            unset($e);
        }
        $this->vars = get_defined_vars();
    }

    public $what;

    public function replace_previous () {
        $this->what = 'replace';
    }

    public function delete_previous () {
        $this->what = 'delete';
    }
}

class AIIXTest
{
    protected $init = array(), $test = array(), $path, $cwd;

    protected static $inst;

    public static $SHARED = array();

    protected function __construct () {
        $this->prev_errhandler = set_error_handler(array($this,'errhandler'));
        //$this->prev_exphandler = set_exception_handler(array($this,'exphandler'));
        register_shutdown_function(array($this,'check_for_fatal'));
        $this->parseCmdLine();
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

    protected static function filename ($stage, $fname) {
        $file = pathinfo($fname);
        $file['dirname'] !== '.'
        or  $file['dirname'] = $stage;
        empty($file['extension'])
        and $file['extension'] = 'php';
        return "$file[dirname]/$file[filename].$file[extension]";
    }

    protected function addFile ($stage, $fname) {
        $fname = self::filename ($stage, $fname);
        file_exists($fname)
        or $this->abort("file $fname does not exists");
        isset($this->{$stage}[$fname])
        or $this->{$stage}[$fname] = $this->prepare($fname);
    }

    protected function getContext ($stage, $fname) {
        $fname = self::filename ($stage, $fname);
        if (isset($this->{$stage}[$fname])) {
            return $this->{$stage}[$fname];
        }
        echo ("\n***** file $fname is not loaded\n");
        return false;
    }

    protected function prepare ($fname) {
        $context = new AIIXTestContext;
        if (!$context->setFilepath($fname)) return false;
        $this->evalPragmas('prepare', $context);
        return $context;
    }

    protected function evalPragmas ($phase, AIIXTestContext $context) {
        foreach ($context->code as $lnum => $line) {
            $lnum++;
            $parts = explode('//!', trim($line));
            if (count($parts) == 2) {
                $code = $parts[1];
            }
            else if (count($parts) > 2) {
                $this->abort("Bad-formed pragma(s) in '$phase' of $context->fname:\n$lnum| {$line}");
            }
            else continue;
            if ($this->evaluate('return $this->'.$phase.'_'.trim($code).';') === false) {
                return false;
            }
        }
    }

    private function evaluate ($code) {
        return eval($code);
    }

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

    protected function run ($stage) {
        if (empty($this->{$stage})) {
            foreach(glob("$stage/*.php") as $fname) {
                $this->addFile($stage, $fname);
            }
        }
        foreach ($this->{$stage} as $context) {
            if (!$context) continue; // skipped
            $this->{"print_$stage"}($context);
        }
    }

    protected function print_init (AIIXTestContext $init) {
        printf("----%'--72s----\n", "[ $init->fname ]");
        $temp = $this->evalPragmas('return', $init);
        unset($init->code);
        if ($temp !== false) {
            $init->exec();
            self::$SHARED = array_merge(self::$SHARED, $init->vars);
            return true;
        }
        return false;
    }

    protected function print_test (AIIXTestContext $test) {
        printf("\n#===%'=-72s===#\n", "[ $test->fname ]");
        $test->printCode();
        $line = str_repeat('-', 80);
        if ($this->evalPragmas('result', $test) === false)
            echo "\nSkipped because of dependencies\n";
        else {
            echo "\n",$line,"\n";
            $test->exec();
            echo "\n",$line,"\n";
            echo "$test->fname returned: ";
            var_dump($test->return);
            echo $line,"\n";
            echo "Vars after $test->fname:\n";
            $this->printVars($test->vars);
            $test->result = $this->test($test);
            if ($test->result !== true) echo "\n$test->result\n";
        }
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

    public function prepare_require_init ($fname) {
        $this->addFile('init', $fname);
    }

    public function prepare_require_test ($fname) {
        $this->addFile('test', $fname);
    }

    public function return_require_init ($fname) {
        $init = $this->getContext('init', $fname);
        return !empty($init->return);
    }

    public function result_require_test ($fname) {
        $test = $this->getContext('test', $fname);
        return !empty($test->result);
    }

    function abort ($message) {
        exit ("\nAborted: $message\n");
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

}

//---=====[ TEST ]=====---//

AIIXTest::create()->start();




