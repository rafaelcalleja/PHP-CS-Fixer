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

    public function provideExampless(){
        return array(
            array(
                '<?php return $a !== null',
                '<?php return is_null($a) !== true',
            ),
        );
    }

    public function provideExamples()
    {
        return array(
            array(
                '<?php 
return null === b($a);
return $a === null;',
                '<?php 
return is_null(b($a));
return is_null($a) === true;',
            ),
            array(
                '<?php return null === $var; $r[0] = false === $i ? null : $c;',
                '<?php return is_null($var); $r[0] = false === $i ? null : $c;',
            ),
            array(
                '<?php $x = array(1, null === null,3);',
                '<?php $x = array(1, is_null(null),3);',
            ),
            array(
                '<?php return $a[null === $b ? $c[null !== $d] : null !== $e]',
                '<?php return $a[is_null($b) ? $c[false == is_null($d)] : !is_null($e)]',
            ),
            array(
                '<?php return $a[null === $b]',
                '<?php return $a[is_null($b)]',
            ),
            array(
                '<?php return null === $b === null === $a',
                '<?php return is_null($b) === is_null($a)',
            ),
            array(
                '<?php return $a !== null',
                '<?php return is_null($a) !== true',
            ),
            array(
                '<?php return $a !== null',
                '<?php return is_null($a) === false',
            ),
            array(
                '<?php return $a === null',
                '<?php return is_null($a) === true',
            ),
            array(
                '<?php return $a !== null',
                '<?php return is_null($a) == false',
            ),
            array(
                '<?php return $a === null',
                '<?php return is_null($a) == true',
            ),
             array(
                '<?php return null === $a',
                '<?php return true == is_null($a)',
            ),
            array(
                '<?php return null !== $a',
                '<?php return false == is_null($a)',
            ),
            array(
                 '<?php return null !== $a',
                 '<?php return false === is_null($a)',
            ),
            array(
                 '<?php return null === $a',
                 '<?php return true === is_null($a)',
            ),
            array(
                 '<?php return null !== $a',
                 '<?php return true !== is_null($a)', //usado para la negativa
            ),
            array(
                 '<?php return null === $a',
                 '<?php return is_null($a)',
            ),
            array(
                 '<?php return null !== b($a)',
                 '<?php return !is_null(b($a))',
            ),


            /*
              array(
                '<?php return is_null($a) == false',
                '<?php return $a != false',
            ),
            array(
                '<?php return is_null($a) === false',
                '<?php return $a !== false',
            ),
*/
            array(
                '<?php
if (null === $a || null !== $b && null !== $a) {
    return (null !== $b && null === $a) || ($a !== null);
}',
                '<?php
if (is_null($a) || !is_null($b) && false === is_null($a)) {
    return (!is_null($b) && is_null($a)) || (is_null($a) !== true);
}',
                '<?php
$this->b = is_null($c);
$this->d = true === $c;
$this->e = false !== $c;
return false !== is_null($d);
',
                '<?php
$this->b = null === $c;
$this->d = true === $c;
$this->e = false !== $c;
return null === $d;
'
            ),
        );
    }
}
