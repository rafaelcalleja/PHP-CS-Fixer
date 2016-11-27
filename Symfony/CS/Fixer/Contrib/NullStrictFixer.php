<?php
/*
 * This file is part of the Symfony CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace Symfony\CS\Fixer\Contrib;
use Symfony\CS\AbstractFixer;
use Symfony\CS\Tokenizer\Token;
use Symfony\CS\Tokenizer\Tokens;
/**
 * @author Bram Gotink <bram@gotink.me>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
class NullStrictFixer extends AbstractFixer
{
    const METHOD_STRING = 'is_null';

    /**
     * {@inheritdoc}
     */
    public function fix(\SplFileInfo $file, $content)
    {
        $tokens = Tokens::fromCode($content);
        $start = $tokens->generateCode();

        if ($this->hasExpectedCall($tokens)){
            $this->fixTokens($tokens);
        }

        if ($start !== $tokens->generateCode() ){
            var_dump('EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE\r\n
            EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE\r\n
            EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE\r\n
            ');
        }
        //die(var_dump('GENERATED ' . $tokens->generateCode()));
        return $tokens->generateCode();
    }
    /**
     * Fixes the comparisons in the given tokens.
     *
     * @param Tokens $tokens The token list to fix
     */
    private function fixTokens(Tokens &$tokens)
    {
        if ( $this->findMethodIndex($tokens)){
            if ( false === $this->hasEqualOperator($tokens)){
                return $this->fixTokenSimpleComparsion($tokens);
            }

            $this->fixTokenCompositeComparsion($tokens);
            $this->fixTokenSimpleComparsion($tokens);
        }
    }

    private function findComparisonIndex(Tokens $tokens){
        $comparisons = $tokens->findGivenKind(array(T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL));
        $comparisons = array_merge(
            array_keys($comparisons[T_IS_EQUAL]),
            array_keys($comparisons[T_IS_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_EQUAL])
        );
        sort($comparisons);
        return current(array_reverse($comparisons));
    }
    private function fixTokenCompositeComparsion(Tokens &$tokens){

        $comparisons = $tokens->findGivenKind(array(T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL));
        $comparisons = array_merge(
            array_keys($comparisons[T_IS_EQUAL]),
            array_keys($comparisons[T_IS_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_EQUAL])
        );
        sort($comparisons);
        $lastFixedIndex = count($tokens);
        foreach (array_reverse($comparisons) as $index) {
            if ($index >= $lastFixedIndex) {
                continue;
            }

            $lastFixedIndex = $this->fixCompositeComparison($tokens, $index);
        }


    }

    private function findMethodIndex($tokens){
        $comparisons = $this->getStringType($tokens);
        $lastFixedIndex = count($tokens);

        foreach ($comparisons as $index) {
            if ($index >= $lastFixedIndex) {
                continue;
            }

            if ( $tokens[$index]->getContent() === self::METHOD_STRING ){
                return $index;
            }
        }
        return 0;
    }

    private function fixTokenSimpleComparsion(Tokens $tokens){
        $comparisons = $this->getStringType($tokens);
        $lastFixedIndex = count($tokens);

        foreach ($comparisons as $index) {

            if ($index >= $lastFixedIndex) {
                continue;
            }

            if ( $tokens[$index]->getContent() === self::METHOD_STRING ){
                $lastFixedIndex = $this->fixComparison($tokens, $index);
            }
        }
    }

    private function getStringType(Tokens $tokens){
        $comparisons = $tokens->findGivenKind(array(T_STRING));
        $comparisons = array_merge(array_keys($comparisons[T_STRING]));
        sort($comparisons);
        return array_reverse($comparisons);
    }

    private function getComparisonTypes(Tokens $tokens){
        $comparisons = $tokens->findGivenKind(array(T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL));
        $comparisons = array_merge(
            array_keys($comparisons[T_IS_EQUAL]),
            array_keys($comparisons[T_IS_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_EQUAL])
        );
        sort($comparisons);
        return array_reverse($comparisons);
    }

    private function hasExpectedCall(Tokens $tokens){

        $comparisons = $this->getStringType($tokens);

        $lastFixedIndex = count($tokens);
        foreach ($comparisons as $index) {

            if ($index >= $lastFixedIndex) {
                continue;
            }

            if ( $tokens[$index]->getContent() === self::METHOD_STRING ){
                return true;
            }
        }

        return false;

    }

    private function hasEqualOperator(Tokens $tokens){
        $comparisons = $this->getComparisonTypes($tokens);

        return false === empty($comparisons);
    }

    private function applyFix(Tokens $tokens, $index){

        list($starNullContent, $endNullContent) = $this->getBlockContent($tokens, $index);
        $endRight = $this->findComparisonEnd($tokens, $index);

        $comparisonType = $this->getReturnComparisonString($tokens, $index);

        $left = $tokens->generatePartialCode($starNullContent, $endNullContent);
        $left = Tokens::fromCode("<?php null $comparisonType $left");
        $left[0]->clear();

        $this->fixTokens($left);
        for ($i = $index; $i <= $endRight; ++$i) {
            $tokens[$i]->clear();
        }

        $tokens->insertAt($index, $left);
        return $tokens;
    }
    /**
     * Fixes the comparison at the given index.
     *
     * A comparison is considered fixed when
     * - both sides are a variable (e.g. $a === $b)
     * - neither side is a variable (e.g. self::CONST === 3)
     * - only the right-hand side is a variable (e.g. 3 === self::$var)
     *
     * If the left-hand side and right-hand side of the given comparison are
     * swapped, this function runs recursively on the previous left-hand-side.
     *
     * <?php return null === $a',
     * <?php return is_null($a)',
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison to fix
     *
     * @return int A upper bound for all non-fixed comparisons.
     */
    private function fixComparison(Tokens $tokens, $index)
    {
        list($starNullContent, $endNullContent) = $this->getBlockContent($tokens, $index);

        $endRight = $this->findComparisonEnd($tokens, $index);

        $comparisonType = $this->getReturnComparisonString($tokens, $index);

        $left = $tokens->generatePartialCode($starNullContent, $endNullContent);
        $left = Tokens::fromCode("<?php null $comparisonType $left");
        $left[0]->clear();

        $this->fixTokens($left);
        for ($i = $index; $i <= $endRight; ++$i) {
            $tokens[$i]->clear();
        }

        $tokens->insertAt($index, $left);
        return $index;

        die(var_dump($tokens[$index]->getContent()));
        var_dump($this->getBlockContent($tokens, $index). ' CCC');
        $startLeft = $this->findComparisonStart($tokens, $index);
        $endLeft = $tokens->getPrevNonWhitespace($index);
        var_dump("\r\n\r\n\r\n  $startLeft, $endLeft");
        if (false == $this->hasIsNullMethod($tokens, $startLeft, $endLeft)) {
            return $index;
        }

        $left = $tokens->generatePartialCode($startLeft, $endLeft);
        $left = Tokens::fromCode('<?php '.$left);
        $left[0]->clear();


        $this->fixTokens($left);
        for ($i = $startLeft; $i <= $endLeft; ++$i) {
            $tokens[$i]->clear();
        }

        $tokens->insertAt($startLeft, $left);
        return $startLeft;
    }

    /**
     * Fixes the comparison at the given index.
     *
     * A comparison is considered fixed when
     * - both sides are a variable (e.g. $a === $b)
     * - neither side is a variable (e.g. self::CONST === 3)
     * - only the right-hand side is a variable (e.g. 3 === self::$var)
     *
     * If the left-hand side and right-hand side of the given comparison are
     * swapped, this function runs recursively on the previous left-hand-side.
     *
     * <?php return null === $a',
     * <?php return is_null($a)',
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison to fix
     *
     * @return int A upper bound for all non-fixed comparisons.
     */
    private function fixCompositeComparison(Tokens &$tokens, $index)
    {
        $startLeft = $this->findComparisonStart($tokens, $index);
        $endLeft = $tokens->getPrevNonWhitespace($index);
        $startRight = $tokens->getNextNonWhitespace($index);
        $endRight = $this->findComparisonEnd($tokens, $index);

        $left = $tokens->generatePartialCode($startLeft, $endLeft);
        $left = Tokens::fromCode('<?php '.$left);

        $right = $tokens->generatePartialCode($startRight, $endRight);
        $right = Tokens::fromCode('<?php '.$right);

        $boolIndex = $index;
        $inversedOrder = false;
        if ( true === $this->hasExpectedCall($left) && true === $this->hasExpectedCall($right) ) {

        }
        if ( true === $this->hasExpectedCall($left) && false === $this->hasExpectedCall($right) ) {
            $this->switchSides($tokens);
            $inversedOrder = true;
            $boolIndex =  $this->findComparisonIndex($tokens);

            $startLeft = $this->findComparisonStart($tokens, $boolIndex);
            $startRight = $tokens->getNextNonWhitespace($boolIndex);
        }

        $operator = $tokens[$boolIndex]; //== !==
        $boolean = $tokens[$startLeft]; // true/false

        if ( $boolean->isNativeConstant() &&  $boolean->isArray() && in_array(strtolower($boolean->getContent()), ['false'], true) ){
            if ($operator->isGivenKind([T_IS_IDENTICAL, T_IS_EQUAL])){

                if ($startRight = $this->findMethodIndex($tokens)){
                    $endRight =  $tokens->getNextNonWhitespace($boolIndex);

                    for ($i = $startLeft; $i < $endRight; ++$i) {
                        $tokens[$i]->clear();
                    }

                    $tokens->insertAt($startLeft, Tokens::fromCode("true !== "));

                    $tokens = $tokens->generatePartialCode(0,  count($tokens) - 1);
                    $tokens = Tokens::fromCode($tokens);

                    $this->fixCompositeComparison($tokens, $startLeft+2);

                }

            }

        }elseif ( $boolean->isNativeConstant() &&  $boolean->isArray() && in_array(strtolower($boolean->getContent()), ['true'], true) ){

            if ($operator->isGivenKind([T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL])){
                    $tokens->insertAt($startRight, Tokens::fromCode("!"));

                    $tokens = $tokens->generatePartialCode(0, count($tokens) - 1);
                    $tokens = Tokens::fromCode($tokens);
            }


            for ($i = $startLeft; $i < $startRight; ++$i) {
                $tokens[$i]->clear();
            }

            $tokens = $tokens->generatePartialCode(0, count($tokens) - 1);
            $tokens = Tokens::fromCode($tokens);

            if ($startRight = $this->findMethodIndex($tokens)){
                $this->fixComparison($tokens, $startRight);

            }
        }else{

            $prefix = $tokens->generatePartialCode(0, $startLeft-1);
            $left = $tokens->generatePartialCode($startLeft, $endLeft);
            $right = $tokens->generatePartialCode($startRight, $endRight);
            $operator = $tokens->generatePartialCode($endLeft+1, $startRight-1);
            $sufix = $tokens->generatePartialCode($endRight+1, count($tokens)-1);

            $leftTokens = $this->createPHPTokensEncodingFromCode($left);
            $rightTOkens = $this->createPHPTokensEncodingFromCode($right);

            foreach([$leftTokens, $rightTOkens] as $fixableToken){
                if ($startRight = $this->findMethodIndex($fixableToken)){
                    $this->fixComparison($fixableToken, $startRight);
                }
            }

            $left = $leftTokens->generateCode();
            $right = $rightTOkens->generateCode();

            $tokens = Tokens::fromCode("$prefix$left$operator$right$sufix");

        }

        if (true === $inversedOrder) {
            $this->switchSides($tokens);
           // var_dump($tokens->generateCode());

        }


        return $index;
    }

    /**
     * @param $code
     * @return Tokens
     */
    private function createPHPTokensEncodingFromCode($code){
        $phpTokens = Tokens::fromCode("<?php $code");
        $phpTokens[0]->clear();
        return $phpTokens;
    }

    private function switchSides(Tokens &$tokens){
        $index =  $this->findComparisonIndex($tokens);

        $startLeft = $this->findComparisonStart($tokens, $index);
        $endLeft = $tokens->getPrevNonWhitespace($index);
        $startRight = $tokens->getNextNonWhitespace($index);
        $endRight = $this->findComparisonEnd($tokens, $index);

        if (null === $startRight){
            return;
        }

        $prefix = $tokens->generatePartialCode(0, $startLeft-1);
        $left = $tokens->generatePartialCode($startLeft, $endLeft);
        $right = $tokens->generatePartialCode($startRight, $endRight);
        $operator = $tokens->generatePartialCode($endLeft+1, $startRight-1);
        $sufix = $tokens->generatePartialCode($endRight+1, count($tokens)-1);


        if (false === empty($sufix)){
            $tokens = Tokens::fromCode("$prefix$right$operator$left$sufix");
        }else{
            $tokens = Tokens::fromCode("$prefix$right$operator$left");
        }

    }

    private function getReturnComparisonString(Tokens $tokens, &$index){
        --$index;

        if ( "!" === $tokens[$index]->getContent() ){
            $tokens[$index]->clear();
            return '!==';
        }

        ++$index;
        return '===';

    }

    private function getBlockContent(Tokens $tokens, $index){

        $endIndex = $index;
        while ($endIndex <= count($tokens) -1 ){
            if ($tokens[$endIndex]->equals('(')){
                $endIndex;
                break;
            }
            $endIndex++;
        }

        $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $endIndex);

        // index = is_null
        $index +=2;// index = $a

        $startNullContent = $index;
        return [$startNullContent, --$end];
    }

    /**
     * Finds the start of the left-hand side of the comparison at the given
     * index.
     *
     * The left-hand side ends when an operator with a lower precedence is
     * encountered or when the block level for `()`, `{}` or `[]` goes below
     * zero.
     *
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison
     *
     * @return int The first index of the left-hand side of the comparison
     */
    private function findComparisonStart(Tokens $tokens, $index)
    {
        //var_dump(__FUNCTION__. "\r\n\r\n" . $index);
        while (0 <= $index) {

            $token = $tokens[$index];

            if ($this->isTokenOfLowerPrecedence($token)) {
                break;//break on return
            }

            $block = $tokens->detectBlockType($token);
            if (null === $block) {
                //var_dump(__FUNCTION__. "\r\n\r\n" . $token->getContent());
                --$index;
                continue;
            }

            if ($block['isStart']) {

                break;
            }

            $index = $tokens->findBlockEnd($block['type'], $index, false) - 1;
        }
        //var_dump(__FUNCTION__. "\r\n\r\n" . $tokens->getNextNonWhitespace($index));

        return $tokens->getNextNonWhitespace($index);
    }
    /**
     * Finds the end of the right-hand side of the comparison at the given
     * index.
     *
     * The right-hand side ends when an operator with a lower precedence is
     * encountered or when the block level for `()`, `{}` or `[]` goes below
     * zero.
     *
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison
     *
     * @return int The last index of the right-hand side of the comparison
     */
    private function findComparisonEnd(Tokens $tokens, $index)
    {
        $count = count($tokens);
        while ($index < $count) {
            $token = $tokens[$index];
            if ($this->isTokenOfLowerPrecedence($token)) {
                break;
            }
            $block = $tokens->detectBlockType($token);
            if (null === $block) {
                ++$index;
                continue;
            }
            if (!$block['isStart']) {
                break;
            }
            $index = $tokens->findBlockEnd($block['type'], $index) + 1;
        }
        return $tokens->getPrevNonWhitespace($index);
    }
    /**
     * Checks whether the given token has a lower precedence than `T_IS_EQUAL`
     * or `T_IS_IDENTICAL`.
     *
     * @param Token $token The token to check
     *
     * @return bool Whether the token has a lower precedence
     */
    private function isTokenOfLowerPrecedence(Token $token)
    {
        static $tokens;
        if (null === $tokens) {
            $tokens = array(
                // '&&', '||',
                T_BOOLEAN_AND, T_BOOLEAN_OR,
                // '.=', '/=', '-=', '%=', '*=', '+=',
                T_CONCAT_EQUAL, T_DIV_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_PLUS_EQUAL,
                // '&=', '|=', '^=',
                T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL,
                // '<<=', '>>=', '=>',
                T_SL_EQUAL, T_SR_EQUAL, T_DOUBLE_ARROW,
                // 'and', 'or', 'xor',
                T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
                // keywords like 'return'
                T_RETURN, T_THROW, T_GOTO, T_CASE,
            );
            // PHP 5.6 introduced **=
            if (defined('T_POW_EQUAL')) {
                $tokens[] = constant('T_POW_EQUAL');
            }
        }
        static $otherTokens = array(
            // bitwise and, or, xor
            '&', '|', '^',
            // ternary operators
            '?', ':',
            // assignment
            '=',
            // end of PHP statement
            ',', ';',
        );
        return $token->isGivenKind($tokens) || $token->equalsAny($otherTokens);
    }
    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return 'Comparisons should be done using Yoda conditions.';
    }
}
