#  aiixtest #

## php test snippets runner

Yes, "snippets" means snippets. *Any valid php file* could be run, its $vars
are printed and its return remembered and analysed.

For example:
```
o==============================================================================o
|   test/argv.php                                                              |
o==============================================================================o
   1| <?php
   2|
   3|
   4| global $argv;
   5| reset($argv);
   6| while (($arg = next($argv)) !== false) {
   7|     $array[] = $arg;
   8| }
   9|
  10| return "new return";
--------------------------------------------------------------------------------

--------------------------------------------------------------------------------
RETURNED: string(10) "new return"
EXPECTED: int(1)
--------------------------------------------------------------------------------
Vars after:

shared $array: array (
  0 => 'php',
);

$argv: array (
  0 => 'C:\\Work\\php\\aiixtest\\aiixtest.php',
  1 => 'php',
);

$arg: false;
```

All what it needs is a test directory, which structure follows:
```
my-tests
    |
    \-init
    |   |
    |   \-(initialization files)*
    |
    \-test
        |
        \-(test files)+
```
Then a testing could be started with:
```
$ cd my-project
$ php aiixtest.php my-tests
```
See even more [at the wiki](https://github.com/ailixter/aiixtest/wiki)

## Installation ##

```
$ git clone https://github.com/ailixter/aiixtest.git
```

The [archive  could just be downloaded](https://github.com/ailixter/aiixtest/archive/master.zip) and unarchived.
