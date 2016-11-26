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
class NullStrictFixerTest extends AbstractFixerTestBase
{
    /**
     * @dataProvider provideExamples
     */
    public function testFixer($expected, $input = null)
    {
        $this->makeTest($expected, $input);
    }

    public function provideExamples()
    {
        return array(
            array(
                '<?php return null === $a',
                '<?php return is_null($a)',
            ),
            array(
                '<?php return null !== b($a)',
                '<?php return !is_null(b($a))',
            ),
            /*array(
                '<?php return null !== $a',
                '<?php return false === is_null($a)',
            ),*&/
            /* array(
                '<?php return null != $a',
                '<?php return false == is_null($a)',
            ),
            array(
                '<?php return null == $a',
                '<?php return true == is_null($a)',
            ),
            array(
                '<?php return null == $a',
                '<?php return true == is_null($a)',
            ),
            array(
                '<?php return is_null($a) == false',
                '<?php return $a != false',
            ),
            array(
                '<?php return is_null($a) === false',
                '<?php return $a !== false',
            ),*/
            array(
                '<?php
if (null === $a || null !== $b) {
    return (null !== $b && null === $a);
}',
                '<?php
if (is_null($a) || !is_null($b)) {
    return (!is_null($b) && is_null($a));
}',
            ),
        );
    }
}