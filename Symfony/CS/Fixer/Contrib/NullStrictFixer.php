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
        $tokens->clearEmptyTokens();


        if ($this->hasExpectedCall($tokens)){
            $this->fixTokens($tokens);
        }

        return $tokens->generateCode();
    }

    /**
     * @param Tokens $tokens The token list to fix
     */
    private function fixTokens(Tokens &$tokens)
    {
        if ( $this->hasExpectedCall($tokens)){
            if ( false === $this->hasEqualOperator($tokens)){
                return $this->fixTokenSimpleComparsion($tokens);
            }

            $this->fixTokenCompositeComparsion($tokens);
            $this->fixTokenSimpleComparsion($tokens);
        }
    }

    /**
     * @param Tokens $tokens
     * @return mixed
     */
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

    /**
     * @param Tokens $tokens
     */
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

    /**
     * @param $tokens
     * @return int
     */
    private function findMethodIndex(Tokens $tokens){

        $comparisons = $this->getStringType($tokens);
        $lastFixedIndex = count($tokens);

        foreach ($comparisons as $index) {
            if ($index >= $lastFixedIndex) {
                continue;
            }

            if ( $tokens[$index]->getContent() === self::METHOD_STRING || $tokens[$index]->getContent() === "!".self::METHOD_STRING){
                return $index;
            }
        }
        return false;
    }

    /**
     * @param Tokens $tokens
     */
    private function fixTokenSimpleComparsion(Tokens $tokens){
        $comparisons = $this->getStringType($tokens);
        $lastFixedIndex = count($tokens);

        foreach ($comparisons as $index) {

            if ($index >= $lastFixedIndex) {
                continue;
            }

            if ( $tokens[$index]->getContent() === self::METHOD_STRING || $tokens[$index]->getContent() === "!".self::METHOD_STRING ){
                $lastFixedIndex = $this->fixComparison($tokens, $index);
            }
        }
    }

    /**
     * @param Tokens $tokens
     * @return array
     */
    private function getStringType(Tokens $tokens){
        $comparisons = $tokens->findGivenKind(array(T_STRING));
        $comparisons = array_merge(array_keys($comparisons[T_STRING]));
        sort($comparisons);
        return array_reverse($comparisons);
    }

    /**
     * @param Tokens $tokens
     * @return array
     */
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

    /**
     * @param Tokens $tokens
     * @return bool
     */
    private function hasExpectedCall(Tokens $tokens){

        $comparisons = $this->getStringType($tokens);

        $lastFixedIndex = count($tokens);
        foreach ($comparisons as $index) {

            if ($index >= $lastFixedIndex) {
                continue;
            }

            if ( $tokens[$index]->getContent() === self::METHOD_STRING || $tokens[$index]->getContent() === "!".self::METHOD_STRING){
                return true;
            }
        }

        return false;

    }

    /**
     * @param Tokens $tokens
     * @return bool
     */
    private function hasEqualOperator(Tokens $tokens){
        $comparisons = $this->getComparisonTypes($tokens);

        return false === empty($comparisons);
    }

    /**
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
    }

    protected static $counter = 0;

    /**
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison to fix
     *
     * @return int A upper bound for all non-fixed comparisons.
     */
    private function fixCompositeComparison(Tokens &$tokens, $index)
    {
        self::$counter++;
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



        if ( false === $this->hasExpectedCall($left) && false === $this->hasExpectedCall($right) ) {
            return $index;
        }
        
        if ( true === $this->hasExpectedCall($left) && false === $this->hasExpectedCall($right) ) {
            $comparsion = array(T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL);
            $this->switchSides($tokens, $startLeft, $endRight);
            $inversedOrder = true;

            for($i = $startLeft; $i < $endRight; $i++) {
                if($tokens[$i]->isGivenKind($comparsion)){
                    $boolIndex = $index = $i;
                    break;
                }
            }

            $startLeft = $this->findComparisonStart($tokens, $index);
            $endLeft = $tokens->getPrevNonWhitespace($index);
            $startRight = $tokens->getNextNonWhitespace($index);
            $endRight = $this->findComparisonEnd($tokens, $index);
        }

        $operator = $tokens[$boolIndex]; //== !==
        $boolean = $tokens[$startLeft]; // true/false

        if ( $boolean->isNativeConstant() &&  $boolean->isArray() && in_array(strtolower($boolean->getContent()), ['false'], true) ){

            if ($operator->isGivenKind([T_IS_IDENTICAL, T_IS_EQUAL])){

                $startRight =  $tokens->getNextNonWhitespace($boolIndex);

                for ($i = $startLeft; $i < $startRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $toResolve = $this->createPHPTokensEncodingFromCode(
                    "true !== ".
                    $tokens->generatePartialCode($startLeft, $endRight)
                );

                $this->fixTokens($toResolve);


                for ($i = $startRight; $i <= $endRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $tokens->insertAt($index, $toResolve);


            }elseif($operator->isGivenKind([T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL])){

                $startRight =  $tokens->getNextNonWhitespace($boolIndex);

                for ($i = $startLeft; $i < $startRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $toResolve = $this->createPHPTokensEncodingFromCode(
                    "true === ".
                    $tokens->generatePartialCode($startLeft, $endRight)
                );

                $this->fixTokens($toResolve);


                for ($i = $startRight; $i <= $endRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $tokens->insertAt($index, $toResolve);
            }

        }elseif ( $boolean->isNativeConstant() &&  $boolean->isArray() && in_array(strtolower($boolean->getContent()), ['true'], true) ){

            if ($operator->isGivenKind([T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL])){

                if ($tokens[$startRight]->getContent() !== '!'){
                    $tokens->insertAt($startRight, Tokens::fromCode("!"));
                    $startLeft = $this->findComparisonStart($tokens, $index);
                    $endRight = $this->findComparisonEnd($tokens, $index);
                }else{
                    $tokens[$startRight]->clear();
                }
           }

            $toResolve = $this->createPHPTokensEncodingFromCode(
                $tokens->generatePartialCode($startRight, $endRight)
            );

            $this->fixTokens($toResolve);

            for ($i = $startLeft; $i < $startRight; ++$i) {
                $tokens[$i]->clear();
            }

            for ($i = $startRight; $i <= $endRight; ++$i) {
                $tokens[$i]->clear();
            }

            $tokens->insertAt($index, $toResolve);
        }else{
            $left = $tokens->generatePartialCode($startLeft, $endLeft);
            $right = $tokens->generatePartialCode($startRight, $endRight);
            $operator = $tokens->generatePartialCode($endLeft+1, $startRight-1);

            $leftTokens = $this->createPHPTokensEncodingFromCode($left);
            $rightTOkens = $this->createPHPTokensEncodingFromCode($right);
            $operator = $this->createPHPTokensEncodingFromCode($operator);

            foreach([$leftTokens, $rightTOkens] as $fixableToken){
                if (false !== ($startRight = $this->findMethodIndex($fixableToken))){
                    $this->fixComparison($fixableToken, $startRight);
                }
            }

            for ($i = $startLeft; $i <= $endRight; ++$i) {
                $tokens[$i]->clear();
            }

            $newIndex = $startLeft+count($leftTokens);
            $tokens->insertAt($startLeft, $leftTokens);
            $tokens->insertAt($newIndex, $operator);
            $tokens->insertAt($startLeft+count($leftTokens)+count($operator), $rightTOkens);

        }

        if (true === $inversedOrder) {

            $startLeft = $this->findComparisonStart($tokens, $index);
            $endRight = $this->findComparisonEnd($tokens, $index);

            $this->switchSides($tokens, $startLeft, $endRight);
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
        $phpTokens->clearEmptyTokens();
        return $phpTokens;
    }

    /**
     * @param Tokens $tokens
     */
    private function switchSides(Tokens &$tokens, $startLeft, $endRight){

        $initialCode = $tokens->generatePartialCode($startLeft, $endRight);
        $partialTokens = $this->createPHPTokensEncodingFromCode(
            $initialCode
        );

        $index = $this->findComparisonIndex($partialTokens);

        if ($index <= 0){
            return;
        }

        $sl = $this->findComparisonStart($partialTokens, $index);
        $el = $partialTokens->getPrevNonWhitespace($index);
        $sr = $partialTokens->getNextNonWhitespace($index);
        $er = $this->findComparisonEnd($partialTokens, $index);
        $operator = $this->createPHPTokensEncodingFromCode($partialTokens->generatePartialCode($el+1, $sr-1));



        $left = $partialTokens->generatePartialCode($sl, $el);
        $left = $this->createPHPTokensEncodingFromCode($left);

        $right = $partialTokens->generatePartialCode($sr, $er);
        $right = $this->createPHPTokensEncodingFromCode($right);

        for ($i = $startLeft; $i <= $endRight; ++$i) {
            $tokens[$i]->clear();
        }

        $newIndex = $startLeft+count($right);
        $tokens->insertAt($startLeft, $right);//, $operator, $left]);
        $tokens->insertAt($newIndex, $operator);
        $tokens->insertAt($startLeft+count($right)+count($operator), $left);

        return $newIndex;
    }

    /**
     * @param Tokens $tokens
     * @param $index
     * @return string
     */
    private function getReturnComparisonString(Tokens $tokens, $index){
        --$index;

        if (false === empty($tokens[$index]) && "!" === $tokens[$index]->getContent() ){
            $tokens[$index]->clear();
            return '!==';
        }


        return '===';

    }

    /**
     * @param Tokens $tokens
     * @param $index
     * @return array
     */
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
     *
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison
     *
     * @return int The first index of the left-hand side of the comparison
     */
    private function findComparisonStart(Tokens $tokens, $index)
    {
        while (0 <= $index) {

            $token = $tokens[$index];

            if ($this->isTokenOfLowerPrecedence($token)) {
                break;//break on return
            }

            $block = $tokens->detectBlockType($token);
            if (null === $block) {
                --$index;
                continue;
            }

            if ($block['isStart']) {

                break;
            }

            $index = $tokens->findBlockEnd($block['type'], $index, false) - 1;
        }

        return $tokens->getNextNonWhitespace($index);
    }

    /**
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
        return 'All is_null($expression) are replaced using null === $expression.';
    }
}
