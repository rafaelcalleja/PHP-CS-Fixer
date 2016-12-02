<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS\Tests\Fixer\Contrib;

use Symfony\CS\Tests\Fixer\AbstractFixerTestBase;

/**
 * @author Bram Gotink <bram@gotink.me>
 */
class ExplicitConditionFixerTest extends AbstractFixerTestBase
{
    /**
     * @dataProvider provideExamples
     */
    public function testFixer($expected, $input = null)
    {
        $this->makeTest($expected, $input);
    }

    public function provideExampless(){
        return array(
            array(
                '<?php if (true === isset($var)) { return; }',
                '<?php if (isset($var)) { return; }',
            ),
        );
    }

    public function provideExamples()
    {
        return array(
            array(
                '<?php if (false === isset($var)) { return; }',
                '<?php if (!isset($var)) { return; }',
            ),
            array(
                '<?php if (true === isset($var)) { return; }',
                '<?php if (isset($var)) { return; }',
            ),
            array(
                '<?php if (true == $b|| true == $a && false == $c ) { return; }',
                '<?php if ($b|| $a && !$c ) { return; }',
            ),
            array(
                '<?php if ( true == $a || ( false == $c && (true == $d || true == $e) || false == $f ) ) { return; }',
                '<?php if ( $a || ( !$c && ($d || $e) || !$f ) ) { return; }',
            ),
            array(
                   '<?php if (  false == $a || true == $b && false == $c ) { return; }',
                   '<?php if (  !$a || $b && !$c ) { return; }',
            ),
            array(
                '<?php if (  true == $a || true == $b && true == $c ) { return; }',
                '<?php if (  $a || $b && $c ) { return; }',
            ),
            array(
                '<?php if (  true == $a || true == $b ) { return; }',
                '<?php if (  $a || $b ) { return; }',
            ),
            array(
                '<?php if (  true == $a  ) { return; }',
                '<?php if (  $a  ) { return; }',
            ),
            array(
                '<?php if (true == $a) { return; }',
                '<?php if ($a) { return; }',
            ),
            array(
                '<?php if (false == $a) { return; }',
                '<?php if (!$a) { return; }',
            ),
            array(
                '<?php 
if (false == $a) else {
}elseif (true == $b) {
}elseif (false === isset($var)) {
}return;',
                '<?php 
if (!$a) else {
}elseif ($b) {
}elseif (!isset($var)) {
}return;',
            )
        );
    }
}
