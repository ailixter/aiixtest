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
            $return = include $this->filepath;
        }
        catch (Exception $e) {
            echo "\n$e\n";
            $return = $e;
            unset($e);
        }
        $this->return = $return instanceof AIIXTestResult ?
                        $return : new AIIXTestResult($return);
        unset($return);
        $this->vars = get_defined_vars();
    }

    public function test () {
        return $this->result = $this->return->check($this,
            AIIXTest::CACHE.'/'.strtr($this->fname, '/:\\', '---').'.txt');
    }

    /*protected function call ($name) {
        if (method_exists($this, $name)) {
            return call_user_func_array(array($this,$name), array_slice(func_get_args(), 1));
        }
        echo "\n\n***** Warning: method $name is not supported and so that skipped\n\n";
    }*/
}

class AIIXTestResult
{
    public $result;

    public function __construct ($result) {
        $this->result = $result;
    }

    public function check (AIIXTestContext $test, $filename) {
        $current = serialize($this->result);
        if (!file_exists($filename)) {
            file_put_contents($filename, $current);
            return true;
        }
        $previous = file_get_contents($filename);
        if ($current === $previous) return true;
        $this->expected = unserialize($previous);
        return false;
    }

    public function show ($passed) {
        return !$passed;
    }
}

class AIIXTestResultIsTrue extends AIIXTestResult
{
    public $expected = true;
    public function check (AIIXTestContext $test, $filename) {
        return !array_filter($this->result, function ($result) {
            if (is_bool($result)) return $result !== true;
            echo("***** is_true(): got ".gettype($result).", not boolean\n");//TODO
            return true;
        });
    }
}

class AIIXTestResultReplacePrev extends AIIXTestResult
{
    public function check (AIIXTestContext $test, $filename) {
        file_put_contents($filename, serialize($this->result));
        return true;
    }
}

class AIIXTestResultDeletePrev extends AIIXTestResult
{
    public function check (AIIXTestContext $test, $filename) {
        unlink($filename);
        return true;
    }
}

class AIIXTest
{
    public static function is_true () {
        return new AIIXTestResultIsTrue(func_get_args());
    }

    public static function replace_previous () {
        return new AIIXTestResultReplacePrev(func_get_args());
    }

    public static function delete_previous () {
        return new AIIXTestResultDeletePrev(func_get_args());
    }

    protected $init = array(), $test = array(), $path, $cwd;

    protected static $inst;

    public static $SHARED = array();

    protected function __construct () {
        assert_options(ASSERT_ACTIVE, 1);
        assert_options(ASSERT_WARNING, 0);
        assert_options(ASSERT_QUIET_EVAL, 1);
        $this->prev_assert_bail = assert_options(ASSERT_BAIL, 0);
        $this->prev_asrhandler  = assert_options(ASSERT_CALLBACK, array($this,'asrhandler'));
        $this->prev_errhandler  = set_error_handler(array($this,'errhandler'));
        //$this->prev_exphandler = set_exception_handler(array($this,'exphandler'));
        register_shutdown_function(array($this,'check_for_fatal'));
        $this->parseCmdLine();
    }

    protected function parseCmdLine () {
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
        $line = str_repeat('=', 78);
        //printf("\n#===%'=-72s===#\n", "[ $test->fname ]");
        echo "\n\no",$line,"o\n";
        printf("|   %' -72s   |", $test->fname);
        echo "\no",$line,"o\n";
        $test->printCode();
        $line = str_repeat('-', 80);
        if ($this->evalPragmas('result', $test) === false)
            echo "\nSkipped because of dependencies\n";
        else {
            echo "\n",$line,"\n";
            $test->exec();
            echo "\n",$line,"\n";
            $passed = $test->test();
            if ($test->return->show($passed)) {
                echo "RETURNED: ";
                var_dump($test->return->result);
            }
            if (!$passed) {
                echo "\nEXPECTED: ";
                var_dump($test->return->expected);
                echo $line,"\n";
            }
            echo "Vars after:\n";
            $this->printVars($test->vars, true);
        }
        return $test;
    }

    protected function printVars (array $vars, $skip = false) {
        foreach ($vars as $key => $value) {
            if ($skip && $key[0] === '_') continue;
            echo "\n";
            isset(self::$SHARED[$key]) and print('shared ');
            echo "$$key: ";
            //var_dump($value);
            var_export($value); echo ";\n";
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
        return !empty($test->result);//TODO ???
    }

    protected function abort ($message) {
        exit ("\nAborted: $message\n");
    }


    protected $prev_errhandler = 'undefined', $prev_asrhandler = 'undefined';

//    function exphandler ($exception) {
//        $this->errhandler(null, (string)$exception);
//    }

    function asrhandler($file, $line, $code) {
        $msg = $this->errmessage(null, $code, $file, $line);
        print("\n***** Assertion failed: $msg\n");
    }

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




