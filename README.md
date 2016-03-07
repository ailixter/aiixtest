#  aiixtest

## php test snippets runner

Yes, "snippets" means snippets. Any valid php file could be run, its $vars
are printed and its return is remembered and analysed.

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
