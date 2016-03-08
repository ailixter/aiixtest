<?php

$a[1][2][] = 'ok';

echo $_hidden;

return AIIXTest::assertion($a[1][2][0], !$a[1][2][1]);